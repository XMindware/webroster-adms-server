<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Agente;
use App\Models\Device;
use App\Models\FacePhoto;
use App\Models\FaceTemplate;
use App\Models\FingerprintTemplate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * WebRoster -> ADMS: an empresa's agents and whether the terminals hold
 * fingerprints or faces for them. Only counts and flags are returned, never
 * template or photo payloads.
 *
 * Biometrics are matched to an agent by empresa and PIN (== agentes.idagente),
 * across every terminal of the empresa.
 *
 * @author XMindware
 */
class AgenteBiometricsController extends Controller
{
    /**
     * GET /api/empresa/agentes?idoficina=&has_fingerprint=&has_face=&per_page=&page=
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'idoficina' => 'sometimes|integer',
            'has_fingerprint' => 'sometimes|boolean',
            'has_face' => 'sometimes|boolean',
            'per_page' => 'sometimes|integer|min:1|max:1000',
        ]);

        $idempresa = $request->attributes->get('idempresa');

        $base = Agente::where('idempresa', $idempresa)
            ->when(isset($validated['idoficina']), fn ($q) => $q->where('idoficina', $validated['idoficina']));

        $summary = [
            'total' => (clone $base)->count(),
            'with_fingerprint' => $this->whereHasFingerprint(clone $base)->count(),
            'with_face' => $this->whereHasFace(clone $base)->count(),
            'without_biometrics' => $this->whereHasFingerprint($this->whereHasFace(clone $base, false), false)->count(),
        ];

        $filtered = clone $base;
        if (array_key_exists('has_fingerprint', $validated)) {
            $this->whereHasFingerprint($filtered, filter_var($validated['has_fingerprint'], FILTER_VALIDATE_BOOLEAN));
        }
        if (array_key_exists('has_face', $validated)) {
            $this->whereHasFace($filtered, filter_var($validated['has_face'], FILTER_VALIDATE_BOOLEAN));
        }

        $page = $filtered->orderBy('idoficina')->orderBy('idagente')
            ->paginate((int) ($validated['per_page'] ?? 200))
            ->withQueryString();

        return response()->json([
            'idempresa' => $idempresa,
            'generated_at' => now()->toIso8601String(),
            'summary' => $summary,
            'data' => $this->present($idempresa, collect($page->items())),
            'meta' => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
            ],
            'links' => [
                'next' => $page->nextPageUrl(),
                'prev' => $page->previousPageUrl(),
            ],
        ]);
    }

    /**
     * GET /api/empresa/agentes/{idagente}
     *
     * Same row as the list, plus which terminals hold which biometrics.
     */
    public function show(Request $request, string $idagente): JsonResponse
    {
        $idempresa = $request->attributes->get('idempresa');

        $agentes = Agente::where('idempresa', $idempresa)->where('idagente', $idagente)
            ->orderBy('idoficina')->get();

        // Same response for "doesn't exist" and "belongs to another empresa".
        abort_if($agentes->isEmpty(), 404, 'Agent not found.');

        $pin = (string) $idagente;

        $fingers = FingerprintTemplate::valid()->where('idempresa', $idempresa)->where('pin', $pin)
            ->get(['sn', 'fid', 'captured_at'])->groupBy('sn');
        $faces = FaceTemplate::valid()->where('idempresa', $idempresa)->where('pin', $pin)
            ->get(['sn', 'fid', 'bio_type', 'captured_at'])->groupBy('sn');
        $photos = FacePhoto::where('idempresa', $idempresa)->where('pin', $pin)
            ->get(['sn', 'bio_type', 'captured_at'])->groupBy('sn');

        $sns = $fingers->keys()->merge($faces->keys())->merge($photos->keys())->unique()->sort()->values();
        $devices = Device::where('idempresa', $idempresa)->whereIn('serial_number', $sns)
            ->get()->keyBy('serial_number');

        $terminals = $sns->map(function (string $sn) use ($fingers, $faces, $photos, $devices) {
            $device = $devices->get($sn);
            $f = $fingers->get($sn, collect());
            $t = $faces->get($sn, collect());
            $p = $photos->get($sn, collect());

            return [
                'serial_number' => $sn,
                'name' => $device?->name,
                'idoficina' => $device?->idoficina !== null ? (int) $device->idoficina : null,
                'fingers' => $f->pluck('fid')->map(fn ($v) => (int) $v)->unique()->sort()->values(),
                'face_templates' => $t->count(),
                'face_photos' => $p->count(),
                'last_captured_at' => $f->pluck('captured_at')->merge($t->pluck('captured_at'))
                    ->merge($p->pluck('captured_at'))->filter()->max()?->toIso8601String(),
            ];
        });

        return response()->json([
            'generated_at' => now()->toIso8601String(),
            'data' => $this->present($idempresa, $agentes)->map(fn (array $row) => $row + ['terminals' => $terminals]),
        ]);
    }

    /**
     * Correlated EXISTS on a template table. The agent side is cast to text so
     * the comparison runs against the indexed string `pin` / `idempresa`
     * columns instead of converting every template row.
     */
    private function whereExistsIn(Builder $query, string $table, bool $present, bool $validOnly): Builder
    {
        $method = $present ? 'whereExists' : 'whereNotExists';

        return $query->{$method}(function ($sub) use ($table, $validOnly) {
            $sub->selectRaw('1')->from($table)
                ->whereRaw("$table.idempresa = CAST(agentes.idempresa AS CHAR)")
                ->whereRaw("$table.pin = CAST(agentes.idagente AS CHAR)")
                ->when($validOnly, fn ($q) => $q->where("$table.valid", '>', 0));
        });
    }

    private function whereHasFingerprint(Builder $query, bool $present = true): Builder
    {
        return $this->whereExistsIn($query, 'fingerprint_templates', $present, true);
    }

    /**
     * A face counts if the terminals hold either a face template or a
     * comparison photo for the agent.
     */
    private function whereHasFace(Builder $query, bool $present = true): Builder
    {
        if (!$present) {
            $this->whereExistsIn($query, 'face_templates', false, true);

            return $this->whereExistsIn($query, 'face_photos', false, false);
        }

        return $query->where(function (Builder $q) {
            $this->whereExistsIn($q, 'face_templates', true, true);
            $q->orWhere(fn (Builder $or) => $this->whereExistsIn($or, 'face_photos', true, false));
        });
    }

    /**
     * Build response rows with a fixed number of queries regardless of agent count.
     */
    private function present(string $idempresa, Collection $agentes): Collection
    {
        if ($agentes->isEmpty()) {
            return collect();
        }

        $pins = $agentes->pluck('idagente')->map(fn ($v) => (string) $v)->unique()->values();

        $fingers = FingerprintTemplate::valid()->where('idempresa', $idempresa)->whereIn('pin', $pins)
            ->groupBy('pin')
            ->selectRaw('pin, count(distinct fid) as fingers, count(distinct sn) as terminals, max(captured_at) as last_captured_at')
            ->get()->keyBy('pin');

        $faceTemplates = FaceTemplate::valid()->where('idempresa', $idempresa)->whereIn('pin', $pins)
            ->groupBy('pin')
            ->selectRaw('pin, count(distinct sn) as terminals, max(captured_at) as last_captured_at')
            ->get()->keyBy('pin');

        $facePhotos = FacePhoto::where('idempresa', $idempresa)->whereIn('pin', $pins)
            ->groupBy('pin')
            ->selectRaw('pin, count(distinct sn) as terminals, max(captured_at) as last_captured_at')
            ->get()->keyBy('pin');

        return $agentes->map(function (Agente $agente) use ($fingers, $faceTemplates, $facePhotos) {
            $pin = (string) $agente->idagente;
            $f = $fingers->get($pin);
            $t = $faceTemplates->get($pin);
            $p = $facePhotos->get($pin);

            return [
                'idagente' => (int) $agente->idagente,
                'idoficina' => (int) $agente->idoficina,
                'shortname' => $agente->shortname,
                'fullname' => $agente->fullname,
                'has_fingerprint' => $f !== null,
                'has_face' => $t !== null || $p !== null,
                'fingerprint' => [
                    'fingers' => (int) ($f->fingers ?? 0),
                    'terminals' => (int) ($f->terminals ?? 0),
                    'last_captured_at' => $this->iso($f?->last_captured_at),
                ],
                'face' => [
                    'template_terminals' => (int) ($t->terminals ?? 0),
                    'photo_terminals' => (int) ($p->terminals ?? 0),
                    'last_captured_at' => $this->iso(collect([$t?->last_captured_at, $p?->last_captured_at])->filter()->max()),
                ],
            ];
        })->values();
    }

    private function iso($value): ?string
    {
        return $value ? \Illuminate\Support\Carbon::parse($value)->toIso8601String() : null;
    }
}
