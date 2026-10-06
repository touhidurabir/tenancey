/**
 * Live provisioning / teardown progress on the central tenant page (central/tenants/show).
 *
 * Plain JS on purpose: the only dependency is window.Echo (resources/js/echo.js), so the same
 * subscribe-and-listen code carries over to the future React frontend.
 *
 * Flow:
 *   1. A step job saves the tenant (App\Models\Tenant::saved hook).
 *   2. Laravel sends TenantProvisioningUpdated or TenantTeardownUpdated to Reverb.
 *   3. Reverb pushes it to every browser subscribed to that private channel.
 *   4. update() below redraws the step list from the event's payload.
 */

const section = document.querySelector('[data-tenant-progress]');

if (section && window.Echo) {
    const { uuid, channel } = section.dataset; // channel: "provisioning" or "teardown"
    const steps = [...section.querySelectorAll('[data-step]')];
    const badge = document.querySelector('[data-progress-badge]');
    const connection = section.querySelector('[data-progress-connection]');

    // The same icons and classes the Blade view renders on page load.
    const icons = { done: '✓', running: '…', failed: '✗', waiting: '·' };
    const classes = {
        done: ['text-gray-900'],
        running: ['font-medium', 'text-blue-700'],
        failed: ['font-medium', 'text-red-700'],
        waiting: ['text-gray-400'],
    };

    /**
     * @param {{uuid: string, state: string, badge_classes: string, current_step: string|null, last_error: string|null}} event
     */
    const update = (event) => {
        // The chain has ended (finished, or failed for good). Reload to get the full server
        // render: the error box, the action buttons and the resources all change at this point.
        if (event.current_step === null || event.last_error) {
            window.location.reload();
            return;
        }

        const currentIndex = steps.findIndex((li) => li.dataset.step === event.current_step);

        steps.forEach((li, index) => {
            const state = index < currentIndex ? 'done' : index === currentIndex ? 'running' : 'waiting';
            const label = li.querySelector('[data-step-label]');

            li.querySelector('[data-step-icon]').textContent = icons[state];
            label.classList.remove(...Object.values(classes).flat());
            label.classList.add(...classes[state]);
        });

        // The state can change mid-run: "Mark tenant ready" flips it to Ready before the email step.
        badge.textContent = event.state;
        badge.className = `rounded-full px-3 py-1 text-sm font-medium ${event.badge_classes}`;
    };

    // private-tenants.{uuid}.provisioning or private-tenants.{uuid}.teardown, authorized by
    // routes/channels.php. The leading dot in '.progress.updated' means "use this exact event
    // name" (set by broadcastAs()), instead of Echo prefixing it with App\Events\.
    window.Echo.private(`tenants.${uuid}.${channel}`)
        .listen('.progress.updated', update)
        .error(() => (connection.textContent = 'not authorized for this channel'));

    // Show the WebSocket state (connecting, connected, unavailable, ...) so it is obvious when
    // Reverb is not running.
    window.Echo.connector.pusher.connection.bind('state_change', ({ current }) => (connection.textContent = current));
}
