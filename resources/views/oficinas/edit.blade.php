@extends('layouts.app')

@section('content')
    <div class="container">
        <h2>{{ __('oficinas.edit_oficina') }}</h2>
        <form method="post" action="{{ route('oficinas.update', ['id' => $oficina->id ]) }}">
            @csrf
            <div class="form-group">
                <label for="idempresa">{{ __('oficinas.idempresa') }}</label>
                <input type="text" name="idempresa" class="form-control" id="idempresa" value="{{ $oficina->idempresa }}">
            </div>
            <div class="form-group">
                <label for="idoficina">{{ __('oficinas.idoficina') }}</label>
                <input type="text" name="idoficina" class="form-control" id="idoficina" value="{{ $oficina->idoficina }}">
            </div>
            <div class="form-group">
                <label for="ubicacion">{{ __('oficinas.ubicacion') }}</label>
                <input type="text" name="ubicacion" class="form-control" id="ubicacion" value="{{ $oficina->ubicacion }}">
            </div>
            <div class="form-group">
                <label for="public_url">{{ __('oficinas.public_url') }}</label>
                <input type="text" name="public_url" class="form-control" id="public_url" value="{{ $oficina->public_url }}">
            </div>
            <div class="form-group">
                <label for="token">{{ __('oficinas.token') }}</label>
                <input type="text" name="token" class="form-control" id="token" value="{{ $oficina->token }}">
            </div>
            <div class="form-group">
                <label for="iatacode">{{ __('oficinas.iatacode') }}</label>
                <input type="text" name="iatacode" class="form-control" id="iatacode" value="{{ $oficina->iatacode }}">
            </div>
            <div class="form-group">
                <label for="city_timezone">{{ __('oficinas.city_timezone') }}</label>
                <input type="text" name="city_timezone" class="form-control" id="city_timezone" value="{{ $oficina->city_timezone }}">
            </div>
            <div class="form-group">
                <label for="timezone">{{ __('oficinas.timezone') }}</label>
                <input type="text" name="timezone" class="form-control" id="timezone" value="{{ $oficina->timezone }}">
            </div>
            <br/>
            <button type="submit" class="btn btn-primary">{{ __('common.update') }}</button>
            <a href="{{ route('oficinas.delete', ['id' => $oficina->id ]) }}" class="btn btn-danger">{{ __('common.delete') }}</a>
            <a href="{{ route('devices.oficinas') }}" class="btn btn-secondary">{{ __('common.cancel') }}</a>
        </form>
    </div>
@endsection
