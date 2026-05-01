<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\ModelKey;
use App\Models\ModelProvider;
use App\Models\Setting;
use App\Services\LlmGateway\VendorKeyInferrer;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * R3d (丁方案 + C2 扩 schema):
 *   - getModel: 返回 {providers: [...], active_main_model_id, active_vision_model_id}
 *   - setModel: 接受新结构；同时为了平滑过渡保留对旧 {base_url, api_key, ...} 的兼容
 *
 * 这是破坏性变更（按 Lin C2 选项），外部 caller 需要迁移到新 schema。
 * 旧 schema 在过渡期内仍能写入，但 output 已经是新结构。
 */
class ConfigController extends Controller
{
    private const CAPABILITIES = ['text', 'vision', 'speech', 'embedding', 'rerank', 'audio', 'other'];

    public function getFeishu()
    {
        return response()->json(Setting::read('feishu', []));
    }

    public function setFeishu(Request $request)
    {
        $data = $request->validate([
            'app_id' => 'required|string',
            'app_secret' => 'required|string',
            'encrypt_key' => 'nullable|string',
            'verification_token' => 'nullable|string',
        ]);
        Setting::write('feishu', $data);

        return response()->json(['ok' => true]);
    }

    /**
     * R3d C2: 返回新 schema（providers 数组 + 两个全局生效模型 ID）
     * 老 caller 期望的 model_gateway 形状已废弃；如果有外部脚本依赖请迁移到 providers[]
     */
    public function getModel()
    {
        $providers = ModelProvider::query()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(function (ModelProvider $p) {
                $key = ModelKey::query()
                    ->where('provider_id', $p->id)
                    ->where('name', 'default')
                    ->where('is_active', true)
                    ->first();
                return [
                    'id' => (int) $p->id,
                    'vendor_key' => (string) $p->vendor_key,
                    'name' => (string) $p->name,
                    'base_url' => (string) $p->base_url,
                    'is_active' => (bool) $p->is_active,
                    'sort_order' => (int) $p->sort_order,
                    'api_key_configured' => $key !== null && trim((string) $key->api_key) !== '',
                    'models' => is_array($p->models) ? $p->models : [],
                ];
            })
            ->all();

        return response()->json([
            'providers' => $providers,
            'active_main_model_id' => (string) Setting::read('active_main_model_id', ''),
            'active_vision_model_id' => (string) Setting::read('active_vision_model_id', ''),
        ]);
    }

    /**
     * R3d C2: 接受新 schema（providers + active_*_model_id）。
     * 同时为过渡期保留老 schema {base_url, api_key, ...} 的写入兼容（自动反推 vendor_key）。
     */
    public function setModel(Request $request)
    {
        $hasNewSchema = $request->has('providers') || $request->has('active_main_model_id');
        if ($hasNewSchema) {
            return $this->setModelNewSchema($request);
        }
        return $this->setModelLegacySchema($request);
    }

    private function setModelNewSchema(Request $request)
    {
        $data = $request->validate([
            'providers' => 'nullable|array',
            'providers.*.vendor_key' => 'required_with:providers|string|max:32',
            'providers.*.base_url' => 'required_with:providers|string|max:1024',
            'providers.*.api_key' => 'nullable|string|max:4096',
            'providers.*.name' => 'nullable|string|max:191',
            'providers.*.is_active' => 'nullable|boolean',
            'providers.*.sort_order' => 'nullable|integer|min:0|max:99999',
            'providers.*.models' => 'nullable|array',
            'active_main_model_id' => 'nullable|string|max:255',
            'active_vision_model_id' => 'nullable|string|max:255',
        ]);

        DB::transaction(function () use ($data): void {
            foreach ((array) ($data['providers'] ?? []) as $row) {
                $vendorKey = trim((string) ($row['vendor_key'] ?? ''));
                if ($vendorKey === '') {
                    continue;
                }
                $baseUrl = trim((string) ($row['base_url'] ?? ''));
                $models = $this->normalizeModels((array) ($row['models'] ?? []));
                $apiKey = trim((string) ($row['api_key'] ?? ''));
                $isActive = (bool) ($row['is_active'] ?? true);
                $sortOrder = (int) ($row['sort_order'] ?? 100);
                $name = trim((string) ($row['name'] ?? '')) ?: ('provider-' . $vendorKey);

                // 计算 defaults：text/vision 各取 models 第一个
                $textDefault = '';
                $visionDefault = '';
                foreach ($models as $m) {
                    $caps = isset($m['capabilities']) && is_array($m['capabilities']) ? $m['capabilities'] : [$m['capability'] ?? ''];
                    $caps = array_map(fn ($c) => strtolower(trim((string) $c)), $caps);
                    if ($textDefault === '' && in_array('text', $caps, true)) {
                        $textDefault = (string) ($m['model_id'] ?? '');
                    }
                    if ($visionDefault === '' && in_array('vision', $caps, true)) {
                        $visionDefault = (string) ($m['model_id'] ?? '');
                    }
                }

                $existing = ModelProvider::query()->where('vendor_key', $vendorKey)->first();
                if ($existing) {
                    $existing->update([
                        'name' => $name,
                        'base_url' => $baseUrl,
                        'default_model' => $textDefault ?: $existing->default_model,
                        'models' => $models,
                        'defaults' => ['text' => $textDefault, 'vision' => $visionDefault],
                        'is_active' => $isActive,
                        'sort_order' => $sortOrder,
                    ]);
                    $provider = $existing->refresh();
                } else {
                    $provider = ModelProvider::query()->create([
                        'vendor_key' => $vendorKey,
                        'name' => $name,
                        'base_url' => $baseUrl,
                        'default_model' => $textDefault,
                        'models' => $models,
                        'defaults' => ['text' => $textDefault, 'vision' => $visionDefault],
                        'is_active' => $isActive,
                        'sort_order' => $sortOrder,
                    ]);
                }

                if ($apiKey !== '') {
                    ModelKey::query()->updateOrCreate(
                        ['provider_id' => $provider->id, 'name' => 'default'],
                        ['api_key' => $apiKey, 'is_active' => true]
                    );
                }
            }

            if ($request->has('active_main_model_id')) {
                Setting::write('active_main_model_id', (string) $data['active_main_model_id']);
            }
            if ($request->has('active_vision_model_id')) {
                Setting::write('active_vision_model_id', (string) $data['active_vision_model_id']);
            }
        });

        return response()->json(['ok' => true, 'schema' => 'v2']);
    }

    private function setModelLegacySchema(Request $request)
    {
        $data = $request->validate([
            'base_url' => 'required|string',
            'api_key' => 'required|string',
            'model' => 'nullable|string',
            'models' => 'nullable|array',
            'defaults' => 'nullable|array',
        ]);

        $models = $this->normalizeModels($data['models'] ?? []);
        $textDefault = trim((string) Arr::get($data, 'defaults.text', $data['model'] ?? ''));
        $visionDefault = trim((string) Arr::get($data, 'defaults.vision', ''));

        if ($textDefault === '' && ! empty($models)) {
            $candidate = collect($models)->firstWhere('capability', 'text');
            $textDefault = (string) ($candidate['model_id'] ?? $models[0]['model_id']);
        }

        $baseUrl = trim((string) $data['base_url']);
        $vendorKey = VendorKeyInferrer::infer($baseUrl);

        DB::transaction(function () use ($data, $baseUrl, $vendorKey, $textDefault, $visionDefault, $models): void {
            $existing = ModelProvider::query()->where('vendor_key', $vendorKey)->first();
            if ($existing) {
                $existing->update([
                    'base_url' => $baseUrl,
                    'default_model' => $textDefault,
                    'models' => $models,
                    'defaults' => ['text' => $textDefault, 'vision' => $visionDefault],
                    'is_active' => true,
                ]);
                $provider = $existing->refresh();
            } else {
                $provider = ModelProvider::query()->create([
                    'vendor_key' => $vendorKey,
                    'name' => 'provider-' . $vendorKey,
                    'base_url' => $baseUrl,
                    'default_model' => $textDefault,
                    'models' => $models,
                    'defaults' => ['text' => $textDefault, 'vision' => $visionDefault],
                    'is_active' => true,
                    'sort_order' => 100,
                ]);
            }

            ModelKey::query()->updateOrCreate(
                ['provider_id' => $provider->id, 'name' => 'default'],
                ['api_key' => $data['api_key'], 'is_active' => true]
            );

            Setting::write('active_main_model_id', $textDefault);
            Setting::write('active_vision_model_id', $visionDefault);
        });

        return response()->json(['ok' => true, 'schema' => 'v1-compat', 'note' => 'Legacy schema accepted; please migrate to providers[] + active_*_model_id']);
    }

    public function getQuota()
    {
        return response()->json([
            'default_monthly_quota_tokens' => Setting::read('default_monthly_quota_tokens', 0),
            'retention_days' => Setting::read('retention_days', 180),
        ]);
    }

    public function setQuota(Request $request)
    {
        $data = $request->validate([
            'default_monthly_quota_tokens' => 'required|integer|min:0',
            'retention_days' => 'required|integer|min:1|max:3650',
        ]);
        Setting::write('default_monthly_quota_tokens', (int) $data['default_monthly_quota_tokens']);
        Setting::write('retention_days', (int) $data['retention_days']);

        return response()->json(['ok' => true]);
    }

    private function normalizeModels(array $models): array
    {
        $normalized = [];
        $seen = [];

        foreach ($models as $row) {
            $capability = strtolower(trim((string) Arr::get($row, 'capability', 'other')));
            if (! in_array($capability, self::CAPABILITIES, true)) {
                $capability = 'other';
            }

            $modelId = trim((string) Arr::get($row, 'model_id', ''));
            if ($modelId === '') {
                continue;
            }

            $dedupe = $capability.'::'.$modelId;
            if (isset($seen[$dedupe])) {
                continue;
            }
            $seen[$dedupe] = true;

            // R3d: 加 capabilities 数组（输入有就用，没有就 = [capability]）
            $capabilities = (array) Arr::get($row, 'capabilities', [$capability]);
            $capabilities = array_values(array_unique(array_filter(array_map(
                fn ($c) => strtolower(trim((string) $c)),
                $capabilities
            ))));
            if (empty($capabilities)) {
                $capabilities = [$capability];
            }

            $normalized[] = [
                'capability' => $capability,
                'capabilities' => $capabilities,
                'model_id' => $modelId,
                'label' => trim((string) Arr::get($row, 'label', '')),
            ];
        }

        return array_values($normalized);
    }
}
