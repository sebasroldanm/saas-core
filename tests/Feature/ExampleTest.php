<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_central_root_redirects_to_admin_panel(): void
    {
        $this->get('http://localhost/')->assertRedirect('/admin');
    }
}
