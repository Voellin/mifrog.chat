<?php

namespace App\Modules\Doppelganger\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use App\Models\User;
use App\Modules\Doppelganger\Models\Doppelganger;
use App\Modules\Doppelganger\Models\DoppelgangerGrant;
use App\Modules\Doppelganger\Services\DoppelgangerService;
use App\Modules\Doppelganger\Services\WorkflowService;
use App\Services\AdminOperationLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class AdminDoppelgangerController extends Controller
{
    public function __construct(
        private readonly DoppelgangerService $service,
        private readonly WorkflowService $workflowService,
    ) {}

    public function index(Request $request)
    {
        $rows = Doppelganger::with('sourceUser')
            ->orderByDesc('id')
            ->paginate(20);

        return view('admin.doppelgangers.index', [
            'rows' => $rows,
            'statuses' => Doppelganger::STATUSES,
        ]);
    }

    public function create(Request $request)
    {
        $sourceUserId = (int) $request->query('source_user_id');
        $candidate = $sourceUserId ? User::find($sourceUserId) : null;

        // 仅显示已停用员工（is_active=false）作为分身候选，避免给在职员工建分身
        $candidates = User::where('is_active', false)
            ->whereDoesntHave('doppelganger')
            ->orderBy('name')
            ->limit(200)
            ->get(['id', 'name', 'department_id']);

        return view('admin.doppelgangers.create', [
            'candidate' => $candidate,
            'candidates' => $candidates,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'source_user_id' => 'required|integer|exists:users,id',
            'display_name' => 'nullable|string|max:191',
            'consent_signed_at' => 'required|date',
            'duration_months' => 'required|integer|min:1|max:60',
            'consent_doc' => 'nullable|file|mimes:pdf|max:10240',
        ]);

        $consentPath = null;
        if ($request->hasFile('consent_doc')) {
            $consentPath = $request->file('consent_doc')->store('doppelganger/consent', 'local');
        }

        $dop = $this->service->create((int) $data['source_user_id'], [
            'display_name' => $data['display_name'] ?? null,
            'consent_signed_at' => $data['consent_signed_at'],
            'consent_doc_path' => $consentPath,
            'expires_at' => now()->addMonths((int) $data['duration_months']),
        ]);

        AdminOperationLogger::log($request, 'doppelganger.create',
            "为用户 #{$dop->source_user_id} 创建数字分身（id={$dop->id}，时长 {$data['duration_months']} 个月）",
            ['target_type' => 'doppelganger', 'target_id' => $dop->id]
        );

        return redirect()->route('admin.doppelgangers.show', $dop->id)
            ->with('status', '数字分身已创建，等待激活');
    }

    public function show(int $id)
    {
        $dop = Doppelganger::with(['sourceUser', 'grants.grantee', 'workflows'])
            ->findOrFail($id);

        $invocationsCount = $dop->invocations()->count();
        $samplesSummary = $dop->samples()
            ->selectRaw('sample_type, COUNT(*) as count')
            ->groupBy('sample_type')
            ->pluck('count', 'sample_type')
            ->toArray();

        $allUsers = User::where('is_active', true)
            ->orderBy('name')
            ->limit(500)
            ->get(['id', 'name']);

        return view('admin.doppelgangers.show', compact(
            'dop', 'invocationsCount', 'samplesSummary', 'allUsers'
        ));
    }

    public function activate(int $id, Request $request)
    {
        $dop = Doppelganger::findOrFail($id);
        try {
            $this->service->activate($dop, $request->attributes->get('admin_user'));
            AdminOperationLogger::log($request, 'doppelganger.activate',
                "激活数字分身 #{$dop->id}", ['target_type' => 'doppelganger', 'target_id' => $dop->id]);
            return back()->with('status', '已激活');
        } catch (\Throwable $e) {
            return back()->withErrors(['activate' => '激活失败：' . $e->getMessage()]);
        }
    }

    public function pause(int $id, Request $request)
    {
        $dop = Doppelganger::findOrFail($id);
        $this->service->pause($dop);
        AdminOperationLogger::log($request, 'doppelganger.pause',
            "暂停数字分身 #{$dop->id}", ['target_type' => 'doppelganger', 'target_id' => $dop->id]);
        return back()->with('status', '已暂停');
    }

    public function resume(int $id, Request $request)
    {
        $dop = Doppelganger::findOrFail($id);
        try {
            $this->service->resume($dop);
            AdminOperationLogger::log($request, 'doppelganger.resume',
                "恢复数字分身 #{$dop->id}", ['target_type' => 'doppelganger', 'target_id' => $dop->id]);
            return back()->with('status', '已恢复');
        } catch (\Throwable $e) {
            return back()->withErrors(['resume' => $e->getMessage()]);
        }
    }

    public function revoke(int $id, Request $request)
    {
        $dop = Doppelganger::findOrFail($id);
        $reason = trim((string) $request->input('reason', ''));
        $this->service->revoke($dop, $reason);
        AdminOperationLogger::log($request, 'doppelganger.revoke',
            "撤销数字分身 #{$dop->id}（原因：{$reason}）", ['target_type' => 'doppelganger', 'target_id' => $dop->id]);
        return back()->with('status', '已撤销');
    }

    public function extend(int $id, Request $request)
    {
        $data = $request->validate([
            'months' => 'required|integer|min:1|max:60',
            'note' => 'nullable|string|max:500',
        ]);
        $dop = Doppelganger::findOrFail($id);
        $this->service->extend($dop, (int) $data['months'], $data['note'] ?? null);
        AdminOperationLogger::log($request, 'doppelganger.extend',
            "续期数字分身 #{$dop->id}（+{$data['months']} 月）", ['target_type' => 'doppelganger', 'target_id' => $dop->id]);
        return back()->with('status', "已续期 {$data['months']} 个月");
    }

    public function grant(int $id, Request $request)
    {
        $data = $request->validate([
            'grantee_user_id' => 'required|integer|exists:users,id',
            'access_level' => 'required|string|in:read_only,use_voice,use_workflow,full',
            'expires_days' => 'nullable|integer|min:1|max:365',
        ]);
        $dop = Doppelganger::findOrFail($id);
        $expiresAt = isset($data['expires_days']) ? now()->addDays((int) $data['expires_days']) : null;

        $this->service->grant($dop, (int) $data['grantee_user_id'], $data['access_level'],
            $request->attributes->get('admin_user'), $expiresAt);

        AdminOperationLogger::log($request, 'doppelganger.grant',
            "授权数字分身 #{$dop->id} 给 user#{$data['grantee_user_id']}（{$data['access_level']}）",
            ['target_type' => 'doppelganger', 'target_id' => $dop->id]);

        return back()->with('status', '授权成功');
    }

    public function revokeGrant(int $id, int $grantId, Request $request)
    {
        $this->service->revokeGrant($grantId);
        AdminOperationLogger::log($request, 'doppelganger.grant_revoke',
            "撤销授权 grant #{$grantId}（dop #{$id}）",
            ['target_type' => 'doppelganger', 'target_id' => $id]);
        return back()->with('status', '已撤销授权');
    }
}
