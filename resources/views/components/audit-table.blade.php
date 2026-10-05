@props(['logs', 'showTenant' => true])
@if ($logs->isEmpty())
    <p class="text-sm text-gray-500">No entries.</p>
@else
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="text-left text-gray-600">
                <tr>
                    <th class="py-2 pr-4 font-medium">When</th>
                    <th class="py-2 pr-4 font-medium">Action</th>
                    <th class="py-2 pr-4 font-medium">Admin</th>
                    @if ($showTenant)
                        <th class="py-2 pr-4 font-medium">Tenant</th>
                    @endif
                    <th class="py-2 pr-4 font-medium">Subject</th>
                    <th class="py-2 pr-4 font-medium">Details</th>
                    <th class="py-2 font-medium">IP</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 align-top">
                @foreach ($logs as $log)
                    <tr>
                        <td class="py-2 pr-4 whitespace-nowrap text-gray-600" title="{{ $log->created_at->toIso8601String() }}">{{ $log->created_at->format('Y-m-d H:i:s') }}</td>
                        <td @class(['py-2 pr-4 whitespace-nowrap font-medium', 'text-red-700' => $log->action->isWarning()])>{{ $log->action->label() }}</td>
                        <td class="py-2 pr-4">{{ $log->actor_email ?? '—' }}</td>
                        @if ($showTenant)
                            <td class="py-2 pr-4">
                                @if ($log->tenant)
                                    <a href="{{ route('central.tenants.show', $log->tenant) }}" class="text-indigo-700 hover:underline">{{ $log->tenant->subdomain }}</a>
                                @else
                                    —
                                @endif
                            </td>
                        @endif
                        <td class="py-2 pr-4">{{ $log->subject_label ?? $log->subject_id ?? '—' }}</td>
                        <td class="py-2 pr-4 font-mono text-xs break-all text-gray-600">
                            @foreach ($log->metadata ?? [] as $key => $value)
                                <div>{{ $key }}: {{ is_scalar($value) || $value === null ? var_export($value, true) : json_encode($value) }}</div>
                            @endforeach
                        </td>
                        <td class="py-2 font-mono text-xs text-gray-600">{{ $log->ip_address ?? '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
