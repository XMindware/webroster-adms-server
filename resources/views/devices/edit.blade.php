@extends('layouts.app')

@section('content')
    <div class="container">
        <h2>{{ __('devices.edit_device') }}</h2>
        <form method="post" action="{{ route('devices.update', ['id' => $device->id ]) }}">
            @csrf
            <input type="hidden" name="id" value="{{ $device->id }}">
            <div class="form-group">
                <label for="name">{{ __('common.name') }}</label>
                <input type="text" name="name" class="form-control" id="name" value="{{ $device->name }}">
            </div>
            <div class="form-group">
                <label for="serial_number">{{ __('devices.serial_number') }}</label>
                <input type="text" name="serial_number" class="form-control" id="serial_number" value="{{ $device->serial_number }}">
            </div>
            <div class="form-group">
                <label for="idreloj">{{ __('devices.id') }}</label>
                <input type="text" name="idreloj" class="form-control" id="idreloj" value="{{ $device->idreloj }}">
            </div>
            <div class="form-group">
                <label for="idoficina">{{ __('devices.oficina') }}</label>
                <select name="idoficina" class="form-control" id="idoficina">
                    @foreach ($oficinas as $oficina)
                        <option value="{{ $oficina->idoficina }}" data-idempresa="{{ $oficina->idempresa }}" @if($device->idoficina == $oficina->idoficina) selected @endif>{{ $oficina->ubicacion }}</option>
                    @endforeach
                </select>
            </div>   
            <input type="hidden" name="idempresa" id="idempresa" value="{{ $device->idempresa }}">
            <div class="form-group">
                <label for="online">{{ __('devices.online') }}</label>
                <input type="text" name="online" class="form-control" id="online" value="{{ $device->online }}">
            </div>
            <br/>
            <button type="submit" class="btn btn-primary">{{ __('common.update') }}</button>
            <!-- remove device -->
            <a href="{{ route('devices.delete', ['id' => $device->id ]) }}" class="btn btn-danger">{{ __('common.delete') }}</a>
            <a href="{{ route('devices.index') }}" class="btn btn-secondary">{{ __('common.cancel') }}</a>
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
            // ensure initial sync on load
            syncEmpresa();
        })();
    </script>
@endsection
