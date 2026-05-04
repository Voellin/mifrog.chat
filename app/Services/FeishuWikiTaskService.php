<?php

namespace App\Services;

use App\Models\Run;
use Throwable;

class FeishuWikiTaskService extends AbstractFeishuTaskService
{
    public function __construct(
        FeishuService $feishuService,
        FeishuCliClient $feishuCliClient,
    ) {
        parent::__construct($feishuService, $feishuCliClient);
    }

    /**
     * @param  array<string,mixed>  $params
     * @return array<string,mixed>
     */
    public function execute(Run $run, array $params): array
    {
        if (($params['_extraction_failed'] ?? false) === true) {
            return [
                'status' => 'clarify',
                'message' => 'Please tell me whether you want to list wiki spaces, list nodes, or create a wiki node.',
            ];
        }

        if (($params['needs_clarification'] ?? false) === true) {
            return [
                'status' => 'clarify',
                'message' => trim((string) ($params['clarification_message'] ?? '')) ?: 'Please tell me which wiki space or node you want me to use.',
            ];
        }

        if (! $this->feishuCliClient->isEnabled() || ! $this->feishuCliClient->isAvailable()) {
            return [
                'status' => 'failed',
                'message' => 'Feishu CLI is not available, so wiki operations cannot run right now.',
            ];
        }

        $config = $this->feishuService->readConfig();
        $action = strtolower(trim((string) ($params['action'] ?? 'list_spaces')));
        $command = $this->buildCommand($action, $params);
        if ($command === null) {
            return [
                'status' => 'clarify',
                'message' => 'Please tell me which wiki space or node you want me to use.',
            ];
        }

        try {
            $result = $this->feishuCliClient->runSkillCommand(
                $config,
                '',
                $command,
                'user',
                trim((string) ($run->user?->feishu_open_id ?? ''))
            );
        } catch (Throwable $e) {
            return $this->blockedFromThrowable($e, 'Feishu authorization is required before I can access wiki content.');
        }

        $code = (int) ($result['code'] ?? 0);
        if ($code !== 0) {
            return [
                'status' => 'failed',
                'message' => 'Wiki operation failed: ' . trim((string) ($result['msg'] ?? 'wiki_operation_failed')),
                'error' => $result,
            ];
        }

        return $this->buildSuccessResponse($action, $result);
    }

    /**
     * @param  array<string,mixed>  $params
     * @return array<int,string>|null
     */
    private function buildCommand(string $action, array $params): ?array
    {
        return match ($action) {
            'list_nodes' => $this->buildListNodesCommand($params),
            'create_node' => $this->buildCreateNodeCommand($params),
            'resolve_url' => $this->buildResolveUrlCommand($params),
            default => ['wiki', 'spaces', 'list', '--params', '{"page_size":10}'],
        };
    }

    /**
     * Build a `wiki spaces get_node` command from either a raw wiki URL or a
     * pre-extracted node_token. Returns null if neither is usable.
     *
     * @param  array<string,mixed>  $params
     * @return array<int,string>|null
     */
    private function buildResolveUrlCommand(array $params): ?array
    {
        $token = trim((string) ($params['node_token'] ?? ''));

        if ($token === '') {
            $url = trim((string) ($params['url'] ?? ''));
            if ($url !== '' && preg_match('#/wiki/([A-Za-z0-9_-]+)#i', $url, $m) === 1) {
                $token = trim((string) ($m[1] ?? ''));
            }
        }

        if ($token === '') {
            return null;
        }

        $query = ['token' => $token];
        $objType = trim((string) ($params['obj_type'] ?? ''));
        if ($objType !== '') {
            $query['obj_type'] = $objType;
        }

        $payload = json_encode($query, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (! is_string($payload)) {
            return null;
        }

        return ['wiki', 'spaces', 'get_node', '--params', $payload];
    }

    /**
     * @param  array<string,mixed>  $params
     * @return array<int,string>|null
     */
    private function buildListNodesCommand(array $params): ?array
    {
        $spaceId = trim((string) ($params['space_id'] ?? ''));
        if ($spaceId === '') {
            return null;
        }

        $query = ['space_id' => $spaceId, 'page_size' => 10];
        $parentNodeToken = trim((string) ($params['parent_node_token'] ?? ''));
        if ($parentNodeToken !== '') {
            $query['parent_node_token'] = $parentNodeToken;
        }

        $payload = json_encode($query, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (! is_string($payload)) {
            return null;
        }

        return ['wiki', 'nodes', 'list', '--params', $payload];
    }

    /**
     * @param  array<string,mixed>  $params
     * @return array<int,string>|null
     */
    private function buildCreateNodeCommand(array $params): ?array
    {
        $spaceId = trim((string) ($params['space_id'] ?? ''));
        $title = trim((string) ($params['title'] ?? ''));
        if ($spaceId === '' || $title === '') {
            return null;
        }

        $body = [
            'node_type' => 'origin',
            'obj_type' => trim((string) ($params['obj_type'] ?? 'docx')) ?: 'docx',
            'title' => $title,
        ];
        $parentNodeToken = trim((string) ($params['parent_node_token'] ?? ''));
        if ($parentNodeToken !== '') {
            $body['parent_node_token'] = $parentNodeToken;
        }

        $query = json_encode(['space_id' => $spaceId], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $payload = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (! is_string($query) || ! is_string($payload)) {
            return null;
        }

        return ['wiki', 'nodes', 'create', '--params', $query, '--data', $payload];
    }

    /**
     * @param  array<string,mixed>  $result
     * @return array<string,mixed>
     */
    private function buildSuccessResponse(string $action, array $result): array
    {
        $data = (array) ($result['data'] ?? $result);

        return match ($action) {
            'list_nodes' => $this->buildListNodesResponse($data),
            'create_node' => $this->buildCreateNodeResponse($data),
            'resolve_url' => $this->buildResolveUrlResponse($data),
            default => $this->buildListSpacesResponse($data),
        };
    }

    /**
     * Project the get_node response down to the fields the LLM needs to dispatch
     * to the next tool: obj_type drives the routing (docx → docs_read, sheet →
     * sheets_read, bitable → base_manage, file/slides/mindnote → drive_manage),
     * obj_token is the real cloud-doc token to feed that next tool.
     *
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    private function buildResolveUrlResponse(array $data): array
    {
        $node = (array) ($data['node'] ?? $data);

        $objType = trim((string) ($node['obj_type'] ?? ''));
        $objToken = trim((string) ($node['obj_token'] ?? ''));
        $nodeToken = trim((string) ($node['node_token'] ?? ''));
        $title = trim((string) ($node['title'] ?? ''));

        if ($objType === '' || $objToken === '') {
            return [
                'status' => 'failed',
                'message' => 'Wiki node lookup returned no obj_type/obj_token; the link may be invalid or inaccessible.',
                'raw_data' => $data,
            ];
        }

        $hint = match ($objType) {
            'docx', 'doc' => 'Next: call docs_read with doc_token=' . $objToken,
            'sheet' => 'Next: call sheets_read with spreadsheet_token=' . $objToken,
            'bitable' => 'Next: call base_manage with app_token=' . $objToken,
            'file', 'slides' => 'Next: call drive_manage with file_token=' . $objToken,
            'mindnote' => 'Next: call drive_manage with file_token=' . $objToken . ' (mindnote)',
            default => 'Next: pick the appropriate read tool based on obj_type=' . $objType,
        };

        $message = 'Wiki link resolved' . ($title !== '' ? ' (' . $title . ')' : '')
            . ': obj_type=' . $objType . ', obj_token=' . $objToken . '. ' . $hint;

        return [
            'status' => 'success',
            'message' => $message,
            'model' => 'feishu-wiki-resolve-url',
            'raw_data' => [
                'obj_type' => $objType,
                'obj_token' => $objToken,
                'node_token' => $nodeToken,
                'title' => $title,
                'node' => $node,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    private function buildListSpacesResponse(array $data): array
    {
        $items = array_values(array_filter((array) ($data['items'] ?? []), 'is_array'));
        if ($items === []) {
            return [
                'status' => 'success',
                'message' => 'I did not find any wiki spaces.',
                'model' => 'feishu-wiki-list-spaces',
                'raw_data' => $data,
            ];
        }

        $lines = ['Wiki spaces:'];
        foreach (array_slice($items, 0, 10) as $index => $item) {
            $name = trim((string) ($item['name'] ?? 'Untitled space'));
            $spaceId = trim((string) ($item['space_id'] ?? ''));
            $lines[] = ($index + 1) . '. ' . $name . ($spaceId !== '' ? ' (space_id=' . $spaceId . ')' : '');
        }

        return [
            'status' => 'success',
            'message' => implode("\n", $lines),
            'model' => 'feishu-wiki-list-spaces',
            'raw_data' => $data,
        ];
    }

    /**
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    private function buildListNodesResponse(array $data): array
    {
        $items = array_values(array_filter((array) ($data['items'] ?? []), 'is_array'));
        if ($items === []) {
            return [
                'status' => 'success',
                'message' => 'I did not find any wiki nodes in that location.',
                'model' => 'feishu-wiki-list-nodes',
                'raw_data' => $data,
            ];
        }

        $lines = ['Wiki nodes:'];
        foreach (array_slice($items, 0, 10) as $index => $item) {
            $title = trim((string) ($item['title'] ?? 'Untitled node'));
            $nodeToken = trim((string) ($item['node_token'] ?? ''));
            $objType = trim((string) ($item['obj_type'] ?? ''));
            $parts = [$title];
            if ($objType !== '') {
                $parts[] = 'type=' . $objType;
            }
            if ($nodeToken !== '') {
                $parts[] = 'node_token=' . $nodeToken;
            }
            $lines[] = ($index + 1) . '. ' . implode(', ', $parts);
        }

        return [
            'status' => 'success',
            'message' => implode("\n", $lines),
            'model' => 'feishu-wiki-list-nodes',
            'raw_data' => $data,
        ];
    }

    /**
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    private function buildCreateNodeResponse(array $data): array
    {
        $node = (array) ($data['node'] ?? $data);
        $title = trim((string) ($node['title'] ?? 'Untitled wiki node'));
        $nodeToken = trim((string) ($node['node_token'] ?? ''));
        $message = 'Wiki node created: ' . $title;
        if ($nodeToken !== '') {
            $message .= '. node_token=' . $nodeToken;
        }

        return [
            'status' => 'success',
            'message' => $message,
            'model' => 'feishu-wiki-create-node',
            'raw_data' => $data,
        ];
    }


}
