@extends('layouts.app')

@section('content')
    <div class="container">
        <h2>Create Biometric Recor</h2>
        <form method="post" action="{{ route('devices.store') }}">
            @csrf
            <div class="form-group">
                <label for="name">Location</label>
                <input type="text" name="name" class="form-control" id="name" placeholder="Location">
            </div>
            <div class="form-group">
                <label for="idoficina">Oficina</label>
                <select name="idoficina" class="form-control" id="idoficina">
                    @foreach ($oficinas as $oficina)
                        <option value="{{ $oficina->idoficina }}" data-idempresa="{{ $oficina->idempresa }}">{{ $oficina->ubicacion }}</option>
                    @endforeach
                </select>
            </div>
            <input type="hidden" name="idempresa" id="idempresa" value="">
            <div class="form-group">
                <label for="no_sn">Serial Number</label>
                <input type="text" name="no_sn" class="form-control" id="no_sn" placeholder="SN00001">
            </div>
            <div class="form-group">
                <label for="lokasi">ID Reloj</label>
                <input type="text" name="idreloj" class="form-control" id="idreloj" placeholder="ID Reloj">
            </div>
            <div class="form-group">
                <label for="lokasi">IP</label>
                <input type="text" name="ip" class="form-control" id="ip" placeholder="IP">
            </div>

            <button type="submit" class="btn btn-primary">Enviar</button>
        </form>
    </div>
    <script>
        (function(){
            var select = document.getElementById('idoficina');
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
