<?php

namespace App\Http\Requests;

use App\Models\Tenant;
use App\Tenancy\Database\DatabaseCredentials;
use App\Tenancy\Database\DatabaseUserManager;
use App\Tenancy\TenantResourceGuard;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StoreTenantRequest extends FormRequest
{
    /**
     * Subdomains that must never become tenants.
     *
     * @var list<string>
     */
    public const RESERVED_SUBDOMAINS = [
        'www', 'admin', 'api', 'mail', 'app', 'central', 'tenancey', 'static', 'assets', 'horizon',
    ];

    protected function prepareForValidation(): void
    {
        $this->merge([
            'subdomain' => strtolower(trim((string) $this->input('subdomain'))),
            'db_username' => filled($this->input('db_username')) ? strtolower(trim((string) $this->input('db_username'))) : null,
            // Checkboxes: absent when unticked. The forms send a hidden 0 before each.
            'admin_must_change_password' => $this->boolean('admin_must_change_password', true),
            'dedicated_db_user' => $this->boolean('dedicated_db_user'),
            'db_master_access' => $this->boolean('db_master_access', true),
        ]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'subdomain' => [
                'required',
                'string',
                'min:3',
                'max:63', // DNS label limit
                'regex:/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/',
                Rule::notIn(self::RESERVED_SUBDOMAINS),
                // Raw query, so soft-deleted tenants count too: their subdomains stay reserved.
                Rule::unique(Tenant::class, 'subdomain'),
            ],
            'admin_name' => ['required', 'string', 'max:255'],
            'admin_email' => ['required', 'string', 'email', 'max:255'],
            'admin_phone' => ['nullable', 'string', 'max:30'],
            'admin_password' => ['required', 'confirmed', Password::defaults()],
            'admin_must_change_password' => ['boolean'],

            // Dedicated database user. Both fields optional: empty ones are generated.
            'dedicated_db_user' => ['boolean'],
            'db_username' => [
                'exclude_unless:dedicated_db_user,true',
                'nullable',
                'string',
                'regex:'.TenantResourceGuard::USERNAME_PATTERN,
                // Raw query, so deleted tenants count too.
                Rule::unique(Tenant::class, 'db_username'),
                function (string $attribute, string $value, Closure $fail) {
                    if (TenantResourceGuard::isReservedUsername($value)) {
                        $fail('That database username is reserved.');
                    } elseif (app(DatabaseUserManager::class)->userExists($value)) {
                        // The server is shared: never adopt (and later drop) someone else's user.
                        $fail('A database user with that name already exists on the server.');
                    }
                },
            ],
            'db_password' => [
                'exclude_unless:dedicated_db_user,true',
                'nullable',
                'string',
                'min:'.DatabaseCredentials::PASSWORD_MIN_LENGTH,
                'max:64',
            ],
            'db_master_access' => ['exclude_unless:dedicated_db_user,true', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'subdomain.regex' => 'Use lowercase letters, numbers and hyphens, not starting or ending with a hyphen.',
            'subdomain.not_in' => 'That subdomain is reserved.',
            'subdomain.unique' => 'That subdomain is already taken (deleted tenants keep theirs).',
            'db_username.regex' => 'Start with a letter; then letters, numbers or underscores, 3–32 characters in total.',
            'db_username.unique' => 'Another tenant already uses (or used) that database username.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'admin_name' => 'user name',
            'admin_email' => 'user email',
            'admin_phone' => 'user phone',
            'admin_password' => 'password',
            'db_username' => 'database username',
            'db_password' => 'database password',
        ];
    }
}
