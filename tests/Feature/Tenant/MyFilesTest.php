<?php

namespace Tests\Feature\Tenant;

use App\Models\User;
use App\Tenancy\Storage\BucketManagers;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\SignsInTenantUsers;
use Tests\TestCase;

class MyFilesTest extends TestCase
{
    use SignsInTenantUsers;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
    }

    public function test_a_user_uploads_lists_downloads_and_deletes_a_file(): void
    {
        $user = $this->signInAs('acme');

        $this->post($this->tenantUrl('acme', '/files'), ['file' => UploadedFile::fake()->createWithContent('report.txt', 'quarterly')])
            ->assertRedirect($this->tenantUrl('acme', '/files'));

        $media = $this->tenant('acme')->run(fn () => $user->fresh()->getFirstMedia('files'));
        $this->get($this->tenantUrl('acme', '/files'))->assertOk()->assertSee('report.txt');

        $link = $this->get($this->tenantUrl('acme', "/files/{$media->id}/download"))->assertRedirect()->headers->get('Location');
        $this->assertStringContainsString('/storage/local/', $link);
        $this->assertSame('quarterly', $this->get($link)->assertDownload('report.txt')->streamedContent());

        $this->delete($this->tenantUrl('acme', "/files/{$media->id}"))->assertRedirect($this->tenantUrl('acme', '/files'));
        $this->assertSame(0, $this->tenant('acme')->run(fn () => $user->fresh()->getMedia('files')->count()));
        // Not storage_path(): tenancy is still on after the request, so stancl has suffixed it.
        $bucket = BucketManagers::forDisk('local')->path($this->tenant('acme')->storage_bucket);
        $this->assertFileDoesNotExist($bucket.'/'.$media->getPathRelativeToRoot());
    }

    public function test_another_users_file_is_not_found(): void
    {
        $this->signInAs('acme');
        $bob = $this->tenant('acme')->run(function () {
            $bob = User::factory()->create();
            $bob->addMedia(UploadedFile::fake()->createWithContent('bob.txt', 'private'))->toMediaCollection('files');

            return $bob;
        });
        $mediaId = $this->tenant('acme')->run(fn () => $bob->getFirstMedia('files')->id);

        $this->get($this->tenantUrl('acme', "/files/{$mediaId}/download"))->assertNotFound();
        $this->delete($this->tenantUrl('acme', "/files/{$mediaId}"))->assertNotFound();
        $this->get($this->tenantUrl('acme', '/files'))->assertDontSee('bob.txt');
    }

    public function test_files_over_10_mb_are_rejected(): void
    {
        $this->signInAs('acme');

        $this->post($this->tenantUrl('acme', '/files'), ['file' => UploadedFile::fake()->create('huge.bin', 10241)])
            ->assertSessionHasErrors('file');
    }
}
