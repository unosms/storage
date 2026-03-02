<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Create User</h2>
    </x-slot>

    <div class="py-10">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8">
            <div class="rounded-2xl bg-white shadow-sm ring-1 ring-slate-200 p-6">
                <form method="POST" action="{{ route('users.store') }}">
                    @csrf

                    @include('users._form')

                    <div class="mt-8 flex items-center gap-3">
                        <x-primary-button>Save User</x-primary-button>
                        <a href="{{ route('users.index') }}" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-100">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
