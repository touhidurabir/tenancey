<x-mail::message>
# Welcome to {{ $tenant->name }}

Hi {{ $admin['name'] }}, your workspace is ready. Sign in with these details:

<x-mail::panel>
**Address:** {{ $loginUrl }}<br>
**Email:** {{ $admin['email'] }}<br>
**Password:** {{ $admin['password'] }}
</x-mail::panel>

@if ($admin['must_change_password'] ?? true)
You will be asked to choose a new password the first time you sign in.
@else
We recommend changing this password after you sign in.
@endif

<x-mail::button :url="$loginUrl">
Sign in
</x-mail::button>

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
