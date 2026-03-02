<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UserManagementController extends Controller
{
    public function index()
    {
        return view('users.index', [
            'users' => User::orderBy('id')->paginate(15),
        ]);
    }

    public function create()
    {
        return view('users.create');
    }

    public function store(Request $request)
    {
        $data = $request->validate($this->rules());

        User::create($this->buildPayload($data, true));

        return redirect()->route('users.index')->with('status', 'User created successfully.');
    }

    public function edit(User $user)
    {
        return view('users.edit', ['user' => $user]);
    }

    public function update(Request $request, User $user)
    {
        $data = $request->validate($this->rules($user->id, false));

        $user->update($this->buildPayload($data, false));

        return redirect()->route('users.index')->with('status', 'User updated successfully.');
    }

    public function destroy(Request $request, User $user)
    {
        if ($request->user()->id === $user->id) {
            return back()->withErrors(['user' => 'You cannot delete your own admin account.']);
        }

        $user->delete();

        return redirect()->route('users.index')->with('status', 'User deleted successfully.');
    }

    private function rules(?int $ignoreId = null, bool $isCreate = true): array
    {
        $emailRule = ['required', 'email', 'max:255', Rule::unique('users', 'email')];
        if ($ignoreId) {
            $emailRule = ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($ignoreId)];
        }

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => $emailRule,
            'password' => $isCreate
                ? ['required', 'confirmed', Password::defaults()]
                : ['nullable', 'confirmed', Password::defaults()],
            'role' => ['required', Rule::in(['admin', 'user'])],
            'can_upload' => ['nullable', 'boolean'],
            'can_view_monitoring' => ['nullable', 'boolean'],
            'can_manage_users' => ['nullable', 'boolean'],
            'quota_mb' => ['required', 'integer', 'min:0'],
            'speed_limit_kbps' => ['nullable', 'integer', 'min:0'],
            'home_directory' => ['required', 'string', 'max:255'],
            'ftp_host' => ['nullable', 'string', 'max:255'],
            'ftp_port' => ['nullable', 'integer', 'between:1,65535'],
            'ftp_username' => ['nullable', 'string', 'max:255'],
            'ftp_password' => [$isCreate ? 'nullable' : 'nullable', 'string', 'max:255'],
            'ftp_passive' => ['nullable', 'boolean'],
            'ftp_ssl' => ['nullable', 'boolean'],
        ];
    }

    private function buildPayload(array $data, bool $isCreate): array
    {
        $role = $data['role'];

        $payload = [
            'name' => $data['name'],
            'email' => $data['email'],
            'role' => $role,
            'can_upload' => (bool) ($data['can_upload'] ?? false),
            'can_view_monitoring' => (bool) ($data['can_view_monitoring'] ?? false),
            'can_manage_users' => $role === 'admin' ? true : (bool) ($data['can_manage_users'] ?? false),
            'quota_mb' => (int) $data['quota_mb'],
            'speed_limit_kbps' => isset($data['speed_limit_kbps']) && $data['speed_limit_kbps'] !== ''
                ? (int) $data['speed_limit_kbps']
                : null,
            'home_directory' => $data['home_directory'],
            'ftp_host' => $data['ftp_host'] ?? null,
            'ftp_port' => isset($data['ftp_port']) && $data['ftp_port'] !== '' ? (int) $data['ftp_port'] : 21,
            'ftp_username' => $data['ftp_username'] ?? null,
            'ftp_passive' => (bool) ($data['ftp_passive'] ?? false),
            'ftp_ssl' => (bool) ($data['ftp_ssl'] ?? false),
        ];

        if (! empty($data['ftp_password'])) {
            $payload['ftp_password'] = $data['ftp_password'];
        }

        if ($isCreate || ! empty($data['password'])) {
            $payload['password'] = Hash::make($data['password']);
        }

        return $payload;
    }
}
