<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Oficina;
use App\Models\Agente;
use App\Jobs\pullEmployeesJob;

class AgentesController extends Controller
{
    public function index(Request $request)
    {        
        $selectedOficina = $request->query('selectedOficina');
		$idempresa = $request->query('idempresa');
        if ($selectedOficina) {
			$q = Agente::where('idoficina', $selectedOficina);
			if (!empty($idempresa)) {
				$q->where('idempresa', $idempresa);
			}
			$agentes = $q->get()->sortBy('idagente');
        } else {
            $agentes = Agente::all()->sortBy('idagente');
        }
        $oficinas = Oficina::all();
        return view('agentes.index', compact('agentes', 'oficinas', 'selectedOficina'));
    }

    public function pullAgentes(Request $request)
    {
        $oficinas = Oficina::all();
        return view('agentes.pull', compact('oficinas'));
    }

    public function runPullAgentes(Request $request)
    {
		$idoficina = $request->input('oficina');
		$idempresa = $request->input('idempresa');
		$oficinaQuery = Oficina::where('idoficina', $idoficina);
		if (!empty($idempresa)) {
			$oficinaQuery->where('idempresa', $idempresa);
		}
		$oficina = $oficinaQuery->first();
                
        pullEmployeesJob::dispatch($oficina);
        // add message to session
        $request->session()->flash('status', 'Job to pull employees has been dispatched.');
        return redirect()->route('agentes.index');
    }
}
