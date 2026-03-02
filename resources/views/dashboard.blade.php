<x-app-layout>
    <style>
        .dashboard-stat-card {
            position: relative;
            overflow: hidden;
            border-radius: 1rem;
            padding: 1.25rem;
            color: #fff;
            box-shadow: 0 12px 28px rgba(15, 23, 42, 0.18);
            isolation: isolate;
        }

        .dashboard-stat-card::before,
        .dashboard-stat-card::after {
            content: "";
            position: absolute;
            border-radius: 9999px;
            background: rgba(255, 255, 255, 0.18);
            z-index: -1;
        }

        .dashboard-stat-card::before {
            width: 110px;
            height: 110px;
            top: -38px;
            right: -22px;
        }

        .dashboard-stat-card::after {
            width: 72px;
            height: 72px;
            bottom: -28px;
            left: -20px;
            background: rgba(255, 255, 255, 0.12);
        }

        .dashboard-stat-users {
            background: linear-gradient(135deg, #06b6d4 0%, #0284c7 100%);
        }

        .dashboard-stat-sessions {
            background: linear-gradient(135deg, #6366f1 0%, #7c3aed 100%);
        }

        .dashboard-stat-progress {
            background: linear-gradient(135deg, #10b981 0%, #0d9488 100%);
        }

        .dashboard-stat-transfer {
            background: linear-gradient(135deg, #f43f5e 0%, #f97316 100%);
        }
    </style>

    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">Storage Dashboard</h2>
            <span class="text-sm text-gray-500" id="monitoring-updated">Updated: --</span>
        </div>
    </x-slot>

    <div class="py-10">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            @if (session('status'))
                <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-emerald-800">
                    {{ session('status') }}
                </div>
            @endif

            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                <div class="dashboard-stat-card dashboard-stat-users">
                    <p class="text-sm uppercase tracking-wider text-white/85">Total Users</p>
                    <p class="mt-2 text-3xl font-black">{{ number_format($stats['users_total']) }}</p>
                    <p class="mt-1 text-xs text-white/80">Admins: {{ number_format($stats['admins_total']) }}</p>
                </div>
                <div class="dashboard-stat-card dashboard-stat-sessions">
                    <p class="text-sm uppercase tracking-wider text-white/85">Active Sessions</p>
                    <p class="mt-2 text-3xl font-black" id="active-sessions-card">{{ number_format($stats['active_sessions']) }}</p>
                    <p class="mt-1 text-xs text-white/80">Logged in users now</p>
                </div>
                <div class="dashboard-stat-card dashboard-stat-progress">
                    <p class="text-sm uppercase tracking-wider text-white/85">In-Progress Transfers</p>
                    <p class="mt-2 text-3xl font-black" id="in-progress-card">{{ number_format($stats['in_progress']) }}</p>
                    <p class="mt-1 text-xs text-white/80">Current FTP uploads/downloads</p>
                </div>
                <div class="dashboard-stat-card dashboard-stat-transfer">
                    <p class="text-sm uppercase tracking-wider text-white/85">Transferred</p>
                    <p class="mt-2 text-3xl font-black">{{ number_format($stats['transferred_gb'], 2) }} GB</p>
                    <p class="mt-1 text-xs text-white/80">Completed uploads: {{ number_format($stats['total_uploads']) }}</p>
                </div>
            </div>

            <div class="grid gap-6 xl:grid-cols-3">
                <div class="xl:col-span-2 rounded-2xl bg-white shadow-sm ring-1 ring-slate-200 p-6">
                    <div class="mb-4 flex items-center justify-between">
                        <h3 class="text-lg font-semibold text-slate-800">System Monitoring</h3>
                        @if (! $canViewMonitoring)
                            <span class="text-xs rounded-full bg-amber-100 text-amber-800 px-2 py-1">Permission required</span>
                        @endif
                    </div>

                    @if ($canViewMonitoring)
                        <div class="grid gap-4 md:grid-cols-3">
                            <div class="rounded-xl border border-slate-200 p-4 bg-slate-50">
                                <p class="text-xs uppercase tracking-wide text-slate-500">CPU</p>
                                <p class="mt-2 text-2xl font-bold text-slate-900" id="cpu-percent">{{ $monitor['cpu_percent'] !== null ? $monitor['cpu_percent'].'%' : 'N/A' }}</p>
                            </div>
                            <div class="rounded-xl border border-slate-200 p-4 bg-slate-50">
                                <p class="text-xs uppercase tracking-wide text-slate-500">RAM</p>
                                <p class="mt-2 text-2xl font-bold text-slate-900" id="ram-percent">{{ $monitor['ram_percent'] !== null ? $monitor['ram_percent'].'%' : 'N/A' }}</p>
                                <p class="text-xs text-slate-500" id="ram-details">
                                    @if($monitor['ram_used_gb'] !== null && $monitor['ram_total_gb'] !== null)
                                        {{ $monitor['ram_used_gb'] }} / {{ $monitor['ram_total_gb'] }} GB
                                    @else
                                        N/A
                                    @endif
                                </p>
                            </div>
                            <div class="rounded-xl border border-slate-200 p-4 bg-slate-50">
                                <p class="text-xs uppercase tracking-wide text-slate-500">Disk Usage</p>
                                <p class="mt-2 text-2xl font-bold text-slate-900" id="disk-percent">{{ $monitor['disk_percent'] !== null ? $monitor['disk_percent'].'%' : 'N/A' }}</p>
                                <p class="text-xs text-slate-500" id="disk-details">
                                    @if($monitor['disk_used_gb'] !== null && $monitor['disk_total_gb'] !== null)
                                        {{ $monitor['disk_used_gb'] }} / {{ $monitor['disk_total_gb'] }} GB
                                    @else
                                        N/A
                                    @endif
                                </p>
                            </div>
                        </div>
                    @else
                        <p class="text-slate-600">Your account does not have permission to view system monitoring.</p>
                    @endif
                </div>

                <div class="rounded-2xl bg-white shadow-sm ring-1 ring-slate-200 p-6">
                    <h3 class="text-lg font-semibold text-slate-800 mb-4">Storage Quota</h3>
                    <div class="space-y-3 text-sm">
                        <div class="flex justify-between">
                            <span class="text-slate-500">Total Quota</span>
                            <span class="font-semibold text-slate-800">{{ number_format($stats['total_quota_gb'], 2) }} GB</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-slate-500">Used Space</span>
                            <span class="font-semibold text-slate-800">{{ number_format($stats['used_quota_gb'], 2) }} GB</span>
                        </div>
                    </div>
                    <div class="mt-4 h-3 rounded-full bg-slate-100 overflow-hidden">
                        @php
                            $usagePercent = $stats['total_quota_gb'] > 0 ? min(100, ($stats['used_quota_gb'] / $stats['total_quota_gb']) * 100) : 0;
                        @endphp
                        <div class="h-full rounded-full bg-gradient-to-r from-indigo-500 to-cyan-500" style="width: {{ $usagePercent }}%"></div>
                    </div>
                    <p class="mt-2 text-xs text-slate-500">{{ number_format($usagePercent, 1) }}% quota used</p>
                </div>
            </div>

            <div class="rounded-2xl bg-white shadow-sm ring-1 ring-slate-200 p-6">
                <div class="mb-4 flex items-center justify-between">
                    <h3 class="text-lg font-semibold text-slate-800">Recent Transfers</h3>
                    <a href="{{ route('transfers.index') }}" class="text-sm font-semibold text-indigo-600 hover:text-indigo-800">Open Transfers</a>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-slate-50 text-slate-500 uppercase text-xs tracking-wide">
                            <tr>
                                <th class="px-4 py-3 text-left">Time</th>
                                <th class="px-4 py-3 text-left">User</th>
                                <th class="px-4 py-3 text-left">File</th>
                                <th class="px-4 py-3 text-left">Status</th>
                                <th class="px-4 py-3 text-right">Size</th>
                                <th class="px-4 py-3 text-right">Speed</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @forelse ($recentTransfers as $transfer)
                                <tr>
                                    <td class="px-4 py-3 text-slate-600">{{ optional($transfer->started_at)->format('Y-m-d H:i:s') ?? '-' }}</td>
                                    <td class="px-4 py-3 font-medium text-slate-800">{{ $transfer->user?->name ?? '-' }}</td>
                                    <td class="px-4 py-3 text-slate-600">{{ $transfer->original_name }}</td>
                                    <td class="px-4 py-3">
                                        <span class="inline-flex rounded-full px-2 py-1 text-xs font-semibold
                                            {{ $transfer->status === 'completed' ? 'bg-emerald-100 text-emerald-700' : '' }}
                                            {{ $transfer->status === 'failed' ? 'bg-rose-100 text-rose-700' : '' }}
                                            {{ $transfer->status === 'in_progress' ? 'bg-amber-100 text-amber-700' : '' }}">
                                            {{ str_replace('_', ' ', $transfer->status) }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 text-right font-medium text-slate-800">{{ number_format($transfer->size_bytes / 1024 / 1024, 2) }} MB</td>
                                    <td class="px-4 py-3 text-right text-slate-600">{{ $transfer->speed_kbps ? number_format($transfer->speed_kbps, 2) . ' kbps' : '-' }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="px-4 py-6 text-center text-slate-500">No transfers yet.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    @if ($canViewMonitoring)
        <script>
            (function () {
                const cpu = document.getElementById('cpu-percent');
                const ram = document.getElementById('ram-percent');
                const ramDetails = document.getElementById('ram-details');
                const disk = document.getElementById('disk-percent');
                const diskDetails = document.getElementById('disk-details');
                const activeSessions = document.getElementById('active-sessions-card');
                const inProgress = document.getElementById('in-progress-card');
                const updated = document.getElementById('monitoring-updated');

                async function refreshMonitoring() {
                    try {
                        const response = await fetch('{{ route('monitoring.live') }}', {
                            headers: {
                                'X-Requested-With': 'XMLHttpRequest'
                            }
                        });

                        if (!response.ok) {
                            return;
                        }

                        const data = await response.json();

                        cpu.textContent = data.monitor.cpu_percent !== null ? `${data.monitor.cpu_percent}%` : 'N/A';
                        ram.textContent = data.monitor.ram_percent !== null ? `${data.monitor.ram_percent}%` : 'N/A';
                        ramDetails.textContent = data.monitor.ram_used_gb !== null && data.monitor.ram_total_gb !== null
                            ? `${data.monitor.ram_used_gb} / ${data.monitor.ram_total_gb} GB`
                            : 'N/A';

                        disk.textContent = data.monitor.disk_percent !== null ? `${data.monitor.disk_percent}%` : 'N/A';
                        diskDetails.textContent = data.monitor.disk_used_gb !== null && data.monitor.disk_total_gb !== null
                            ? `${data.monitor.disk_used_gb} / ${data.monitor.disk_total_gb} GB`
                            : 'N/A';

                        activeSessions.textContent = data.active_sessions;
                        inProgress.textContent = data.in_progress;
                        updated.textContent = `Updated: ${data.timestamp}`;
                    } catch (error) {
                        // Keep current values on network failure
                    }
                }

                setInterval(refreshMonitoring, 8000);
                refreshMonitoring();
            })();
        </script>
    @endif
</x-app-layout>
