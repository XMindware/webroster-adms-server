<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClockCommTest extends TestCase
{
    /**
     * test the clock/cdata
     */
    public function test_iclock_getrequest_returns_200(): void
    {
        $response = $this->get('/iclock/getrequest');

        $response->assertStatus(200);
    }
}
