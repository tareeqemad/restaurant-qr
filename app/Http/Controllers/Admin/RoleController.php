<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Http\Request;

class RoleController extends Controller
{
    public function index()
    {
        $this->authorize('viewAny', Role::class);
        $roles = Role::with('permissions')->orderBy('display_order')->get();
        return view('admin.roles.index', compact('roles'));
    }

    public function create() { $this->authorize('create', Role::class); return view('admin.roles.create', ['permissions' => $this->permGrouped()]); }

    public function store(Request $request)
    {
        $this->authorize('create', Role::class);
        $data = $this->valid($request);
        $role = Role::create($data);
        $role->permissions()->sync($data['permissions'] ?? []);
        return redirect()->route('admin.roles.index')->with('success', 'تم');
    }

    public function edit(Role $role)
    {
        $this->authorize('update', $role);
        return view('admin.roles.edit', ['role' => $role, 'permissions' => $this->permGrouped()]);
    }

    public function update(Request $request, Role $role)
    {
        $this->authorize('update', $role);
        $data = $this->valid($request);
        $role->update($data);
        $role->permissions()->sync($data['permissions'] ?? []);
        return redirect()->route('admin.roles.index')->with('success', 'تم');
    }

    public function destroy(Role $role)
    {
        $this->authorize('delete', $role);
        if ($role->is_system) abort(403, 'لا يمكن حذف دور نظام');
        $role->delete();
        return back()->with('success', 'تم');
    }

    protected function permGrouped()
    {
        return Permission::orderBy('group')->orderBy('display_order')->get()->groupBy('group');
    }

    protected function valid(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string'],
            'label' => ['required', 'string'],
            'description' => ['nullable', 'string'],
            'display_order' => ['nullable', 'integer'],
            'permissions' => ['array'],
            'permissions.*' => ['exists:permissions,id'],
        ]);
    }
}
