<x-layouts.tenant title="Change password">
    <div class="mx-auto max-w-sm rounded-lg border border-gray-200 bg-white p-6 shadow-sm">
        <h1 class="text-xl font-semibold">Choose a new password</h1>
        @if (auth()->user()->must_change_password)
            <p class="mt-2 text-sm text-gray-600">You signed in with a temporary password. Please choose your own to continue.</p>
        @endif
        <form method="POST" action="{{ route('password.change') }}" class="mt-6 space-y-4">
            @csrf
            @method('PUT')
            <x-field name="current_password" label="Current password" type="password" required />
            <x-field name="password" label="New password" type="password" required />
            <x-field name="password_confirmation" label="Confirm new password" type="password" required />
            <x-button class="w-full justify-center">Update password</x-button>
        </form>
    </div>
</x-layouts.tenant>
