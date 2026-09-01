<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Role;

class RoleController extends Controller
{
    public function index()
    {
        $this->authorize('viewAny', Role::class);

        return redirect()->route('admin.permissions.index', ['tab' => 'roles']);
    }

    public function create()
    {
        abort(404);
    }

    public function store()
    {
        abort(404);
    }

    public function edit(Role $role)
    {
        $this->authorize('view', $role);

        return redirect()->route('admin.permissions.index', [
            'tab' => 'roles',
            'role' => $role->id,
        ]);
    }

    public function update(Role $role)
    {
        abort(404);
    }

    public function destroy(Role $role)
    {
        abort(404);
    }

    public function clone(Role $role)
    {
        abort(404);
    }
}
