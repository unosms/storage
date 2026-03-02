<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">FTP Transfers</h2>
    </x-slot>

    <div class="py-10">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            @if (session('status'))
                <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-emerald-800">
                    {{ session('status') }}
                </div>
            @endif

            @if ($errors->any())
                <div class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-rose-700">
                    <ul class="list-disc list-inside text-sm space-y-1">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="grid gap-6 lg:grid-cols-3">
                <div class="lg:col-span-2 rounded-2xl bg-white shadow-sm ring-1 ring-slate-200 p-6">
                    <h3 class="text-lg font-semibold text-slate-800 mb-4">Upload to FTP</h3>

                    <form action="{{ route('transfers.upload') }}" method="POST" enctype="multipart/form-data" class="space-y-4">
                        @csrf

                        <div>
                            <x-input-label for="file" value="Select File" />
                            <input id="file" name="file" type="file" required class="mt-1 block w-full rounded-lg border-slate-300" />
                        </div>

                        <div>
                            <x-input-label for="remote_subdir" value="Remote Sub Directory (optional)" />
                            <x-text-input id="remote_subdir" name="remote_subdir" type="text" class="mt-1 block w-full" placeholder="e.g. backups/2026" />
                        </div>

                        <div class="flex items-center justify-between rounded-xl bg-slate-50 border border-slate-200 px-4 py-3 text-sm">
                            <div>
                                <p class="text-slate-500">Quota Used</p>
                                <p class="font-semibold text-slate-800">{{ number_format($quotaUsedGb, 2) }} GB</p>
                            </div>
                            <div>
                                <p class="text-slate-500">Quota Total</p>
                                <p class="font-semibold text-slate-800">{{ $quotaTotalGb !== null ? number_format($quotaTotalGb, 2) . ' GB' : 'Unlimited' }}</p>
                            </div>
                            <div>
                                <p class="text-slate-500">Home Directory</p>
                                <p class="font-semibold text-slate-800">{{ auth()->user()->home_directory }}</p>
                            </div>
                            <div>
                                <p class="text-slate-500">Speed Limit</p>
                                <p class="font-semibold text-slate-800">{{ auth()->user()->speed_limit_kbps ? number_format(auth()->user()->speed_limit_kbps).' kbps' : 'Not Set' }}</p>
                            </div>
                        </div>

                        <x-primary-button>Start Upload</x-primary-button>
                    </form>
                </div>

                <div class="rounded-2xl bg-white shadow-sm ring-1 ring-slate-200 p-6">
                    <h3 class="text-lg font-semibold text-slate-800 mb-4">FTP Connection</h3>
                    @php
                        $u = auth()->user();
                    @endphp
                    <dl class="space-y-3 text-sm">
                        <div class="flex justify-between gap-3">
                            <dt class="text-slate-500">Host</dt>
                            <dd class="font-medium text-slate-800">{{ $u->ftp_host ?: 'Not configured' }}</dd>
                        </div>
                        <div class="flex justify-between gap-3">
                            <dt class="text-slate-500">Port</dt>
                            <dd class="font-medium text-slate-800">{{ $u->ftp_port }}</dd>
                        </div>
                        <div class="flex justify-between gap-3">
                            <dt class="text-slate-500">Username</dt>
                            <dd class="font-medium text-slate-800">{{ $u->ftp_username ?: 'Not configured' }}</dd>
                        </div>
                        <div class="flex justify-between gap-3">
                            <dt class="text-slate-500">Passive</dt>
                            <dd class="font-medium text-slate-800">{{ $u->ftp_passive ? 'Enabled' : 'Disabled' }}</dd>
                        </div>
                        <div class="flex justify-between gap-3">
                            <dt class="text-slate-500">SSL FTP</dt>
                            <dd class="font-medium text-slate-800">{{ $u->ftp_ssl ? 'Enabled' : 'Disabled' }}</dd>
                        </div>
                    </dl>
                    @if (auth()->user()->isAdmin())
                        <a href="{{ route('users.index') }}" class="mt-5 inline-block text-sm font-semibold text-indigo-600 hover:text-indigo-800">Manage user FTP settings</a>
                    @endif
                </div>
            </div>

            <div class="rounded-2xl bg-white shadow-sm ring-1 ring-slate-200 p-6">
                <h3 class="text-lg font-semibold text-slate-800 mb-4">Transfer Activity</h3>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-slate-50 text-slate-500 uppercase text-xs tracking-wide">
                            <tr>
                                <th class="px-4 py-3 text-left">Started</th>
                                <th class="px-4 py-3 text-left">User</th>
                                <th class="px-4 py-3 text-left">File</th>
                                <th class="px-4 py-3 text-left">Path</th>
                                <th class="px-4 py-3 text-left">Status</th>
                                <th class="px-4 py-3 text-right">Size</th>
                                <th class="px-4 py-3 text-right">Speed</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @forelse ($logs as $log)
                                <tr>
                                    <td class="px-4 py-3 text-slate-600">{{ optional($log->started_at)->format('Y-m-d H:i:s') ?? '-' }}</td>
                                    <td class="px-4 py-3 text-slate-800 font-medium">{{ $log->user?->name ?? '-' }}</td>
                                    <td class="px-4 py-3 text-slate-700">{{ $log->original_name }}</td>
                                    <td class="px-4 py-3 text-slate-500">{{ $log->ftp_path }}</td>
                                    <td class="px-4 py-3">
                                        <span class="inline-flex rounded-full px-2 py-1 text-xs font-semibold
                                            {{ $log->status === 'completed' ? 'bg-emerald-100 text-emerald-700' : '' }}
                                            {{ $log->status === 'failed' ? 'bg-rose-100 text-rose-700' : '' }}
                                            {{ $log->status === 'in_progress' ? 'bg-amber-100 text-amber-700' : '' }}">
                                            {{ str_replace('_', ' ', $log->status) }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 text-right font-medium text-slate-800">{{ number_format($log->size_bytes / 1024 / 1024, 2) }} MB</td>
                                    <td class="px-4 py-3 text-right text-slate-600">{{ $log->speed_kbps ? number_format($log->speed_kbps, 2) . ' kbps' : '-' }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="px-4 py-6 text-center text-slate-500">No transfers recorded yet.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <div class="mt-5">
                    {{ $logs->links() }}
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
