<?php

namespace Tests\Feature;

use App\Models\Oficina;
use App\Services\GetStationAgentsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GetStationAgentsServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function makeOficina(): Oficina
    {
        return Oficina::create([
            'idempresa' => 30,
            'idoficina' => 40,
            'ubicacion' => 'Station 40',
            'public_url' => 'https://station40.test',
            'token' => 'token',
            'iatacode' => 'CUN',
        ]);
    }

    public function test_it_returns_plain_list_as_array(): void
    {
        Http::fake([
            'station40.test/agentes/getstationagents' => Http::response([
                ['idagente' => 1, 'shortname' => 'a', 'nombre' => 'A', 'apellidos' => 'One'],
                ['idagente' => 2, 'shortname' => 'b', 'nombre' => 'B', 'apellidos' => 'Two'],
            ], 200),
        ]);

        $result = (new GetStationAgentsService())->getStationAgents($this->makeOficina());

        $this->assertIsArray($result);
        $this->assertCount(2, $result);
    }

    public function test_it_returns_wrapper_object_with_data_key(): void
    {
        Http::fake([
            'station40.test/agentes/getstationagents' => Http::response([
                'status' => 'success',
                'data' => [
                    ['idagente' => 1, 'shortname' => 'a', 'nombre' => 'A', 'apellidos' => 'One'],
                ],
            ], 200),
        ]);

        $result = (new GetStationAgentsService())->getStationAgents($this->makeOficina());

        $this->assertIsObject($result);
        $this->assertEquals('success', $result->status);
        $this->assertCount(1, $result->data);
    }

    public function test_it_flags_http_failure_as_failed_status(): void
    {
        Http::fake([
            'station40.test/agentes/getstationagents' => Http::response('Server error', 500),
        ]);

        $result = (new GetStationAgentsService())->getStationAgents($this->makeOficina());

        $this->assertIsObject($result);
        $this->assertEquals('failed', $result->status);
    }

    public function test_it_flags_non_json_body_as_failed_status(): void
    {
        Http::fake([
            'station40.test/agentes/getstationagents' => Http::response('not json', 200),
        ]);

        $result = (new GetStationAgentsService())->getStationAgents($this->makeOficina());

        $this->assertIsObject($result);
        $this->assertEquals('failed', $result->status);
    }

    /*
    |--------------------------------------------------------------------------
    | What the operator is told when a pull fails
    |--------------------------------------------------------------------------
    |
    | "Pull failed" with no reason is what this service used to produce, and it
    | cost a whole debugging session: the station's answer (a status code, an
    | HTML login page, a transport error) was written to the log and never shown,
    | while the modal carried a sentence that named nothing. These tests pin the
    | parts of the failure that have to reach the screen, and the parts of the
    | request that have to stay correct.
    |
    */

    public function test_it_refuses_to_call_a_station_that_has_no_public_url(): void
    {
        Http::fake();

        $office = Oficina::create([
            'idempresa' => 30,
            'idoficina' => 41,
            'ubicacion' => 'Buaran',
            'public_url' => '   ',
            'token' => 'token',
            'iatacode' => 'JKT',
        ]);

        $result = (new GetStationAgentsService())->getStationAgents($office);

        $this->assertSame('failed', $result->status);

        // A bare path can never resolve, so no request is worth making - and the
        // message has to name the office, because nothing else tells the operator
        // which row of the office table the pull was aimed at.
        $this->assertSame(
            'Office "Buaran" (30/41) has no public URL configured. Set it on the office form.',
            $result->message
        );

        Http::assertNothingSent();
    }

    public function test_it_does_not_double_the_slash_when_the_public_url_ends_with_one(): void
    {
        Http::fake(['*' => Http::response([], 200)]);

        $office = Oficina::create([
            'idempresa' => 30,
            'idoficina' => 42,
            'ubicacion' => 'Buaran',
            'public_url' => 'https://station40.test/',
            'token' => 'token',
            'iatacode' => 'JKT',
        ]);

        (new GetStationAgentsService())->getStationAgents($office);

        Http::assertSent(
            fn ($request) => $request->url() === 'https://station40.test/agentes/getstationagents'
        );
    }

    public function test_it_sends_the_office_ids_as_json_with_the_token_in_the_authorization_header(): void
    {
        Http::fake(['*' => Http::response([], 200)]);

        $office = Oficina::create([
            'idempresa' => 30,
            'idoficina' => 43,
            'ubicacion' => 'Buaran',
            'public_url' => 'https://station40.test',
            'token' => 'TOKEN-ABC',
            'iatacode' => 'JKT',
        ]);

        (new GetStationAgentsService())->getStationAgents($office);

        // The station's contract: a JSON POST, the raw token in Authorization,
        // and the two ids in the body. Nothing asserted this before - Http::fake
        // matches on URL alone - so a change to the method or the body shape
        // would have gone unnoticed until a station rejected it.
        Http::assertSent(function ($request) {
            return $request->method() === 'POST'
                && $request->url() === 'https://station40.test/agentes/getstationagents'
                && $request->header('Authorization')[0] === 'TOKEN-ABC'
                && $request->header('Content-Type')[0] === 'application/json'
                && $request->body() === '{"idempresa":30,"idoficina":43}';
        });
    }

    public function test_it_still_calls_a_station_that_has_no_token(): void
    {
        Http::fake(['*' => Http::response([], 200)]);

        $office = Oficina::create([
            'idempresa' => 30,
            'idoficina' => 44,
            'ubicacion' => 'Buaran',
            'public_url' => 'https://station40.test',
            'token' => '',
            'iatacode' => 'JKT',
        ]);

        (new GetStationAgentsService())->getStationAgents($office);

        // Deliberately not blocked: a station that does not check the token must
        // keep working. The empty Authorization header is recorded in the log
        // instead of being turned into a refusal here.
        Http::assertSent(
            fn ($request) => $request->url() === 'https://station40.test/agentes/getstationagents'
        );
    }

    public function test_it_reports_the_http_status_and_a_bounded_slice_of_the_body(): void
    {
        // A station that refuses a request answers with its own error page, which
        // can be tens of kilobytes. It has to reach the screen short enough to
        // read, and the status has to survive with it.
        Http::fake([
            'station40.test/agentes/getstationagents' => Http::response(
                '{"message":"Unauthenticated.","trace":"' . str_repeat('E', 4000) . '"}',
                401
            ),
        ]);

        $result = (new GetStationAgentsService())->getStationAgents($this->makeOficina());

        $this->assertSame('failed', $result->status);
        $this->assertStringStartsWith('HTTP 401: {"message":"Unauthenticated."', $result->message);
        $this->assertStringEndsWith('...', $result->message);
        $this->assertLessThan(400, strlen($result->message));
    }

    public function test_it_shows_the_status_and_a_slice_of_an_html_reply(): void
    {
        // The likeliest bad answer in the field: the station's session expired,
        // so it returns a login page with a 200. The old message ("Unexpected or
        // empty response body from station.") named nothing at all.
        $html = '<!DOCTYPE html><html><head><title>Login</title></head><body>'
            . str_repeat('x', 5000) . '</body></html>';

        Http::fake(['station40.test/agentes/getstationagents' => Http::response($html, 200)]);

        $result = (new GetStationAgentsService())->getStationAgents($this->makeOficina());

        $this->assertSame('failed', $result->status);
        $this->assertStringStartsWith(
            'Station replied HTTP 200 but the body is not JSON: <!DOCTYPE html>',
            $result->message
        );
        $this->assertStringContainsString('Login', $result->message);
        $this->assertLessThan(500, strlen($result->message));
    }

    /*
    |--------------------------------------------------------------------------
    | The station URL that points back at this server
    |--------------------------------------------------------------------------
    |
    | What production actually did. Office 1/1 (Buaran) was given the address of
    | the ADMS server itself in its "URL Publik" field, so every pull POSTed
    | /agentes/getstationagents back into this app - which has no such route -
    | and nginx answered with a bare 404 page. The modal said only "Pull failed"
    | and the log showed a 404 for a URL on our own domain. The cause was one
    | field away, in the office form, and nothing pointed at it.
    |
    */

    public function test_it_refuses_a_station_url_that_points_at_this_server(): void
    {
        config(['app.url' => 'https://adms.example.test']);
        Http::fake();

        // The field is filled in by hand from the browser's address bar, so it
        // arrives with a scheme, without one, and with a trailing slash.
        foreach (['https://adms.example.test', 'adms.example.test', 'https://adms.example.test/'] as $i => $url) {
            $office = Oficina::create([
                'idempresa' => 1,
                'idoficina' => 60 + $i,
                'ubicacion' => 'Buaran',
                'public_url' => $url,
                'token' => 'token',
                'iatacode' => 'JKT',
            ]);

            $result = (new GetStationAgentsService())->getStationAgents($office);

            $this->assertSame('failed', $result->status, $url);
            $this->assertSame(
                'Office "Buaran" points its public URL at this ADMS server (adms.example.test) '
                . 'instead of the station app. Replace it on the office form with the station app address.',
                $result->message,
                $url
            );
        }

        // Calling ourselves is pointless: this app has no /agentes route, so
        // the request can only ever come back as a 404.
        Http::assertNothingSent();
    }

    public function test_it_refuses_a_station_url_that_points_at_the_address_in_the_browser(): void
    {
        // In production APP_URL is usually left at its default, so app.url is
        // not a reliable signal on its own. The address the operator is
        // browsing is - it is the one they copied into the field.
        config(['app.url' => 'http://localhost']);
        $this->app->instance('request', Request::create('https://adms.halobayi.co.id/oficinas'));
        Http::fake();

        $office = Oficina::create([
            'idempresa' => 1,
            'idoficina' => 63,
            'ubicacion' => 'Buaran',
            'public_url' => 'https://adms.halobayi.co.id',
            'token' => 'token',
            'iatacode' => 'JKT',
        ]);

        $result = (new GetStationAgentsService())->getStationAgents($office);

        $this->assertSame('failed', $result->status);
        $this->assertStringContainsString('adms.halobayi.co.id', $result->message);
        Http::assertNothingSent();
    }

    public function test_it_still_calls_a_station_that_only_shares_the_host(): void
    {
        // The guard compares whole origins, not hostnames, because sharing a
        // hostname is not the mistake. Refusing these would be a regression:
        // both are separate applications that merely live on the same machine
        // or under the same domain.
        config(['app.url' => 'https://adms.example.test']);
        Http::fake(['*' => Http::response([], 200)]);

        foreach (['https://adms.example.test/roster', 'https://adms.example.test:8443'] as $i => $url) {
            $office = Oficina::create([
                'idempresa' => 30,
                'idoficina' => 70 + $i,
                'ubicacion' => 'Station 40',
                'public_url' => $url,
                'token' => 'token',
                'iatacode' => 'CUN',
            ]);

            (new GetStationAgentsService())->getStationAgents($office);
        }

        Http::assertSent(
            fn ($request) => $request->url() === 'https://adms.example.test/roster/agentes/getstationagents'
        );

        Http::assertSent(
            fn ($request) => $request->url() === 'https://adms.example.test:8443/agentes/getstationagents'
        );
    }
}
