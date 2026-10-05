@props(['name', 'label', 'checked' => false, 'hint' => null, 'id' => null])
@php($id ??= $name)
<div>
    {{-- Hidden 0 first: an unticked box sends nothing, so the request would fall back to the default. --}}
    <input type="hidden" name="{{ $name }}" value="0">
    <label for="{{ $id }}" class="flex items-start gap-2 text-sm text-gray-700">
        <input type="checkbox" id="{{ $id }}" name="{{ $name }}" value="1" @checked(old($name, $checked))
            {{ $attributes->merge(['class' => 'mt-0.5 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500']) }}>
        <span>
            <span class="font-medium">{{ $label }}</span>
            @if ($hint)
                <span class="block text-xs text-gray-500">{{ $hint }}</span>
            @endif
        </span>
    </label>
    @error($name)
        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
    @enderror
</div>
