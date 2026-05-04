<?php

namespace App\Modules\Doppelganger\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Doppelganger\Models\Doppelganger;
use App\Modules\Doppelganger\Models\DoppelgangerInvocation;
use App\Modules\Doppelganger\Services\DoppelgangerService;
use App\Modules\Doppelganger\Services\KnowledgeService;
use App\Modules\Doppelganger\Services\VoiceService;
use App\Modules\Doppelganger\Services\WorkflowService;
use App\Services\AdminOperationLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * 接班人使用入口 —— 统一对话框，按用户输入意图分发到 3 种能力。
 *
 * V1 调度策略（简单）：
 *   - 默认走 Level 1 知识检索
 *   - 用户在 UI 上显式选「起草回复」→ Level 2 voice
 *   - 用户在 UI 上点击某个 workflow → Level 3
 */
class DoppelgangerInvocationController extends Controller
{
    public function __construct(
        private readonly DoppelgangerService $service,
        private readonly KnowledgeService $knowledge,
        private readonly VoiceService $voice,
        private readonly WorkflowService $workflowService,
    ) {}

    /**
     * 接班人浏览自己被授权的分身列表
     */
    public function myGrants(Request $request)
    {
        $admin = $request->attributes->get('admin_user');
        // V1：admin 视图——展示所有 active 分身（admin 跨权限可见所有）
        // 未来如果接入员工自助：根据 admin 关联的 user 找 grants
        $rows = Doppelganger::with('sourceUser')
            ->where('status', Doppelganger::STATUS_ACTIVE)
            ->orderByDesc('enabled_at')
            ->paginate(20);

        return view('admin.doppelgangers.my_grants', ['rows' => $rows]);
    }

    /**
     * 统一对话页面：1 个对话框 + 模式切换（知识 / 起草 / 工作流）
     */
    public function chat(int $id)
    {
        $dop = Doppelganger::with('sourceUser')->findOrFail($id);
        if (! $dop->isActive()) {
            return redirect()->route('admin.doppelgangers.my_grants')
                ->withErrors(['chat' => '该分身当前不可用（状态：' . $dop->status . '）']);
        }
        $workflows = $this->workflowService->listForDoppelganger($dop);
        $recentInvocations = DoppelgangerInvocation::where('doppelganger_id', $dop->id)
            ->orderByDesc('id')->limit(20)->get();

        return view('admin.doppelgangers.chat', compact('dop', 'workflows', 'recentInvocations'));
    }

    /**
     * Level 1：知识问答
     */
    public function ask(int $id, Request $request)
    {
        $data = $request->validate(['query' => 'required|string|min:2|max:1000']);
        $dop = Doppelganger::findOrFail($id);
        if (! $dop->isActive()) {
            return response()->json(['ok' => false, 'error' => '分身不可用'], 400);
        }

        $admin = $request->attributes->get('admin_user');
        $result = $this->knowledge->ask($dop, $data['query']);

        $inv = DoppelgangerInvocation::create([
            'doppelganger_id' => $dop->id,
            'caller_admin_id' => $admin->id,
            'level' => DoppelgangerInvocation::LEVEL_KNOWLEDGE,
            'query' => $data['query'],
            'response_excerpt' => mb_substr($result['answer'], 0, 500),
            'token_input' => $result['token_input'],
            'token_output' => $result['token_output'],
            'meta' => ['sources_count' => count($result['sources'])],
        ]);

        AdminOperationLogger::log($request, 'doppelganger.ask',
            "查询数字分身 #{$dop->id}：" . mb_substr($data['query'], 0, 100),
            ['target_type' => 'doppelganger', 'target_id' => $dop->id]);

        return response()->json([
            'ok' => true,
            'invocation_id' => $inv->id,
            'answer' => $result['answer'],
            'sources' => $result['sources'],
            'tokens' => ['input' => $result['token_input'], 'output' => $result['token_output']],
        ]);
    }

    /**
     * Level 2：风格起草
     */
    public function draft(int $id, Request $request)
    {
        $data = $request->validate(['situation' => 'required|string|min:2|max:2000']);
        $dop = Doppelganger::findOrFail($id);
        if (! $dop->isActive()) {
            return response()->json(['ok' => false, 'error' => '分身不可用'], 400);
        }

        $admin = $request->attributes->get('admin_user');
        $result = $this->voice->draft($dop, $data['situation']);

        $inv = DoppelgangerInvocation::create([
            'doppelganger_id' => $dop->id,
            'caller_admin_id' => $admin->id,
            'level' => DoppelgangerInvocation::LEVEL_VOICE,
            'query' => $data['situation'],
            'response_excerpt' => mb_substr($result['draft'], 0, 500),
            'token_input' => $result['token_input'],
            'token_output' => $result['token_output'],
            'meta' => ['samples_used' => $result['samples_used']],
        ]);

        AdminOperationLogger::log($request, 'doppelganger.draft',
            "请求数字分身 #{$dop->id} 起草：" . mb_substr($data['situation'], 0, 100),
            ['target_type' => 'doppelganger', 'target_id' => $dop->id]);

        return response()->json([
            'ok' => true,
            'invocation_id' => $inv->id,
            'draft' => $result['draft'],
            'samples_used' => $result['samples_used'],
            'tokens' => ['input' => $result['token_input'], 'output' => $result['token_output']],
        ]);
    }

    /**
     * Level 3：查看工作流模板
     */
    public function workflowPreview(int $id, int $workflowId, Request $request)
    {
        $dop = Doppelganger::findOrFail($id);
        if (! $dop->isActive()) {
            return response()->json(['ok' => false, 'error' => '分身不可用'], 400);
        }
        $wf = $dop->workflows()->findOrFail($workflowId);

        $admin = $request->attributes->get('admin_user');
        $preview = $this->workflowService->previewWorkflow($wf);

        DoppelgangerInvocation::create([
            'doppelganger_id' => $dop->id,
            'caller_admin_id' => $admin->id,
            'level' => DoppelgangerInvocation::LEVEL_WORKFLOW,
            'query' => "查看工作流 #{$wf->id}：" . $wf->workflow_name,
            'response_excerpt' => mb_substr($preview['body'], 0, 500),
            'token_input' => 0,
            'token_output' => 0,
            'meta' => ['workflow_id' => $wf->id],
        ]);

        return response()->json(['ok' => true, 'preview' => $preview]);
    }
}
