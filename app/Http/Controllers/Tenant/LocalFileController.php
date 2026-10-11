<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves a signed temporary link on the `local` disk: the local stand-in for an S3 presigned URL.
 * Tenancy is initialized, so the disk's root is this tenant's bucket folder.
 */
class LocalFileController extends Controller
{
    public function __invoke(string $path): StreamedResponse
    {
        $disk = Storage::disk(tenant('storage_disk'));

        abort_unless(config('filesystems.disks.'.tenant('storage_disk').'.driver') === 'local' && $disk->exists($path), 404);

        return $disk->download($path, basename($path), ['X-Content-Type-Options' => 'nosniff']);
    }
}
