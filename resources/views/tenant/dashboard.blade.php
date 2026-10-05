<x-layouts.tenant title="Dashboard">
    <h1 class="text-2xl font-semibold">Welcome, {{ auth()->user()->name }}</h1>
    <p class="mt-2 text-gray-600">You are signed in to <strong>{{ $tenant->name }}</strong>.</p>

    <dl class="mt-8 grid gap-4 sm:grid-cols-4">
        <div class="rounded-lg border border-gray-200 bg-white p-4">
            <dt class="text-xs uppercase text-gray-500">Tenant</dt>
            <dd class="mt-1 font-mono text-sm">{{ $tenant->subdomain }}</dd>
        </div>
        <div class="rounded-lg border border-gray-200 bg-white p-4">
            <dt class="text-xs uppercase text-gray-500">Database</dt>
            <dd class="mt-1 font-mono text-sm">{{ DB::connection()->getDatabaseName() }}</dd>
        </div>
        <div class="rounded-lg border border-gray-200 bg-white p-4">
            <dt class="text-xs uppercase text-gray-500">Your roles</dt>
            <dd class="mt-1 font-mono text-sm">{{ auth()->user()->getRoleNames()->join(', ') ?: '—' }}</dd>
        </div>
        <div class="rounded-lg border border-gray-200 bg-white p-4">
            <dt class="text-xs uppercase text-gray-500">Users</dt>
            <dd class="mt-1 font-mono text-sm">{{ $userCount }}</dd>
        </div>
    </dl>
</x-layouts.tenant>
