<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">User Manager</h2>
            <a href="{{ route('users.create') }}" class="inline-flex items-center rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">
                Add User
            </a>
        </div>
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

            <div class="rounded-2xl bg-white shadow-sm ring-1 ring-slate-200 p-6">
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-slate-50 text-slate-500 uppercase text-xs tracking-wide">
                            <tr>
                                <th class="px-4 py-3 text-left">Name</th>
                                <th class="px-4 py-3 text-left">Email</th>
                                <th class="px-4 py-3 text-left">Role</th>
                                <th class="px-4 py-3 text-right">Quota</th>
                                <th class="px-4 py-3 text-right">Used</th>
                                <th class="px-4 py-3 text-left">Permissions</th>
                                <th class="px-4 py-3 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @forelse ($users as $managedUser)
                                <tr>
                                    <td class="px-4 py-3 font-semibold text-slate-900">{{ $managedUser->name }}</td>
                                    <td class="px-4 py-3 text-slate-600">{{ $managedUser->email }}</td>
                                    <td class="px-4 py-3">
                                        <span class="inline-flex rounded-full px-2 py-1 text-xs font-semibold {{ $managedUser->role === 'admin' ? 'bg-indigo-100 text-indigo-700' : 'bg-slate-100 text-slate-700' }}">
                                            {{ strtoupper($managedUser->role) }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 text-right text-slate-700">{{ $managedUser->quota_mb > 0 ? number_format($managedUser->quota_mb / 1024, 2) . ' GB' : 'Unlimited' }}</td>
                                    <td class="px-4 py-3 text-right text-slate-700">{{ number_format($managedUser->used_space_bytes / 1024 / 1024 / 1024, 2) }} GB</td>
                                    <td class="px-4 py-3 text-xs text-slate-600">
                                        {{ $managedUser->can_upload ? 'Upload ' : '' }}
                                        {{ $managedUser->can_view_monitoring ? '/ Monitoring ' : '' }}
                                        {{ $managedUser->can_manage_users ? '/ Manage Users' : '' }}
                                    </td>
                                    <td class="px-4 py-3 text-right">
                                        <div class="inline-flex items-center gap-2">
                                            <a href="{{ route('users.edit', $managedUser) }}" class="rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-100">Edit</a>
                                            @if (auth()->id() !== $managedUser->id)
                                                <form method="POST" action="{{ route('users.destroy', $managedUser) }}" onsubmit="return confirm('Delete this user?')">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button class="rounded-lg border border-rose-300 px-3 py-1.5 text-xs font-semibold text-rose-700 hover:bg-rose-50">Delete</button>
                                                </form>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="px-4 py-6 text-center text-slate-500">No users found.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <div class="mt-5">
                    {{ $users->links() }}
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
