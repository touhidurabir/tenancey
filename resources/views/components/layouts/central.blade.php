@props(['title' => null, 'refresh' => null])
<x-layouts.base :title="$title" :refresh="$refresh">
    <header class="border-b border-gray-200 bg-white">
        <div class="mx-auto flex max-w-5xl items-center justify-between px-4 py-3">
            <a href="{{ route('central.tenants.index') }}" class="font-semibold">
                {{ config('app.name') }} <span class="font-normal text-gray-500">· Central admin</span>
            </a>
            @auth('central')
                <nav class="flex items-center gap-4 text-sm">
                    <a href="{{ route('central.tenants.index') }}" class="hover:underline">Tenants</a>
                    <a href="{{ route('central.audit.index') }}" class="hover:underline">Audit log</a>
                    <a href="{{ url('/horizon') }}" class="hover:underline" target="_blank">Horizon</a>
                    <a href="{{ route('log-viewer.index') }}" class="hover:underline" target="_blank">Logs</a>
                    <span class="text-gray-500">{{ auth('central')->user()->email }}</span>
                    <form method="POST" action="{{ route('central.logout') }}">
                        @csrf
                        <button class="text-gray-600 hover:underline">Log out</button>
                    </form>
                </nav>
            @endauth
        </div>
    </header>

    <main class="mx-auto max-w-5xl px-4 py-8">
        <x-flash />
        {{ $slot }}
    </main>
</x-layouts.base>
