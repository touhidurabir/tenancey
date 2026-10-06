/**
 * Live /tenants list (central/tenants/index).
 *
 * Subscribes to the shared private `tenants` channel. Every TenantProvisioningUpdated and
 * TenantTeardownUpdated is also sent there (see their broadcastOn()), so this page hears every
 * tenant's progress on one channel, including tenants created after the page loaded.
 */

const list = document.querySelector('[data-tenant-list]');

if (list && window.Echo) {
    const rows = list.querySelector('[data-tenant-rows]');
    const template = list.querySelector('[data-tenant-row-template]');

    /**
     * The row for this tenant. A tenant the page has not seen yet gets a new row from the
     * <template>, added at the top (the list is newest first).
     */
    const rowFor = (uuid) => {
        let row = rows.querySelector(`[data-tenant-row="${uuid}"]`);

        if (!row) {
            row = template.content.firstElementChild.cloneNode(true);
            row.dataset.tenantRow = uuid;
            rows.prepend(row);

            // The first tenant: swap "No tenants yet." for the table.
            list.querySelector('[data-tenant-list-empty]').classList.add('hidden');
            list.querySelector('[data-tenant-list-table]').classList.remove('hidden');
        }

        return row;
    };

    /**
     * @param {{uuid: string, name: string, host: string, show_url: string, login_url: string, enabled: boolean, state: string, state_description: string, badge_classes: string, current_step: string|null}} event
     */
    const update = (event) => {
        const row = rowFor(event.uuid);
        const deleted = event.state === 'Deleted'; // the teardown's last step soft deletes the row

        const name = row.querySelector('[data-tenant-name]');
        name.textContent = event.name;
        name.href = event.show_url;

        // A deleted tenant's host is plain text: its subdomain no longer serves anything.
        const host = row.querySelector('[data-tenant-host]');
        if (deleted) {
            host.textContent = event.host;
        } else {
            host.innerHTML = '';
            const link = Object.assign(document.createElement('a'), {
                href: event.login_url,
                target: '_blank',
                className: 'text-gray-700 hover:underline',
                textContent: event.host,
            });
            host.append(link);
        }

        row.querySelector('[data-tenant-enabled]').textContent = event.enabled ? 'Yes' : 'No';

        const state = row.querySelector('[data-tenant-state]');
        state.textContent = event.state;
        state.title = event.state_description;
        state.className = `rounded-full px-2 py-0.5 text-xs font-medium ${event.badge_classes}`;

        row.querySelector('[data-tenant-step]').textContent = event.current_step ?? '';
        row.classList.toggle('text-gray-400', deleted);
    };

    // private-tenants, authorized by routes/channels.php. Same event name as the tenant page.
    window.Echo.private('tenants').listen('.progress.updated', update);
}
