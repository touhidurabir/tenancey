<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreFileRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * "My files": each user sees and manages only their own uploads. Downloads are 5-minute signed
 * links straight to the tenant's bucket (S3/MinIO) or to the local file route.
 */
class FileController extends Controller
{
    public function index(Request $request): View
    {
        return view('tenant.files.index', ['files' => $request->user()->getMedia('files')]);
    }

    public function store(StoreFileRequest $request): RedirectResponse
    {
        $media = $request->user()->addMediaFromRequest('file')->toMediaCollection('files');

        Log::channel('tenancy')->info('File uploaded', [...tenant()->logContext(), 'user_id' => $request->user()->id, 'media_id' => $media->id]);

        return redirect()->route('files.index')->with('status', "Uploaded {$media->file_name}.");
    }

    public function download(Request $request, Media $media): RedirectResponse
    {
        $this->ensureOwnedBy($request, $media);

        return redirect()->away($media->getTemporaryUrl(now()->addMinutes(5), '', [
            'ResponseContentDisposition' => 'attachment; filename="'.addslashes($media->file_name).'"',
        ]));
    }

    public function destroy(Request $request, Media $media): RedirectResponse
    {
        $this->ensureOwnedBy($request, $media);

        $media->delete();

        Log::channel('tenancy')->info('File deleted', [...tenant()->logContext(), 'user_id' => $request->user()->id, 'media_id' => $media->id]);

        return redirect()->route('files.index')->with('status', "Deleted {$media->file_name}.");
    }

    /**
     * Someone else's file is "not found", not "forbidden": its existence isn't revealed.
     */
    protected function ensureOwnedBy(Request $request, Media $media): void
    {
        abort_unless(
            $media->collection_name === 'files'
                && $media->model_type === $request->user()->getMorphClass()
                && (int) $media->model_id === $request->user()->id,
            404,
        );
    }
}
