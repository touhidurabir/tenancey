<x-layouts.central title="Sign in">
    <div class="mx-auto max-w-sm rounded-lg border border-gray-200 bg-white p-6 shadow-sm">
        <h1 class="mb-6 text-xl font-semibold">Central admin sign in</h1>
        <form method="POST" action="{{ route('central.login.store') }}" class="space-y-4">
            @csrf
            <x-field name="email" label="Email" type="email" required autofocus />
            <x-field name="password" label="Password" type="password" required />
            <label class="flex items-center gap-2 text-sm text-gray-600">
                <input type="checkbox" name="remember"> Remember me
            </label>
            <x-button class="w-full justify-center">Sign in</x-button>
        </form>
    </div>
</x-layouts.central>
