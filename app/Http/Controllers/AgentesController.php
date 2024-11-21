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
        $oficina = $request->input('oficina');
        if ($oficina) {
            $agentes = Agente::where('idoficina', $oficina)->get()
                    ->sortBy('idagente');
        } else {
            $agentes = Agente::all()->sortBy('idagente');
        }
        $oficinas = Oficina::all();
        return view('agentes.index', compact('agentes', 'oficinas'));
    }

    public function pullAgentes(Request $request)
    {
        $oficinas = Oficina::all();
        return view('agentes.pull', compact('oficinas'));
    }

    public function runPullAgentes(Request $request)
    {
        $idoficina = $request->input('oficina');
        $oficina = Oficina::where('idoficina', $idoficina)->first();
                
        pullEmployeesJob::dispatch($oficina);
        // add message to session
        $request->session()->flash('status', 'Job to pull employees has been dispatched.');
        return redirect()->route('agentes.index');
    }
}
