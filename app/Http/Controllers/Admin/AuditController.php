<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Support\WorkspaceContext;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AuditController extends Controller
{
    public function __construct(private readonly WorkspaceContext $context) {}

    public function index(Request $request): View
    {
        $action = $request->query('action');

        $logs = AuditLog::with('user')
            ->when(! $request->user()->is_super_admin, function ($q) {
                // Non-super admins see their workspace's entries plus system (null) ones.
                $q->where(fn ($w) => $w->where('workspace_id', $this->context->id())->orWhereNull('workspace_id'));
            })
            ->when($action, fn ($q) => $q->where('action', $action))
            ->latest('created_at')
            ->paginate(30)
            ->withQueryString();

        $actions = AuditLog::query()->select('action')->distinct()->orderBy('action')->pluck('action');

        return view('admin.audit.index', compact('logs', 'actions', 'action'));
    }
}
