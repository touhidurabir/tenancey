<x-layouts.central title="Tenants">
    <div class="mb-6 flex items-center justify-between">
        <h1 class="text-2xl font-semibold">Tenants</h1>
        <a href="{{ route('central.tenants.create') }}" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">New tenant</a>
    </div>

    {{-- resources/js/tenant-list.js keeps this table live over the private `tenants` channel. --}}
    <div data-tenant-list>
        <p data-tenant-list-empty @class(['rounded-lg border border-dashed border-gray-300 p-8 text-center text-gray-500', 'hidden' => $tenants->isNotEmpty()])>No tenants yet.</p>

        <div data-tenant-list-table @class(['overflow-hidden rounded-lg border border-gray-200 bg-white', 'hidden' => $tenants->isEmpty()])>
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50 text-left text-gray-600">
                    <tr>
                        <th class="px-4 py-3 font-medium">Name</th>
                        <th class="px-4 py-3 font-medium">Subdomain</th>
                        <th class="px-4 py-3 font-medium">Enabled</th>
                        <th class="px-4 py-3 font-medium">State</th>
                        <th class="px-4 py-3 font-medium">Created</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100" data-tenant-rows>
                    @foreach ($tenants as $tenant)
                        <tr data-tenant-row="{{ $tenant->uuid }}" @class(['text-gray-400' => $tenant->trashed()])>
                            <td class="px-4 py-3">
                                <a href="{{ route('central.tenants.show', $tenant) }}" class="font-medium text-indigo-700 hover:underline" data-tenant-name>{{ $tenant->name }}</a>
                            </td>
                            <td class="px-4 py-3" data-tenant-host>
                                @if ($tenant->trashed())
                                    {{ $tenant->host() }}
                                @else
                                    <a href="{{ $tenant->url('/login') }}" class="text-gray-700 hover:underline" target="_blank">{{ $tenant->host() }}</a>
                                @endif
                            </td>
                            <td class="px-4 py-3" data-tenant-enabled>{{ $tenant->enabled ? 'Yes' : 'No' }}</td>
                            <td class="px-4 py-3">
                                <span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $tenant->state->badgeClasses() }}" title="{{ $tenant->state->describe() }}" data-tenant-state>{{ $tenant->state->label() }}</span>
                                <span class="ml-1 text-xs text-gray-500" data-tenant-step>{{ $tenant->current_step }}</span>
                            </td>
                            <td class="px-4 py-3 text-gray-500">{{ $tenant->created_at->diffForHumans() }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        {{-- An empty row with the same markup. The JS clones and fills it when a tenant it has not seen yet is created. --}}
        <template data-tenant-row-template>
            <tr data-tenant-row="">
                <td class="px-4 py-3">
                    <a href="" class="font-medium text-indigo-700 hover:underline" data-tenant-name></a>
                </td>
                <td class="px-4 py-3" data-tenant-host></td>
                <td class="px-4 py-3" data-tenant-enabled></td>
                <td class="px-4 py-3">
                    <span class="rounded-full px-2 py-0.5 text-xs font-medium" data-tenant-state></span>
                    <span class="ml-1 text-xs text-gray-500" data-tenant-step></span>
                </td>
                <td class="px-4 py-3 text-gray-500">just now</td>
            </tr>
        </template>
    </div>
</x-layouts.central>
