<?php

namespace App\Services;

use App\Models\Oficina;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GetStationAgentsService
{
    /**
     * How much of a station's reply to put in front of the operator.
     *
     * When a station refuses a request it answers with whatever its web server
     * produces - an HTML login page, a proxy error page, a framework stack
     * trace. Those run to tens of kilobytes, and this excerpt ends up in a
     * Bootstrap modal, so it is cut down to something readable. The
     * untruncated body still goes to the log.
     */
    private const BODY_EXCERPT_LIMIT = 300;

    /**
     * Constructor
     *
     * Inisialisasi service
     *
     * @author XMindware
     * @link https://github.com/hallobayi/webroster-adms-server/blob/main/app/Services/GetStationAgentsService.php
     */
    public function __construct()
    {
    }

    /**
     * Get Station Agents
     *
     * Mengambil data karyawan (agen) dari server remote untuk kantor tertentu
     *
     * @author XMindware
     * @link https://github.com/hallobayi/webroster-adms-server/blob/main/app/Services/GetStationAgentsService.php
     */
    public function getStationAgents(Oficina $oficina)
    {
        // A trailing slash on the office's public URL used to produce
        // "…//agentes/getstationagents", which stations commonly route to a 404.
        $baseUrl = rtrim(trim((string) $oficina->public_url()), '/');
        $token = trim((string) $oficina->token);

        $context = [
            'idempresa' => $oficina->idempresa,
            'idoficina' => $oficina->idoficina,
            'url' => $baseUrl . '/agentes/getstationagents',
        ];

        Log::debug('getStationAgents: request', $context);

        // A blank public URL cannot produce a working request: the URL would be
        // a bare path, and Guzzle would fail before leaving this server. Report
        // the configuration problem it is instead of letting it surface as
        // "Pull failed" with an opaque transport error - and name the office,
        // because the operator cannot otherwise tell which row of the office
        // table the pull was aimed at.
        if ($baseUrl === '') {
            Log::error('getStationAgents: office has no public URL', $context);

            return (object) [
                'status' => 'failed',
                'message' => 'Office "' . ($oficina->ubicacion ?? '') . '" ('
                    . $oficina->idempresa . '/' . $oficina->idoficina
                    . ') has no public URL configured. Set it on the office form.',
            ];
        }

        // Deliberately not fatal: a station that does not check the token still
        // answers, and refusing here would break such an office. But an empty
        // Authorization header is the first thing to look at when a station
        // answers 401, so record it.
        if ($token === '') {
            Log::error('getStationAgents: office has no station token, sending an empty Authorization header', $context);
        }

        // This field is the base URL of the *station* (roster) app, and the pull
        // POSTs /agentes/getstationagents to it. Pointing it at this ADMS server
        // is an easy mistake - the field is only labelled "Public URL", and the
        // address that comes to mind is the one already open in the browser - and
        // it is what production did: adms.halobayi.co.id was set on office 1/1,
        // so every pull POSTed to this app, which has no such route, and died as
        // "Pull failed" with a bare 404 in the log. Nothing said why.
        if ($this->isOwnOrigin($baseUrl)) {
            $host = $this->hostOf($baseUrl);

            Log::error('getStationAgents: office station URL points at this ADMS server', array_merge($context, [
                'host' => $host,
            ]));

            return (object) [
                'status' => 'failed',
                'message' => 'Office "' . ($oficina->ubicacion ?? '') . '" points its public URL at this ADMS server ('
                    . $host . ') instead of the station app. Replace it on the office form with the station app address.',
            ];
        }

        try {
            $headers = [
                'Authorization' => $token,
                'Accept' => 'application/json',
            ];
            $form = [
                'idempresa' => $oficina->idempresa,
                'idoficina' => $oficina->idoficina,
            ];
            $response = Http::withHeaders($headers)
                ->withBody(json_encode($form), 'application/json')
                ->post($baseUrl . '/agentes/getstationagents');

            // Debug: log the raw response so we can diagnose station API shape
            // changes without having to reproduce the issue live.
            Log::debug('getStationAgents: response', array_merge($context, [
                'http_status' => $response->status(),
                'body' => $response->body(),
            ]));

            if ($response->failed()) {
                Log::error('getStationAgents: HTTP request failed', array_merge($context, [
                    'http_status' => $response->status(),
                    'body' => $response->body(),
                ]));

                return (object) [
                    'status' => 'failed',
                    'message' => 'HTTP ' . $response->status() . ': ' . $this->excerpt($response->body()),
                ];
            }

            $json = $response->json();

            if (!is_array($json)) {
                Log::error('getStationAgents: unexpected/empty response body', array_merge($context, [
                    'http_status' => $response->status(),
                    'body' => $response->body(),
                ]));

                // The reply here is almost always an HTML page - an expired
                // session handing back a login form, or a proxy error page -
                // and it can carry a 200 status. Reporting only "unexpected
                // response body" left the operator with nothing to act on, so
                // the status and a slice of the body travel with the message.
                return (object) [
                    'status' => 'failed',
                    'message' => 'Station replied HTTP ' . $response->status()
                        . ' but the body is not JSON: ' . $this->excerpt($response->body()),
                ];
            }

            // If the station returned a plain list of agents (e.g. [ {...}, {...} ]),
            // keep it as an array so downstream code doesn't have to guess between
            // array/object shapes. If it returned a wrapper object (e.g.
            // { "status": "success", "data": [...] }), keep it as an object so
            // properties like ->status keep working.
            if (array_is_list($json)) {
                Log::debug('getStationAgents: parsed as plain list', array_merge($context, [
                    'count' => count($json),
                ]));

                return $json;
            }

            Log::debug('getStationAgents: parsed as wrapper object', array_merge($context, [
                'keys' => array_keys($json),
            ]));

            return (object) $json;
        } catch (\Exception $e) {
            Log::error('getStationAgents error', array_merge($context, [
                'error' => $e->getMessage(),
            ]));

            return (object) [
                'status' => 'failed',
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Squash a response body into one short line for a modal.
     *
     * Whitespace is collapsed rather than preserved: the body is usually HTML,
     * and raw markup with its own line breaks turns a two-line modal into a
     * wall of tags.
     */
    private function excerpt(?string $body): string
    {
        $body = trim((string) preg_replace('/\s+/', ' ', (string) $body));

        if ($body === '') {
            return '(empty body)';
        }

        return strlen($body) > self::BODY_EXCERPT_LIMIT
            ? substr($body, 0, self::BODY_EXCERPT_LIMIT) . '...'
            : $body;
    }

    /**
     * Is this URL this ADMS server itself, at the same origin?
     *
     * Compared as whole origins rather than as hostnames, because sharing a
     * hostname is not the mistake. A station app on the same machine but
     * another port, or on the same domain under a path, is a different
     * application and is left alone; only the exact origin of this server
     * means the pull is about to call back into the app that issued it.
     */
    private function isOwnOrigin(string $url): bool
    {
        $target = $this->originOf($url);

        if ($target === null) {
            return false;
        }

        foreach ($this->ownOrigins() as $candidate) {
            if ($this->originOf($candidate) === $target) {
                return true;
            }
        }

        return false;
    }

    /**
     * The origins this ADMS server answers on.
     *
     * Two sources, because the mistake has two shapes. `app.url` is the
     * canonical address. `HTTP_HOST` is the address the operator actually has
     * open in the browser - the one they are most likely to paste into the
     * field - and it is the source that matters in production, where `app.url`
     * is often left at its default.
     */
    private function ownOrigins(): array
    {
        $candidates = [(string) config('app.url')];

        // Only set when the request really arrived over HTTP; a console-made
        // request carries a placeholder host that would match nothing useful.
        $httpHost = request()->server('HTTP_HOST');

        if (is_string($httpHost) && $httpHost !== '') {
            $candidates[] = $httpHost;
        }

        return $candidates;
    }

    /**
     * The comparable origin of a URL, or null when it is not a bare origin.
     *
     * Null for anything carrying a path: the station app at
     * "https://example.com/roster" is not the application at
     * "https://example.com", and only the latter can be this server. The port
     * is kept when it is written out, so two apps on one machine stay
     * distinguishable. Default ports are normalised away on both sides of the
     * comparison, which is why this fails open rather than closed.
     */
    private function originOf(string $value): ?string
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        $parts = parse_url($value);

        // A hostname written without a scheme ("adms.example.com") parses as a
        // path, so it has to be handled before the path check below.
        if (!is_array($parts) || !isset($parts['host'])) {
            $host = $this->hostOf($value);

            return $host === '' ? null : $host;
        }

        if (trim((string) ($parts['path'] ?? ''), '/') !== '') {
            return null;
        }

        $host = strtolower(rtrim($parts['host'], '.'));

        return isset($parts['port']) ? $host . ':' . $parts['port'] : $host;
    }

    /**
     * Reduce a URL, or a bare hostname, to a comparable lowercase host.
     *
     * The field is filled in by hand, so it arrives as anything from
     * "https://adms.example.com/" to "adms.example.com". A bare hostname has
     * no scheme and parse_url then reports no host at all, so fall back to the
     * string itself rather than silently comparing against an empty value.
     */
    private function hostOf(string $value): string
    {
        $value = strtolower(trim($value));

        if ($value === '') {
            return '';
        }

        $host = parse_url($value, PHP_URL_HOST);

        return rtrim(is_string($host) && $host !== '' ? $host : $value, '.');
    }
}
