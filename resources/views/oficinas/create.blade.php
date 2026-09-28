@extends('layouts.app')

@section('content')
    <div class="container">
        <h2>{{ __('oficinas.create_oficina') }}</h2>
        <form method="post" action="{{ route('oficinas.store') }}">
            @csrf
            <div class="form-group">
                <label for="idempresa">{{ __('oficinas.idempresa') }}</label>
                <input type="text" name="idempresa" class="form-control" id="idempresa" value="{{ old('idempresa') }}">
            </div>
            <div class="form-group">
                <label for="idoficina">{{ __('oficinas.idoficina') }}</label>
                <input type="text" name="idoficina" class="form-control" id="idoficina" value="{{ old('idoficina') }}">
            </div>
            <div class="form-group">
                <label for="ubicacion">{{ __('oficinas.ubicacion') }}</label>
                <input type="text" name="ubicacion" class="form-control" id="ubicacion" value="{{ old('ubicacion') }}">
            </div>
            <div class="form-group">
                <label for="public_url">{{ __('oficinas.public_url') }}</label>
                <input type="text" name="public_url" class="form-control" id="public_url" value="{{ old('public_url') }}">
            </div>
            <div class="form-group">
                <label for="token">{{ __('oficinas.token') }}</label>
                <input type="text" name="token" class="form-control" id="token" value="{{ old('token') }}">
            </div>
            <div class="form-group">
                <label for="iatacode">{{ __('oficinas.iatacode') }}</label>
                <input type="text" name="iatacode" class="form-control" id="iatacode" value="{{ old('iatacode') }}">
            </div>
            <div class="form-group">
                <label for="city_timezone">{{ __('oficinas.city_timezone') }}</label>
                <input type="text" name="city_timezone" class="form-control" id="city_timezone" value="{{ old('city_timezone') }}">
            </div>
            <div class="form-group">
                <label for="timezone">{{ __('oficinas.timezone') }}</label>
                <input type="text" name="timezone" class="form-control" id="timezone" value="{{ old('timezone') }}">
            </div>
            <br/>
            <button type="submit" class="btn btn-primary">{{ __('common.create') }}</button>
            <a href="{{ route('devices.oficinas') }}" class="btn btn-secondary">{{ __('common.cancel') }}</a>
        </form>
    </div>
@endsection
