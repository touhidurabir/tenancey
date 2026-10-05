<x-layouts.central title="Edit {{ $tenant->name }}">
    <h1 class="mb-6 text-2xl font-semibold">Edit {{ $tenant->name }}</h1>

    <form method="POST" action="{{ route('central.tenants.update', $tenant) }}" class="max-w-lg space-y-4 rounded-lg border border-gray-200 bg-white p-6">
        @csrf
        @method('PUT')
        <x-field name="name" label="Tenant name" :value="$tenant->name" required />
        <p class="text-sm text-gray-500">The subdomain <span class="font-mono">{{ $tenant->subdomain }}</span> is fixed.</p>
        <div class="flex gap-3">
            <x-button>Save</x-button>
            <a href="{{ route('central.tenants.show', $tenant) }}" class="px-4 py-2 text-sm text-gray-600 hover:underline">Cancel</a>
        </div>
    </form>
</x-layouts.central>
