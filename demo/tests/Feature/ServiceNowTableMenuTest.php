<?php

namespace Tests\Feature;

use Tests\TestCase;

class ServiceNowTableMenuTest extends TestCase
{
    public function test_home_page_lists_configured_tables(): void
    {
        $response = $this->get('/');

        $response->assertStatus(200);
        $response->assertSee('incident');
    }

    public function test_unknown_table_returns_404(): void
    {
        $response = $this->get('/tables/not_a_real_table');

        $response->assertStatus(404);
    }
}
