<?php

namespace Tests\Feature\Central;

use Illuminate\Http\Request;
use Opcodes\LogViewer\Http\Middleware\EnsureFrontendRequestsAreStateful;
use Tests\TestCase;

class LogViewerApiTest extends TestCase
{
    /**
     * log-viewer's API only gets a session (and so `auth:central`) when the page's Referer matches
     * `api_stateful_domains`. In port mode the Referer carries the port (`localhost:8000`), so the
     * central domain alone (`localhost`) doesn't match and every API call answers 401.
     */
    public function test_the_api_is_stateful_for_a_central_domain_with_a_port(): void
    {
        $domains = $this->statefulDomainsFor(appUrl: 'http://localhost:8000', centralDomain: 'localhost');
        config(['log-viewer.api_stateful_domains' => $domains]);

        $request = Request::create('http://localhost:8000/log-viewer/api/folders', 'GET', server: [
            'HTTP_REFERER' => 'http://localhost:8000/log-viewer',
        ]);

        $this->assertTrue(EnsureFrontendRequestsAreStateful::fromFrontend($request));
    }

    public function test_the_api_stays_stateful_for_a_central_domain_without_a_port(): void
    {
        $domains = $this->statefulDomainsFor(appUrl: 'https://tenancey.test', centralDomain: 'tenancey.test');
        config(['log-viewer.api_stateful_domains' => $domains]);

        $request = Request::create('https://tenancey.test/log-viewer/api/folders', 'GET', server: [
            'HTTP_REFERER' => 'https://tenancey.test/log-viewer',
        ]);

        $this->assertTrue(EnsureFrontendRequestsAreStateful::fromFrontend($request));
    }

    /**
     * Evaluate config/log-viewer.php under the given environment, as the app would at boot.
     *
     * @return list<string>
     */
    private function statefulDomainsFor(string $appUrl, string $centralDomain): array
    {
        $saved = [$_SERVER['APP_URL'] ?? null, $_SERVER['CENTRAL_DOMAIN'] ?? null];
        $_SERVER['APP_URL'] = $appUrl;
        $_SERVER['CENTRAL_DOMAIN'] = $centralDomain;

        try {
            return (require config_path('log-viewer.php'))['api_stateful_domains'];
        } finally {
            [$_SERVER['APP_URL'], $_SERVER['CENTRAL_DOMAIN']] = $saved;
        }
    }
}
