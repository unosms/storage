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
        <x-text-input id="password" name="password" type="password" class="mt-1 block w-full" {{ $isEdit ? '' : 'required' }} />
    </div>

    <div>
        <x-input-label for="password_confirmation" value="Confirm Password" />
        <x-text-input id="password_confirmation" name="password_confirmation" type="password" class="mt-1 block w-full" {{ $isEdit ? '' : 'required' }} />
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
        <x-input-label for="home_directory" value="Home Directory" />
        <x-text-input id="home_directory" name="home_directory" type="text" class="mt-1 block w-full" value="{{ old('home_directory', $user->home_directory ?? '/') }}" required />
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
</div>

<div class="mt-6 rounded-xl border border-slate-200 p-4 bg-slate-50">
    <h4 class="text-sm font-semibold text-slate-700 mb-3">FTP Settings</h4>
    <div class="grid gap-4 md:grid-cols-2">
        <div>
            <x-input-label for="ftp_host" value="FTP Host" />
            <x-text-input id="ftp_host" name="ftp_host" type="text" class="mt-1 block w-full" value="{{ old('ftp_host', $user->ftp_host ?? '') }}" />
        </div>
        <div>
            <x-input-label for="ftp_port" value="FTP Port" />
            <x-text-input id="ftp_port" name="ftp_port" type="number" class="mt-1 block w-full" value="{{ old('ftp_port', $user->ftp_port ?? 21) }}" />
        </div>
        <div>
            <x-input-label for="ftp_username" value="FTP Username" />
            <x-text-input id="ftp_username" name="ftp_username" type="text" class="mt-1 block w-full" value="{{ old('ftp_username', $user->ftp_username ?? '') }}" />
        </div>
        <div>
            <x-input-label for="ftp_password" value="FTP Password {{ $isEdit ? '(leave blank to keep current)' : '' }}" />
            <x-text-input id="ftp_password" name="ftp_password" type="password" class="mt-1 block w-full" />
        </div>
    </div>
</div>

<div class="mt-6 grid gap-3 md:grid-cols-2 xl:grid-cols-4">
    @php
        $canUpload = (bool) old('can_upload', $user->can_upload ?? true);
        $canMonitoring = (bool) old('can_view_monitoring', $user->can_view_monitoring ?? true);
        $canManageUsers = (bool) old('can_manage_users', $user->can_manage_users ?? false);
        $ftpPassive = (bool) old('ftp_passive', $user->ftp_passive ?? true);
        $ftpSsl = (bool) old('ftp_ssl', $user->ftp_ssl ?? false);
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
    <label class="inline-flex items-center gap-2 text-sm">
        <input type="checkbox" name="ftp_passive" value="1" {{ $ftpPassive ? 'checked' : '' }} class="rounded border-slate-300" />
        FTP Passive Mode
    </label>
    <label class="inline-flex items-center gap-2 text-sm">
        <input type="checkbox" name="ftp_ssl" value="1" {{ $ftpSsl ? 'checked' : '' }} class="rounded border-slate-300" />
        Use FTP SSL
    </label>
</div>
