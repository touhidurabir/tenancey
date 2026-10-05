<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_central_root_sends_guests_to_the_central_login(): void
    {
        $this->get($this->centralUrl('/'))->assertRedirect('/tenants');
        $this->get($this->centralUrl('/tenants'))->assertRedirect($this->centralUrl('/login'));
    }
}
