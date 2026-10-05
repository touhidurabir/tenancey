<?php

namespace App\Http\Controllers\Central;

use App\Enums\AuditAction;
use App\Enums\TenantState;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTenantRequest;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Services\TenantProvisioner;
use App\Services\TenantTeardown;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TenantController extends Controller
{
    public function index(): View
    {
        // Deleted tenants are listed too: their soft-deleted rows are the record.
        $tenants = Tenant::withTrashed()->orderByDesc('id')->get();

        return view('central.tenants.index', [
            'tenants' => $tenants,
            'inProgress' => $tenants->contains(fn (Tenant $tenant) => $tenant->state->isInProgress()),
        ]);
    }

    public function create(): View
    {
        return view('central.tenants.create');
    }

    public function store(StoreTenantRequest $request, TenantProvisioner $provisioner): RedirectResponse
    {
        $data = $request->validated();
        $tenant = $provisioner->provision($data);

        AuditLog::record(AuditAction::TenantCreated, $request->user('central'), $tenant, null, [
            'subdomain' => $tenant->subdomain,
            'admin_email' => $data['admin_email'],
            'admin_must_change_password' => $data['admin_must_change_password'],
            'dedicated_db_user' => $tenant->hasDedicatedDatabaseUser(),
            'db_username' => $tenant->db_username,
            'db_master_access' => $tenant->db_master_access,
        ]);

        return redirect()->route('central.tenants.show', $tenant)
            ->with('status', "Provisioning {$tenant->name}. Horizon must be running to process the queue.");
    }

    public function show(Tenant $tenant, TenantProvisioner $provisioner): View
    {
        $tearingDown = in_array($tenant->state, [TenantState::Deleting, TenantState::Deleted], true);

        return view('central.tenants.show', [
            'tenant' => $tenant,
            'steps' => $tearingDown ? TenantTeardown::STEPS : TenantProvisioner::STEPS,
            'canRetry' => $provisioner->canRetry($tenant),
            'auditLogs' => AuditLog::query()->where('tenant_id', $tenant->id)->latest('id')->limit(10)->get(),
        ]);
    }

    public function edit(Tenant $tenant): View
    {
        return view('central.tenants.edit', ['tenant' => $tenant]);
    }

    public function update(Request $request, Tenant $tenant): RedirectResponse
    {
        $validated = $request->validate(['name' => ['required', 'string', 'max:100']]);

        $from = $tenant->name;
        $tenant->update($validated);

        if ($from !== $tenant->name) {
            AuditLog::record(AuditAction::TenantRenamed, $request->user('central'), $tenant, null, ['from' => $from, 'to' => $tenant->name]);
        }

        return redirect()->route('central.tenants.show', $tenant)->with('status', 'Tenant renamed.');
    }

    /**
     * Switch the tenant on or off. Independent of its lifecycle state.
     */
    public function toggle(Request $request, Tenant $tenant): RedirectResponse
    {
        $tenant->forceFill(['enabled' => ! $tenant->enabled])->save();

        AuditLog::record($tenant->enabled ? AuditAction::TenantEnabled : AuditAction::TenantDisabled, $request->user('central'), $tenant);

        return back()->with('status', $tenant->enabled ? 'Tenant enabled.' : 'Tenant disabled.');
    }

    public function retry(Request $request, Tenant $tenant, TenantProvisioner $provisioner): RedirectResponse
    {
        abort_unless($provisioner->canRetry($tenant), 409, 'Nothing to retry.');

        $previousState = $tenant->state;
        $provisioner->retry($tenant);

        AuditLog::record(AuditAction::TenantRetried, $request->user('central'), $tenant, null, ['from_state' => $previousState->label()]);

        return redirect()->route('central.tenants.show', $tenant)->with('status', 'Retrying provisioning.');
    }

    public function destroy(Request $request, Tenant $tenant, TenantTeardown $teardown): RedirectResponse
    {
        $request->validate(
            ['confirm_subdomain' => ['required', 'in:'.$tenant->subdomain]],
            ['confirm_subdomain.in' => 'Type the subdomain exactly to confirm.'],
        );

        abort_if($tenant->state === TenantState::Provisioning, 409, 'Wait for provisioning to finish or fail first.');

        $previousState = $tenant->state;
        $teardown->teardown($tenant);

        AuditLog::record(AuditAction::TenantDeleteRequested, $request->user('central'), $tenant, null, ['from_state' => $previousState->label()]);

        return redirect()->route('central.tenants.show', $tenant)->with('status', "Deleting {$tenant->name}.");
    }
}
