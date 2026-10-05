@props(['variant' => 'primary'])
@php
    $classes = match ($variant) {
        'danger' => 'bg-red-600 text-white hover:bg-red-700',
        'secondary' => 'border border-gray-300 bg-white text-gray-700 hover:bg-gray-50',
        default => 'bg-indigo-600 text-white hover:bg-indigo-700',
    };
@endphp
<button {{ $attributes->merge(['type' => 'submit', 'class' => "inline-flex items-center rounded-md px-4 py-2 text-sm font-medium shadow-sm {$classes}"]) }}>
    {{ $slot }}
</button>
