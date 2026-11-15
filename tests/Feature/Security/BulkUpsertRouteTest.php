<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * S12: /api/agentes/bulk-upsert was unauthenticated and pointed at a method
 * that does not exist. It must not be routable.
 */
class BulkUpsertRouteTest extends TestCase
{
    public function test_bulk_upsert_endpoint_is_not_routable(): void
    {
        $this->postJson('/api/agentes/bulk-upsert', ['agentes' => []])->assertNotFound();
    }

    public function test_every_api_route_points_at_an_existing_controller_method(): void
    {
        foreach (Route::getRoutes() as $route) {
            $action = $route->getAction('uses');
            if (! is_string($action) || ! str_contains($action, '@')) {
                continue;
            }
            [$class, $method] = explode('@', $action, 2);
            $this->assertTrue(
                method_exists($class, $method),
                "Route {$route->uri()} points at missing {$class}@{$method}"
            );
        }
    }
}
