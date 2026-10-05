<x-layouts.central title="New tenant">
    <h1 class="mb-6 text-2xl font-semibold">New tenant</h1>

    <form method="POST" action="{{ route('central.tenants.store') }}" class="space-y-8">
        @csrf

        <section class="space-y-4 rounded-lg border border-gray-200 bg-white p-6">
            <h2 class="font-medium">Tenant</h2>
            <x-field name="name" label="Tenant name" required />
            <div>
                <label for="subdomain" class="block text-sm font-medium text-gray-700">Subdomain</label>
                <div class="mt-1 flex rounded-md shadow-sm">
                    <input id="subdomain" name="subdomain" value="{{ old('subdomain') }}" required
                        class="block w-full rounded-l-md border border-gray-300 px-3 py-2 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500">
                    <span class="inline-flex items-center rounded-r-md border border-l-0 border-gray-300 bg-gray-50 px-3 text-sm text-gray-500">.{{ config('tenancy.central_domains')[0] }}</span>
                </div>
                <p class="mt-1 text-xs text-gray-500">3–63 lowercase letters, numbers or hyphens. Cannot be changed later, and stays reserved after the tenant is deleted.</p>
                @error('subdomain')
                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>
        </section>

        <section class="space-y-4 rounded-lg border border-gray-200 bg-white p-6">
            <h2 class="font-medium">First user</h2>
            <p class="text-sm text-gray-500">Gets the admin role and receives these credentials by email.</p>
            <x-field name="admin_name" label="Name" required />
            <x-field name="admin_email" label="Email" type="email" required />
            <x-field name="admin_phone" label="Phone" type="tel" />
            <x-field name="admin_password" label="Password" type="password" required />
            <x-field name="admin_password_confirmation" label="Confirm password" type="password" required />
            <x-checkbox name="admin_must_change_password" :checked="true"
                label="Must change this password at first sign-in" />
        </section>

        {{-- The fields show while the box is ticked (CSS only: Tailwind's group-has variant). --}}
        <section class="group space-y-4 rounded-lg border border-gray-200 bg-white p-6">
            <h2 class="font-medium">Database access</h2>
            <x-checkbox name="dedicated_db_user" id="dedicated_db_user"
                label="Give this tenant its own database user"
                hint="Unticked: the tenant connects with the master database user from .env." />

            <div class="hidden space-y-4 border-l-2 border-indigo-100 pl-4 group-has-[#dedicated_db_user:checked]:block">
                <x-field name="db_username" label="Database username" autocomplete="off"
                    hint="Optional. Empty: 16 random letters and digits. Otherwise start with a letter; letters, numbers or underscores, up to 32. Must not exist on the database server yet." />
                <x-field name="db_password" label="Database password" type="password" autocomplete="new-password"
                    hint="Optional. Empty: random, 12–24 characters. Stored encrypted (TENANT_DB_KEY)." />
                <x-checkbox name="db_master_access" :checked="true"
                    label="The master database user (.env) may also access this database"
                    hint="Recorded only. The app never grants or revokes anything on the master user." />
            </div>
        </section>

        <div class="flex gap-3">
            <x-button>Create tenant</x-button>
            <a href="{{ route('central.tenants.index') }}" class="px-4 py-2 text-sm text-gray-600 hover:underline">Cancel</a>
        </div>
    </form>
</x-layouts.central>
