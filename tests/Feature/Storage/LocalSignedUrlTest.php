<?php

namespace Tests\Feature\Storage;

use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class LocalSignedUrlTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
    }

    public function test_a_local_temporary_url_serves_the_tenants_file_until_it_expires(): void
    {
        $tenant = $this->createTenant('acme');

        tenancy()->initialize($tenant);
        Storage::put('docs/report.txt', 'hello');
        $url = Storage::temporaryUrl('docs/report.txt', now()->addMinutes(5));
        tenancy()->end();

        $this->assertStringStartsWith($this->tenantUrl('acme', '/storage/local/docs/report.txt'), $url);

        $response = $this->get($url)->assertOk()->assertDownload('report.txt');
        $this->assertSame('hello', $response->streamedContent());

        $this->get(str_replace('report.txt', 'other.txt', $url))->assertForbidden(); // tampered

        $this->travel(6)->minutes();
        $this->get($url)->assertForbidden(); // expired
    }

    public function test_a_signed_url_for_one_tenant_does_not_work_on_another(): void
    {
        $acme = $this->createTenant('acme');
        $this->createTenant('beta');

        tenancy()->initialize($acme);
        Storage::put('secret.txt', 'acme only');
        $url = Storage::temporaryUrl('secret.txt', now()->addMinutes(5));
        tenancy()->end();

        $this->get(str_replace('acme.', 'beta.', $url))->assertNotFound();
    }
}
