<?php

namespace Tests\Feature\Tenant;

use App\Tenancy\Storage\BucketManagers;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\SignsInTenantUsers;
use Tests\TestCase;

class AvatarTest extends TestCase
{
    use SignsInTenantUsers;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
    }

    public function test_uploading_an_avatar_stores_it_with_a_thumbnail_in_the_tenants_bucket(): void
    {
        $user = $this->signInAs('acme');

        $this->put($this->tenantUrl('acme', '/profile/avatar'), ['avatar' => UploadedFile::fake()->image('me.png', 300, 300)])
            ->assertRedirect($this->tenantUrl('acme', '/profile'));

        $media = $this->tenant('acme')->run(fn () => $user->fresh()->getFirstMedia('avatar'));
        $this->assertNotNull($media);
        $this->assertTrue($media->hasGeneratedConversion('thumb'));
        // Not storage_path(): tenancy is still on after the request, so stancl has suffixed it.
        $bucket = BucketManagers::forDisk('local')->path($this->tenant('acme')->storage_bucket);
        $this->assertFileExists($bucket.'/'.$media->getPathRelativeToRoot('thumb'));

        $this->get($this->tenantUrl('acme', '/profile'))->assertOk()->assertSee('/storage/local/', false);
    }

    public function test_a_new_avatar_replaces_the_old_one_and_can_be_removed(): void
    {
        $user = $this->signInAs('acme');
        $url = $this->tenantUrl('acme', '/profile/avatar');

        $this->put($url, ['avatar' => UploadedFile::fake()->image('one.png')]);
        $this->put($url, ['avatar' => UploadedFile::fake()->image('two.png')]);
        $this->assertSame(['two.png'], $this->tenant('acme')->run(fn () => $user->fresh()->getMedia('avatar')->pluck('file_name')->all()));

        $this->delete($url)->assertRedirect($this->tenantUrl('acme', '/profile'));
        $this->assertSame(0, $this->tenant('acme')->run(fn () => $user->fresh()->getMedia('avatar')->count()));
    }

    public function test_only_small_images_are_accepted(): void
    {
        $this->signInAs('acme');
        $url = $this->tenantUrl('acme', '/profile/avatar');

        $this->put($url, ['avatar' => UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf')])->assertSessionHasErrors('avatar');
        $this->put($url, ['avatar' => UploadedFile::fake()->image('big.png')->size(3000)])->assertSessionHasErrors('avatar');
    }
}
