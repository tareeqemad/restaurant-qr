<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Station;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', User::class);

        $q = User::query()->with('station');
        if ($s = $request->get('search')) {
            $q->where(function ($qq) use ($s) {
                $qq->where('name', 'like', "%$s%")
                    ->orWhere('username', 'like', "%$s%")
                    ->orWhere('email', 'like', "%$s%")
                    ->orWhere('phone', 'like', "%$s%");
            });
        }
        if ($role = $request->get('role')) {
            $q->where('role', $role);
        }
        if ($status = $request->get('status')) {
            $q->where('status', $status);
        }
        $users = $q->latest()->paginate(15)->withQueryString();

        $stats = [
            'total'    => User::count(),
            'active'   => User::where('status', 'active')->count(),
            'admins'   => User::whereIn('role', ['super_admin', 'admin'])->count(),
            'inactive' => User::where('status', '!=', 'active')->count(),
        ];

        return view('admin.users.index', [
            'users' => $users,
            'roles' => UserRole::options(),
            'stats' => $stats,
        ]);
    }

    public function create()
    {
        $this->authorize('create', User::class);
        return view('admin.users.create', [
            'roles' => UserRole::options(),
            'stations' => Station::where('active', true)->get(),
        ]);
    }

    public function store(Request $request)
    {
        $this->authorize('create', User::class);
        $data = $this->validateData($request);
        $data['password'] = Hash::make($data['password']);
        $user = User::create($data);

        ActivityLog::log('user.created', "إنشاء مستخدم {$user->name}", $user);
        return redirect()->route('admin.users.index')->with('success', 'تم إنشاء المستخدم');
    }

    public function edit(User $user)
    {
        $this->authorize('update', $user);
        return view('admin.users.edit', [
            'user' => $user,
            'roles' => UserRole::options(),
            'stations' => Station::where('active', true)->get(),
        ]);
    }

    public function update(Request $request, User $user)
    {
        $this->authorize('update', $user);
        $data = $this->validateData($request, $user->id);
        if (! empty($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        } else {
            unset($data['password']);
        }
        $user->update($data);

        ActivityLog::log('user.updated', "تعديل المستخدم {$user->name}", $user);
        return redirect()->route('admin.users.index')->with('success', 'تم تحديث المستخدم');
    }

    public function destroy(User $user)
    {
        $this->authorize('delete', $user);
        $user->delete();
        ActivityLog::log('user.deleted', "حذف المستخدم {$user->name}", $user);
        return back()->with('success', 'تم حذف المستخدم');
    }

    public function toggleStatus(User $user)
    {
        $this->authorize('update', $user);
        $user->update(['status' => $user->status === 'active' ? 'suspended' : 'active']);
        ActivityLog::log('user.status_changed', "تغيير حالة {$user->name} إلى {$user->status}", $user);
        return back()->with('success', 'تم تغيير حالة المستخدم');
    }

    protected function validateData(Request $request, ?int $id = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'username' => ['required', 'string', 'max:64', Rule::unique('users')->ignore($id)],
            'email' => ['nullable', 'email', Rule::unique('users')->ignore($id)],
            'phone' => ['nullable', 'string', 'max:20'],
            'role' => ['required', Rule::in(array_keys(UserRole::options()))],
            'station_id' => ['nullable', 'exists:stations,id'],
            'status' => ['required', Rule::in(['active', 'inactive', 'suspended'])],
            'password' => [$id ? 'nullable' : 'required', 'min:6', 'confirmed'],
        ]);
    }
}
