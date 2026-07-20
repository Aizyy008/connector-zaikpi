<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class WorkspaceSwitchController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'workspace_id' => ['required', 'integer'],
        ]);

        $workspace = Workspace::where('status', 'active')->find($data['workspace_id']);

        // Enforce access: a user may only switch to a workspace they belong to
        // (super admins may switch to any active workspace).
        if (! $workspace || ! $request->user()->belongsToWorkspace($workspace)) {
            abort(403, 'You cannot access that workspace.');
        }

        $request->session()->put('current_workspace_id', $workspace->id);

        return back()->with('status', "Switched to “{$workspace->name}”.");
    }
}
