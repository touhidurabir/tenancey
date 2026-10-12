<?php

namespace App\Tenancy\Storage;

use Aws\S3\S3Client;
use DateTimeInterface;

/**
 * Signs temporary links for the address the BROWSER uses, when it differs from the one PHP uses
 * (Docker: PHP reaches MinIO as minio:9000, the browser as localhost:19010). A presigned URL's
 * signature covers the host, so the host can't be swapped after signing (Laravel's
 * `temporary_url` option does that, and breaks it). Signing is offline: this client never connects.
 */
class S3PublicUrlSigner
{
    /**
     * @param  array<string, mixed>  $disk  the disk's config, with `public_endpoint` and the tenant's `bucket`
     * @param  array<string, mixed>  $options  GetObject parameters, e.g. ResponseContentDisposition
     */
    public static function temporaryUrl(array $disk, string $path, DateTimeInterface $expiration, array $options = []): string
    {
        $client = new S3Client([
            'version' => 'latest',
            'region' => $disk['region'],
            'endpoint' => $disk['public_endpoint'],
            'use_path_style_endpoint' => $disk['use_path_style_endpoint'] ?? false,
            'credentials' => ['key' => $disk['key'], 'secret' => $disk['secret']],
        ]);

        $command = $client->getCommand('GetObject', [
            'Bucket' => $disk['bucket'],
            'Key' => ltrim($path, '/'),
            ...$options,
        ]);

        return (string) $client->createPresignedRequest($command, $expiration)->getUri();
    }
}
