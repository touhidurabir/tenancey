<?php

namespace App\Http\Controllers\Central;

use App\Enums\AuditAction;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AuditLogController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'tenant' => ['nullable', 'uuid'],
            'action' => ['nullable', Rule::enum(AuditAction::class)],
            'actor' => ['nullable', 'string', 'max:255'],
        ]);

        $tenant = isset($filters['tenant']) ? Tenant::withTrashed()->where('uuid', $filters['tenant'])->first() : null;

        $logs = AuditLog::query()
            ->with('tenant')
            ->when(isset($filters['tenant']), fn ($query) => $query->where('tenant_id', $tenant?->id ?? 0))
            ->when(isset($filters['action']), fn ($query) => $query->where('action', $filters['action']))
            ->when(isset($filters['actor']), fn ($query) => $query->where('actor_email', 'like', '%'.$filters['actor'].'%'))
            ->latest('id')
            ->paginate(50)
            ->withQueryString();

        return view('central.audit.index', [
            'logs' => $logs,
            'filters' => $filters,
            'filterTenant' => $tenant,
            'tenants' => Tenant::withTrashed()->orderBy('name')->get(['id', 'uuid', 'name', 'subdomain']),
        ]);
    }
}
