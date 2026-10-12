<?php

namespace App\Tenancy\Storage;

use Illuminate\Filesystem\AwsS3V3Adapter;

/**
 * An S3/MinIO disk whose temporary links are signed for its `public_endpoint` (S3PublicUrlSigner)
 * while everything else keeps using `endpoint`. A subclass, because AwsS3V3Adapter::temporaryUrl()
 * ignores buildTemporaryUrlsUsing() callbacks. Installed by TenantBucketBootstrapper.
 */
class PublicLinkS3Adapter extends AwsS3V3Adapter
{
    public static function from(AwsS3V3Adapter $disk): self
    {
        return new self($disk->getDriver(), $disk->getAdapter(), $disk->getConfig(), $disk->getClient());
    }

    /**
     * @param  string  $path
     * @param  \DateTimeInterface  $expiration
     * @param  array<string, mixed>  $options
     */
    public function temporaryUrl($path, $expiration, array $options = []): string
    {
        return S3PublicUrlSigner::temporaryUrl($this->config, $this->prefixer->prefixPath($path), $expiration, $options);
    }
}
