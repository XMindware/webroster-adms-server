@extends('layouts.app')

@section('content')
    <div class="container">
        <h2>Create Biometric Recor</h2>
        <form method="post" action="{{ route('devices.store') }}">
            @csrf
            <div class="form-group">
                <label for="name">{{ __('devices.location') }}</label>
                <input type="text" name="name" class="form-control" id="name" placeholder="Location">
            </div>
            <div class="form-group">
                <label for="oficina_id">{{ __('devices.oficina') }}</label>
                <select name="oficina_id" class="form-control" id="oficina_id">
                    @foreach ($oficinas as $oficina)
                        <option value="{{ $oficina->id }}">{{ $oficina->ubicacion }}</option>
                    @endforeach
                </select>
            </div>
            <div class="form-group">
                <label for="no_sn">{{ __('devices.serial_number') }}</label>
                <input type="text" name="no_sn" class="form-control" id="no_sn" placeholder="SN00001">
            </div>
            <div class="form-group">
                <label for="lokasi">{{ __('devices.id') }}</label>
                <input type="text" name="idreloj" class="form-control" id="idreloj" placeholder="ID Reloj">
            </div>
            <div class="form-group">
                <label for="lokasi">{{ __('devices.ip_address') }}</label>
                <input type="text" name="ip" class="form-control" id="ip" placeholder="IP">
            </div>

            <div class="form-group">
                <label for="timezone_format">Formato de zona horaria (TimeZone)</label>
                <select name="timezone_format" class="form-control" id="timezone_format">
                    <option value="" selected>Nombre (America/Mexico_City) - predeterminado</option>
                    <option value="hours">Horas (-6)</option>
                    <option value="minutes">Minutos (-360)</option>
                </select>
            </div>

            <button type="submit" class="btn btn-primary">Enviar</button>
        </form>
    </div>
@endsection
