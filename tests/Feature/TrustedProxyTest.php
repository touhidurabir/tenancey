<?php

namespace Tests\Feature;

use Tests\TestCase;

class TrustedProxyTest extends TestCase
{
    /**
     * Traefik ends HTTPS and forwards plain http to nginx with X-Forwarded-Proto: https.
     * Laravel must believe that header, or every URL it builds during the request is http://.
     */
    public function test_a_request_forwarded_as_https_builds_https_urls(): void
    {
        $this->withHeader('X-Forwarded-Proto', 'https')
            ->get('http://tenancey.test/tenants')
            ->assertRedirect('https://tenancey.test/login');
    }

    public function test_a_plain_http_request_without_the_header_stays_http(): void
    {
        $this->get('http://tenancey.test/tenants')
            ->assertRedirect('http://tenancey.test/login');
    }
}
