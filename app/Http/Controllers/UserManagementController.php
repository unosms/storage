<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\FtpAccountProvisioningService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Throwable;

class UserManagementController extends Controller
{
    public function __construct(private readonly FtpAccountProvisioningService $ftpAccountProvisioningService)
    {
    }

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

        $user = User::create($this->buildPayload($data, true, null));

        try {
            $ftpPayload = $this->ftpAccountProvisioningService->provisionForUser($user, (string) $data['password']);
            $user->update(array_merge($ftpPayload, [
                'ftp_password' => (string) $data['password'],
            ]));
        } catch (Throwable $exception) {
            $user->delete();

            return back()
                ->withInput($request->except(['password', 'password_confirmation']))
                ->withErrors(['ftp' => 'User was not created: FTP provisioning failed. ' . $exception->getMessage()]);
        }

        return redirect()->route('users.index')->with('status', 'User created successfully.');
    }

    public function edit(User $user)
    {
        return view('users.edit', ['user' => $user]);
    }

    public function update(Request $request, User $user)
    {
        $data = $request->validate($this->rules($user->id, false));
        $payload = $this->buildPayload($data, false, $user);

        if (! empty($data['password'])) {
            try {
                $plainPassword = (string) $data['password'];

                if ($user->ftp_username) {
                    $this->ftpAccountProvisioningService->updateFtpPassword($user, $plainPassword);
                } else {
                    $ftpPayload = $this->ftpAccountProvisioningService->provisionForUser($user, $plainPassword);
                    $payload = array_merge($payload, $ftpPayload);
                }

                $payload['ftp_password'] = $plainPassword;
            } catch (Throwable $exception) {
                return back()
                    ->withInput($request->except(['password', 'password_confirmation']))
                    ->withErrors(['ftp' => 'FTP password update failed: ' . $exception->getMessage()]);
            }
        }

        $user->update($payload);

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
                ? ['required', 'string', 'min:6', 'max:255', 'confirmed']
                : ['nullable', 'string', 'min:6', 'max:255', 'confirmed'],
            'role' => ['required', Rule::in(['admin', 'user'])],
            'can_upload' => ['nullable', 'boolean'],
            'can_view_monitoring' => ['nullable', 'boolean'],
            'can_manage_users' => ['nullable', 'boolean'],
            'quota_mb' => ['required', 'integer', 'min:0'],
            'speed_limit_kbps' => ['nullable', 'integer', 'min:0'],
        ];
    }

    private function buildPayload(array $data, bool $isCreate, ?User $existingUser): array
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
            'home_directory' => $isCreate
                ? '/'
                : ((string) ($existingUser?->home_directory ?: '/')),
            'ftp_host' => $isCreate
                ? null
                : ((string) ($existingUser?->ftp_host ?: config('storage_manager.ftp.host', '127.0.0.1'))),
            'ftp_port' => $isCreate
                ? (int) config('storage_manager.ftp.port', 21)
                : (int) ($existingUser?->ftp_port ?: config('storage_manager.ftp.port', 21)),
            'ftp_username' => $isCreate
                ? null
                : $existingUser?->ftp_username,
            'ftp_passive' => $isCreate
                ? (bool) config('storage_manager.ftp.passive', true)
                : (bool) ($existingUser?->ftp_passive ?? config('storage_manager.ftp.passive', true)),
            'ftp_ssl' => $isCreate
                ? (bool) config('storage_manager.ftp.ssl', false)
                : (bool) ($existingUser?->ftp_ssl ?? config('storage_manager.ftp.ssl', false)),
        ];

        if ($isCreate || ! empty($data['password'])) {
            $payload['password'] = Hash::make($data['password']);
        }

        if (! empty($data['password'])) {
            $payload['ftp_password'] = (string) $data['password'];
        }

        return $payload;
    }
}
