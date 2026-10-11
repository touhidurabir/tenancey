<x-layouts.tenant title="My files">
    <h1 class="text-2xl font-semibold">My files</h1>

    <form method="POST" action="{{ route('files.store') }}" enctype="multipart/form-data" class="mt-6 flex items-end gap-3 rounded-lg border border-gray-200 bg-white p-6">
        @csrf
        <x-field name="file" label="Upload a file" type="file" hint="Any type, up to 10 MB." required />
        <x-button>Upload</x-button>
    </form>

    <div class="mt-6 overflow-hidden rounded-lg border border-gray-200 bg-white">
        @forelse ($files as $file)
            <div class="flex items-center justify-between gap-4 border-b border-gray-100 px-4 py-3 last:border-0">
                <div>
                    <a href="{{ route('files.download', $file) }}" class="font-medium text-indigo-700 hover:underline">{{ $file->file_name }}</a>
                    <p class="text-xs text-gray-500">{{ $file->human_readable_size }} · {{ $file->created_at->diffForHumans() }}</p>
                </div>
                <form method="POST" action="{{ route('files.destroy', $file) }}">
                    @csrf
                    @method('DELETE')
                    <x-button variant="secondary">Delete</x-button>
                </form>
            </div>
        @empty
            <p class="px-4 py-6 text-sm text-gray-500">No files yet.</p>
        @endforelse
    </div>
</x-layouts.tenant>
