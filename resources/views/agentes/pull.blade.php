@extends('layouts.app')

@section('content')
    <div class="container">
        <h2>Pull employees from Office</h2>
        <form method="post" action="{{ route('agentes.runpull') }}">
            @csrf
            <div class="form-group">
                <label for="oficina">Offices</label>
                <select name="oficina" class="form-control" id="oficina">
                    @foreach ($oficinas as $oficina)
                        <option value="{{ $oficina->idoficina }}" data-idempresa="{{ $oficina->idempresa }}">{{ $oficina->ubicacion }}</option>
                    @endforeach
                </select>
            </div>
            <input type="hidden" name="idempresa" id="idempresa" value="">
            <button type="submit" class="btn btn-primary">Run Request</button>
        </form>
    </div>
    <script>
        (function(){
            var select = document.getElementById('oficina');
            var inputEmpresa = document.getElementById('idempresa');
            function syncEmpresa() {
                var opt = select.options[select.selectedIndex];
                if (opt && opt.dataset && opt.dataset.idempresa) {
                    inputEmpresa.value = opt.dataset.idempresa;
                }
            }
            select.addEventListener('change', syncEmpresa);
            syncEmpresa();
        })();
    </script>
@endsection
