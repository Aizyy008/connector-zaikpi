<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * The root redirects into the admin panel (which then guards via auth).
     */
    public function test_the_root_redirects_to_the_admin_dashboard(): void
    {
        $this->get('/')->assertRedirect(route('admin.dashboard'));
    }
}
