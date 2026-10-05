@props(['title' => null])
<x-layouts.base :title="$title">
    @if (! empty($impersonator))
        <div class="bg-red-600 text-white">
            <div class="mx-auto flex max-w-3xl items-center justify-between gap-4 px-4 py-2 text-sm">
                <span>
                    You are impersonating <strong>{{ auth()->user()?->email }}</strong> as {{ $impersonator['central_user_email'] }}.
                    Ends automatically at {{ \Illuminate\Support\Carbon::createFromTimestamp($impersonator['expires_at'])->timezone(config('app.timezone'))->format('H:i') }}.
                </span>
                <form method="POST" action="{{ route('impersonate.end') }}">
                    @csrf
                    <button class="rounded bg-white/20 px-3 py-1 font-medium hover:bg-white/30">End impersonation</button>
                </form>
            </div>
        </div>
    @endif
    <header class="border-b border-indigo-100 bg-indigo-50">
        <div class="mx-auto flex max-w-3xl items-center justify-between px-4 py-3">
            <span class="font-semibold">{{ tenant('name') }}</span>
            @auth
                <nav class="flex items-center gap-4 text-sm">
                    <span class="text-gray-600">{{ auth()->user()->email }}</span>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button class="text-gray-600 hover:underline">Log out</button>
                    </form>
                </nav>
            @endauth
        </div>
    </header>

    <main class="mx-auto max-w-3xl px-4 py-8">
        <x-flash />
        {{ $slot }}
    </main>
</x-layouts.base>
