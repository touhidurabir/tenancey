@php
    use App\Enums\TenantState;

    $inProgress = $tenant->state->isInProgress();
    $labels = array_map(fn (string $job) => $job::label(), $steps);
    $currentIndex = $tenant->current_step ? array_search($tenant->current_step, $labels, true) : false;
    $finished = in_array($tenant->state, [TenantState::Ready, TenantState::Deleted], true);
@endphp
<x-layouts.central :title="$tenant->name" :refresh="$inProgress ? 2 : null">
    <div class="mb-6 flex items-start justify-between gap-6">
        <div>
            <h1 class="text-2xl font-semibold">{{ $tenant->name }}</h1>
            @if ($tenant->trashed())
                <p class="text-sm text-gray-500">{{ $tenant->host() }} · deleted {{ $tenant->deleted_at->toDayDateTimeString() }}</p>
            @else
                <a href="{{ $tenant->url('/login') }}" target="_blank" class="text-sm text-indigo-700 hover:underline">{{ $tenant->url('/login') }}</a>
            @endif
        </div>
        <div class="text-right">
            <span class="rounded-full px-3 py-1 text-sm font-medium {{ $tenant->state->badgeClasses() }}">{{ $tenant->state->label() }}</span>
            @unless ($tenant->enabled)
                <span class="ml-1 rounded-full bg-gray-200 px-3 py-1 text-sm font-medium text-gray-700">Disabled</span>
            @endunless
            <p class="mt-2 max-w-xs text-xs text-gray-500">{{ $tenant->state->describe() }}</p>
        </div>
    </div>

    @if ($tenant->last_error)
        <div class="mb-6 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
            <p class="font-medium">Last error</p>
            <p class="mt-1 font-mono text-xs break-all">{{ $tenant->last_error }}</p>
        </div>
    @endif

    <div class="grid gap-6 md:grid-cols-2">
        <section class="rounded-lg border border-gray-200 bg-white p-6">
            <h2 class="mb-4 font-medium">{{ in_array($tenant->state, [TenantState::Deleting, TenantState::Deleted], true) ? 'Teardown' : 'Provisioning' }}</h2>
            <ol class="space-y-2 text-sm">
                @foreach ($labels as $index => $label)
                    @php
                        // No current step: either nothing has started yet, or the chain finished.
                        $state = match (true) {
                            $currentIndex === false => $finished ? 'done' : 'waiting',
                            $index < $currentIndex => 'done',
                            $index === $currentIndex => $tenant->last_error ? 'failed' : 'running',
                            default => 'waiting',
                        };
                    @endphp
                    <li class="flex items-center gap-2">
                        <span class="w-5 text-center">{{ ['done' => '✓', 'running' => '…', 'failed' => '✗', 'waiting' => '·'][$state] }}</span>
                        <span @class([
                            'text-gray-900' => $state === 'done',
                            'font-medium text-blue-700' => $state === 'running',
                            'font-medium text-red-700' => $state === 'failed',
                            'text-gray-400' => $state === 'waiting',
                        ])>{{ $label }}</span>
                    </li>
                @endforeach
            </ol>
            @if ($inProgress)
                <p class="mt-4 text-xs text-gray-500">This page refreshes every 2 seconds. Nothing moving? Horizon is not running (<code>supervisorctl status tenancey-horizon</code>).</p>
            @endif
        </section>

        <section class="rounded-lg border border-gray-200 bg-white p-6">
            <h2 class="mb-4 font-medium">Resources</h2>
            <dl class="space-y-2 text-sm">
                @foreach ([
                    'ID / UUID' => $tenant->id.' / '.$tenant->uuid,
                    'Subdomain' => $tenant->subdomain,
                    'Database' => $tenant->db_name,
                    'Database user' => $tenant->hasDedicatedDatabaseUser()
                        ? $tenant->db_username.($tenant->db_user_created_at ? '' : ' (not created yet)')
                        : 'master (.env)',
                    'Master user access' => $tenant->hasDedicatedDatabaseUser()
                        ? ($tenant->db_master_access ? 'yes (recorded)' : 'no (recorded, not enforced)')
                        : 'yes (it is the connection)',
                    'Storage' => $tenant->storage_path,
                    'Redis prefix' => $tenant->cache_prefix,
                    'Provisioned' => $tenant->provisioned_at?->toDayDateTimeString() ?? '—',
                ] as $term => $value)
                    <div class="flex justify-between gap-4">
                        <dt class="shrink-0 text-gray-500">{{ $term }}</dt>
                        <dd class="text-right font-mono text-xs break-all">{{ $value }}</dd>
                    </div>
                @endforeach
            </dl>
        </section>
    </div>

    <section class="mt-6 rounded-lg border border-gray-200 bg-white p-6">
        <div class="mb-4 flex items-center justify-between">
            <h2 class="font-medium">Recent audit entries</h2>
            <div class="flex gap-4 text-sm">
                <a href="{{ route('central.audit.index', ['tenant' => $tenant->uuid]) }}" class="text-indigo-700 hover:underline">All entries</a>
                <a href="{{ route('log-viewer.index', ['query' => $tenant->uuid]) }}" target="_blank" class="text-indigo-700 hover:underline">Provisioning logs</a>
            </div>
        </div>
        <x-audit-table :logs="$auditLogs" :show-tenant="false" />
    </section>

    @unless ($tenant->trashed())
        <section class="mt-6 flex flex-wrap gap-3">
            @unless ($inProgress)
                <a href="{{ route('central.tenants.edit', $tenant) }}" class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">Rename</a>
            @endunless

            @if ($tenant->state !== TenantState::Deleting)
                <form method="POST" action="{{ route('central.tenants.toggle', $tenant) }}">
                    @csrf
                    <x-button variant="secondary">{{ $tenant->enabled ? 'Disable' : 'Enable' }}</x-button>
                </form>
            @endif

            @if ($tenant->isAccessible())
                <form method="POST" action="{{ route('central.tenants.impersonate', $tenant) }}">
                    @csrf
                    <x-button variant="secondary" title="Sign in to the tenant as its first admin. Audited.">Impersonate admin</x-button>
                </form>
            @endif

            @if ($canRetry)
                <form method="POST" action="{{ route('central.tenants.retry', $tenant) }}">
                    @csrf
                    <x-button>{{ $tenant->state === TenantState::Ready ? 'Resend credentials' : 'Retry provisioning' }}</x-button>
                </form>
            @endif
        </section>

        @if ($tenant->state !== TenantState::Provisioning && ($tenant->state !== TenantState::Deleting || $tenant->last_error))
            <section class="mt-10 rounded-lg border border-red-200 bg-white p-6">
                <h2 class="font-medium text-red-700">{{ $tenant->state === TenantState::Deleting ? 'Retry delete' : 'Delete tenant' }}</h2>
                <p class="mt-1 text-sm text-gray-600">Drops the database <span class="font-mono">{{ $tenant->db_name }}</span>, its storage and cache. The record is kept and the subdomain stays reserved. This cannot be undone.</p>
                <form method="POST" action="{{ route('central.tenants.destroy', $tenant) }}" class="mt-4 flex items-end gap-3">
                    @csrf
                    @method('DELETE')
                    <div class="flex-1">
                        <x-field name="confirm_subdomain" label="Type {{ $tenant->subdomain }} to confirm" autocomplete="off" />
                    </div>
                    <x-button variant="danger">Delete</x-button>
                </form>
            </section>
        @endif
    @endunless
</x-layouts.central>
