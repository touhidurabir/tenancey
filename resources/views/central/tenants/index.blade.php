<x-layouts.central title="Tenants" :refresh="$inProgress ? 3 : null">
    <div class="mb-6 flex items-center justify-between">
        <h1 class="text-2xl font-semibold">Tenants</h1>
        <a href="{{ route('central.tenants.create') }}" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">New tenant</a>
    </div>

    @if ($tenants->isEmpty())
        <p class="rounded-lg border border-dashed border-gray-300 p-8 text-center text-gray-500">No tenants yet.</p>
    @else
        <div class="overflow-hidden rounded-lg border border-gray-200 bg-white">
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
                <tbody class="divide-y divide-gray-100">
                    @foreach ($tenants as $tenant)
                        <tr @class(['text-gray-400' => $tenant->trashed()])>
                            <td class="px-4 py-3">
                                <a href="{{ route('central.tenants.show', $tenant) }}" class="font-medium text-indigo-700 hover:underline">{{ $tenant->name }}</a>
                            </td>
                            <td class="px-4 py-3">
                                @if ($tenant->trashed())
                                    {{ $tenant->host() }}
                                @else
                                    <a href="{{ $tenant->url('/login') }}" class="text-gray-700 hover:underline" target="_blank">{{ $tenant->host() }}</a>
                                @endif
                            </td>
                            <td class="px-4 py-3">{{ $tenant->enabled ? 'Yes' : 'No' }}</td>
                            <td class="px-4 py-3">
                                <span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $tenant->state->badgeClasses() }}" title="{{ $tenant->state->describe() }}">{{ $tenant->state->label() }}</span>
                                @if ($tenant->current_step)
                                    <span class="ml-1 text-xs text-gray-500">{{ $tenant->current_step }}</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-gray-500">{{ $tenant->created_at->diffForHumans() }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</x-layouts.central>
