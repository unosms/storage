@php
    $isEdit = isset($user);
@endphp

<div class="grid gap-4 md:grid-cols-2">
    <div>
        <x-input-label for="name" value="Name" />
        <x-text-input id="name" name="name" type="text" class="mt-1 block w-full" value="{{ old('name', $user->name ?? '') }}" required />
    </div>

    <div>
        <x-input-label for="email" value="Email" />
        <x-text-input id="email" name="email" type="email" class="mt-1 block w-full" value="{{ old('email', $user->email ?? '') }}" required />
    </div>

    <div>
        <x-input-label for="password" value="{{ $isEdit ? 'Password (leave blank to keep current)' : 'Password' }}" />
        <input id="password" name="password" type="password" class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500" autocomplete="new-password" {{ $isEdit ? '' : 'required' }} />
        <x-input-error :messages="$errors->get('password')" class="mt-2" />
    </div>

    <div>
        <x-input-label for="password_confirmation" value="Confirm Password" />
        <input id="password_confirmation" name="password_confirmation" type="password" class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500" autocomplete="new-password" {{ $isEdit ? '' : 'required' }} />
        <x-input-error :messages="$errors->get('password_confirmation')" class="mt-2" />
    </div>

    <div>
        <x-input-label for="role" value="Role" />
        <select id="role" name="role" class="mt-1 block w-full rounded-lg border-slate-300" required>
            @php $roleValue = old('role', $user->role ?? 'user'); @endphp
            <option value="user" {{ $roleValue === 'user' ? 'selected' : '' }}>User</option>
            <option value="admin" {{ $roleValue === 'admin' ? 'selected' : '' }}>Admin</option>
        </select>
    </div>

    <div>
        <x-input-label for="quota_mb" value="Quota (MB)" />
        <x-text-input id="quota_mb" name="quota_mb" type="number" min="0" class="mt-1 block w-full" value="{{ old('quota_mb', $user->quota_mb ?? 10240) }}" required />
        <p class="mt-1 text-xs text-slate-500">Use 0 for unlimited quota.</p>
    </div>

    <div>
        <x-input-label for="speed_limit_kbps" value="Speed Limit (kbps)" />
        <x-text-input id="speed_limit_kbps" name="speed_limit_kbps" type="number" min="0" class="mt-1 block w-full" value="{{ old('speed_limit_kbps', $user->speed_limit_kbps ?? '') }}" />
    </div>

    <div>
        <x-input-label value="FTP User" />
        <div class="mt-1 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-700">
            {{ $user->ftp_username ?? 'Auto-generated on create' }}
        </div>
    </div>
</div>

<div class="mt-6 grid gap-3 md:grid-cols-2 xl:grid-cols-4">
    @php
        $canUpload = (bool) old('can_upload', $user->can_upload ?? true);
        $canMonitoring = (bool) old('can_view_monitoring', $user->can_view_monitoring ?? true);
        $canManageUsers = (bool) old('can_manage_users', $user->can_manage_users ?? false);
    @endphp

    <label class="inline-flex items-center gap-2 text-sm">
        <input type="checkbox" name="can_upload" value="1" {{ $canUpload ? 'checked' : '' }} class="rounded border-slate-300" />
        Can Upload
    </label>
    <label class="inline-flex items-center gap-2 text-sm">
        <input type="checkbox" name="can_view_monitoring" value="1" {{ $canMonitoring ? 'checked' : '' }} class="rounded border-slate-300" />
        Can View Monitoring
    </label>
    <label class="inline-flex items-center gap-2 text-sm">
        <input type="checkbox" name="can_manage_users" value="1" {{ $canManageUsers ? 'checked' : '' }} class="rounded border-slate-300" />
        Can Manage Users
    </label>
</div>
