@php use App\Enums\AuditAction; @endphp
<x-layouts.central title="Audit log">
    <div class="mb-6 flex items-center justify-between">
        <h1 class="text-2xl font-semibold">Audit log</h1>
        <a href="{{ route('log-viewer.index', array_filter(['query' => $filterTenant?->uuid])) }}" target="_blank" class="text-sm text-indigo-700 hover:underline">Open log viewer</a>
    </div>

    <form method="GET" class="mb-6 flex flex-wrap items-end gap-3 text-sm">
        <label class="flex flex-col gap-1">
            <span class="text-gray-600">Tenant</span>
            <select name="tenant" class="rounded-md border border-gray-300 px-3 py-2">
                <option value="">All</option>
                @foreach ($tenants as $tenant)
                    <option value="{{ $tenant->uuid }}" @selected(($filters['tenant'] ?? null) === $tenant->uuid)>{{ $tenant->name }} ({{ $tenant->subdomain }})</option>
                @endforeach
            </select>
        </label>
        <label class="flex flex-col gap-1">
            <span class="text-gray-600">Action</span>
            <select name="action" class="rounded-md border border-gray-300 px-3 py-2">
                <option value="">All</option>
                @foreach (AuditAction::cases() as $action)
                    <option value="{{ $action->value }}" @selected(($filters['action'] ?? null) === $action->value)>{{ $action->label() }}</option>
                @endforeach
            </select>
        </label>
        <label class="flex flex-col gap-1">
            <span class="text-gray-600">Admin email</span>
            <input name="actor" value="{{ $filters['actor'] ?? '' }}" class="rounded-md border border-gray-300 px-3 py-2">
        </label>
        <x-button>Filter</x-button>
        <a href="{{ route('central.audit.index') }}" class="px-2 py-2 text-gray-600 hover:underline">Reset</a>
    </form>

    <section class="rounded-lg border border-gray-200 bg-white p-6">
        <x-audit-table :logs="$logs" />
        <div class="mt-4">{{ $logs->links() }}</div>
    </section>
</x-layouts.central>
