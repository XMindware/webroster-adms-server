@extends('layouts.app')

@section('content')
    <div class="container">
        <h2>Edit Device</h2>
        <form method="post" action="{{ route('devices.update', ['id' => $device->id ]) }}">
            @csrf
            <input type="hidden" name="id" value="{{ $device->id }}">
            <div class="form-group">
                <label for="name">Nombre</label>
                <input type="text" name="name" class="form-control" id="name" value="{{ $device->name }}">
            </div>
            <div class="form-group">
                <label for="serial_number">Numero de Serie</label>
                <input type="text" name="serial_number" class="form-control" id="serial_number" value="{{ $device->serial_number }}">
            </div>
            <div class="form-group">
                <label for="idreloj">ID Reloj</label>
                <input type="text" name="idreloj" class="form-control" id="idreloj" value="{{ $device->idreloj }}">
            </div>
            <div class="form-group">
                <label for="oficina_id">Oficina</label>
                <select name="oficina_id" class="form-control" id="oficina_id">
                    @foreach ($oficinas as $oficina)
                        <option value="{{ $oficina->id }}" @if($device->idoficina == $oficina->idoficina && $device->idempresa == $oficina->idempresa) selected @endif>{{ $oficina->ubicacion }}</option>
                    @endforeach
                </select>
            </div>   
            <div class="form-group">
                <label for="timezone_format">Formato de zona horaria (TimeZone)</label>
                <select name="timezone_format" class="form-control" id="timezone_format">
                    <option value="" @if(!$device->timezone_format) selected @endif>Nombre (America/Mexico_City) - predeterminado</option>
                    <option value="hours" @if($device->timezone_format === 'hours') selected @endif>Horas (-6)</option>
                    <option value="minutes" @if($device->timezone_format === 'minutes') selected @endif>Minutos (-360)</option>
                </select>
            </div>
            <div class="form-group">
                <label for="online">Online</label>
                <input type="text" name="online" class="form-control" id="online" value="{{ $device->online }}">
            </div>
            <br/>
            <button type="submit" class="btn btn-primary">Update</button>
            <!-- remove device -->
            <a href="{{ route('devices.delete', ['id' => $device->id ]) }}" class="btn btn-danger">Delete</a>
            <a href="{{ route('devices.index') }}" class="btn btn-secondary">Cancel</a>
        </form>
    </div>
@endsection
