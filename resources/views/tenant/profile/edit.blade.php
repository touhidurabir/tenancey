<x-layouts.tenant title="Profile">
    <h1 class="text-2xl font-semibold">Profile</h1>

    <section class="mt-6 rounded-lg border border-gray-200 bg-white p-6">
        <h2 class="font-semibold">Avatar</h2>
        <div class="mt-4 flex items-center gap-6">
            @if ($thumb = $user->getFirstTemporaryUrl(now()->addMinutes(5), 'avatar', 'thumb'))
                <img src="{{ $thumb }}" alt="Your avatar" class="h-16 w-16 rounded-full object-cover">
            @else
                <div class="flex h-16 w-16 items-center justify-center rounded-full bg-indigo-100 text-xl font-semibold text-indigo-700">{{ mb_substr($user->name, 0, 1) }}</div>
            @endif

            <form method="POST" action="{{ route('profile.avatar') }}" enctype="multipart/form-data" class="flex items-end gap-3">
                @csrf
                @method('PUT')
                <x-field name="avatar" label="New avatar" type="file" accept="image/jpeg,image/png,image/webp" hint="JPEG, PNG or WebP, up to 2 MB." required />
                <x-button>Upload</x-button>
            </form>
        </div>

        @if ($user->getFirstMedia('avatar'))
            <form method="POST" action="{{ route('profile.avatar') }}" class="mt-4">
                @csrf
                @method('DELETE')
                <x-button variant="secondary">Remove avatar</x-button>
            </form>
        @endif
    </section>
</x-layouts.tenant>
