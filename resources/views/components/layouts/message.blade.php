@props(['title'])
{{-- Standalone page for tenant states that never reach the app (unknown, provisioning, unavailable). --}}
<x-layouts.base :title="$title">
    <main class="mx-auto mt-24 max-w-md px-4 text-center">
        <h1 class="text-2xl font-semibold">{{ $title }}</h1>
        <p class="mt-3 text-gray-600">{{ $slot }}</p>
    </main>
</x-layouts.base>
