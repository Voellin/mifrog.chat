<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\AdminOperationLog;
use App\Models\AdminUser;
use Illuminate\Http\Request;
use Illuminate\Contracts\View\View;

class AdminOperationLogController extends Controller
{
    public function index(Request $request): View
    {
        $actorId = (int) $request->query('actor_id', 0);
        $actionFilter = trim((string) $request->query('action', ''));

        $query = AdminOperationLog::query()
            ->with('adminUser:id,username,display_name')
            ->orderByDesc('id');

        if ($actorId > 0) {
            $query->where('admin_user_id', $actorId);
        }
        if ($actionFilter !== '') {
            $query->where('action', 'like', $actionFilter.'%');
        }

        $logs = $query->paginate(40)->withQueryString();

        $actors = AdminUser::query()
            ->orderBy('username')
            ->get(['id', 'username', 'display_name']);

        $actionTypes = AdminOperationLog::query()
            ->selectRaw('action, COUNT(*) AS cnt')
            ->groupBy('action')
            ->orderByDesc('cnt')
            ->limit(40)
            ->get();

        return view('admin.operation_logs', [
            'logs' => $logs,
            'actors' => $actors,
            'actionTypes' => $actionTypes,
            'selectedActorId' => $actorId,
            'selectedAction' => $actionFilter,
        ]);
    }
}
