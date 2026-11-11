<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Oficina;
use App\Models\Agente;
use App\Models\FacePhoto;
use App\Models\FaceTemplate;
use App\Models\FingerprintTemplate;
use App\Services\SyncStationEmployeesService;
use App\Services\RemoveStationEmployeesService;
use Illuminate\Support\Facades\Log;

class AgentesController extends Controller
{
    public function index(Request $request)
    {        
        $selectedOficina = $request->query('selectedOficina');
		$idempresa = $request->query('idempresa');
        $selected = null;

        if ($selectedOficina) {
			$q = Agente::where('idoficina', $selectedOficina);
            $qo = Oficina::where('idoficina', $selectedOficina);
			if (!empty($idempresa)) {
				$q->where('idempresa', $idempresa);
                $qo->where('idempresa', $idempresa);
			}
			$agentes = $q->get()->sortBy('idagente');
            $selected = $qo->first();
        } else {
            $agentes = Agente::all()->sortBy('idagente');
        }
        $oficinas = Oficina::all();

        $fingerCounts = $this->templateCounts(FingerprintTemplate::valid(), $selected);
        $faceCounts = $this->templateCounts(FaceTemplate::valid(), $selected);

        // A visible-light terminal may hand over only the comparison photo, so
        // an employee with a photo and no template still counts as pulled.
        foreach ($this->templateCounts(FacePhoto::query(), $selected, 'bio_type') as $key => $photos) {
            $faceCounts[$key] = max($faceCounts[$key] ?? 0, $photos);
        }

        return view('agentes.index', compact('agentes', 'oficinas', 'selectedOficina', 'fingerCounts', 'faceCounts'));
    }

    /**
     * Valid biometric templates already pulled, keyed empresa|oficina|pin (pin
     * on the terminal == agentes.idagente). Counts distinct slots so the same
     * finger/face held by several terminals is not counted twice.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $slot column that tells one template of a PIN from another
     */
    protected function templateCounts($query, ?Oficina $office, string $slot = 'fid')
    {
        $query = $query
            ->selectRaw("idempresa, idoficina, pin, COUNT(DISTINCT {$slot}) as slots")
            ->groupBy('idempresa', 'idoficina', 'pin');

        if ($office) {
            $query->forOffice($office->idempresa, $office->idoficina);
        }

        return $query->get()->mapWithKeys(fn ($row) => [
            "{$row->idempresa}|{$row->idoficina}|{$row->pin}" => (int) $row->slots,
        ]);
    }

    public function pullAgentes(Request $request)
    {
        $oficinas = Oficina::all();
        return view('agentes.pull', compact('oficinas'));
    }

    public function runPullAgentes(Request $request, SyncStationEmployeesService $service)
    {
		$idoficina = $request->input('oficina');
		$idempresa = $request->input('idempresa');
		$oficinaQuery = Oficina::where('idoficina', $idoficina);
		if (!empty($idempresa)) {
			$oficinaQuery->where('idempresa', $idempresa);
		}
		$oficina = $oficinaQuery->first();

		if (!$oficina) {
			Log::warning('runPullAgentes: office not found', [
				'idoficina' => $idoficina,
				'idempresa' => $idempresa,
			]);

			$request->session()->flash('pull_result', [
				'failed' => true,
				'message' => __('agentes.office_not_found'),
			]);

			return redirect()->route('agentes.index');
		}

		Log::debug('runPullAgentes: manual pull triggered', [
			'idoficina' => $oficina->idoficina,
			'idempresa' => $oficina->idempresa,
			'triggered_by' => optional($request->user())->email ?? optional($request->user())->id,
		]);

		// Run synchronously so we can report accurate counts back to the user
		// immediately, instead of a fire-and-forget queued job whose result
		// nobody sees.
		$result = $service->syncOffice($oficina);

		Log::info('runPullAgentes: manual pull finished', array_merge($result, [
			'idoficina' => $oficina->idoficina,
			'idempresa' => $oficina->idempresa,
		]));

		$request->session()->flash('pull_result', array_merge($result, [
			'oficina' => $oficina->ubicacion,
		]));

        return redirect()->route('agentes.index');
    }

    /**
     * Deferred device-removal step: push DATA DELETE USERINFO commands to the
     * office's devices for every agent that was marked removed locally but not
     * yet queued for device removal.
     */
    public function runPurgeRemoved(Request $request, RemoveStationEmployeesService $service)
    {
        $idoficina = $request->input('oficina');
        $idempresa = $request->input('idempresa');

        $oficinaQuery = Oficina::where('idoficina', $idoficina);
        if (!empty($idempresa)) {
            $oficinaQuery->where('idempresa', $idempresa);
        }
        $oficina = $oficinaQuery->first();

        if (!$oficina) {
            Log::warning('runPurgeRemoved: office not found', [
                'idoficina' => $idoficina,
                'idempresa' => $idempresa,
            ]);

            $request->session()->flash('purge_result', [
                'failed' => true,
                'message' => __('agentes.office_not_found'),
            ]);

            return redirect()->route('agentes.index', [
                'selectedOficina' => $idoficina,
                'idempresa' => $idempresa,
            ]);
        }

        Log::debug('runPurgeRemoved: manual purge triggered', [
            'idoficina' => $oficina->idoficina,
            'idempresa' => $oficina->idempresa,
            'triggered_by' => optional($request->user())->email ?? optional($request->user())->id,
        ]);

        $result = $service->purgeOffice($oficina);

        Log::info('runPurgeRemoved: manual purge finished', array_merge($result, [
            'idoficina' => $oficina->idoficina,
            'idempresa' => $oficina->idempresa,
        ]));

        $request->session()->flash('purge_result', array_merge($result, [
            'oficina' => $oficina->ubicacion,
        ]));

        return redirect()->route('agentes.index', [
            'selectedOficina' => $oficina->idoficina,
            'idempresa' => $oficina->idempresa,
        ]);
    }
}
