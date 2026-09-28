@extends('layouts.app')

@section('content')
    <div class="container">
        <h2>{{ $title }}</h2>
        <form method="post" action="{{ route('devices.runDeleteFingerRecord') }}">
            @csrf
            <div class="form-group mb-3">
                <label for="oficina">{{ __('devices.oficina') }}</label>
                <select name="oficina" class="form-control" id="oficina" required>
                    <option value="">{{ __('devices.select_office') }}</option>
                    @foreach ($oficinas as $oficina)
                        <option value="{{ $oficina->idoficina }}">{{ $oficina->ubicacion }} ({{ $oficina->idoficina }})</option>
                    @endforeach
                </select>
                <small class="form-text text-muted">{{ __('devices.delete_employee_hint') }}</small>
            </div>

            <div class="form-group mb-3">
                <label for="idagente">{{ __('devices.employee_pin') }}</label>
                <input type="text" name="idagente" class="form-control" id="idagente" placeholder="{{ __('devices.enter_pin_to_delete') }}" required>
            </div>

            <div class="alert alert-warning">
                <strong>{{ __('common.warning') }}</strong> {{ __('devices.delete_employee_warning') }}
            </div>

            <button type="submit" class="btn btn-danger">{{ __('devices.delete_from_devices') }}</button>
            <a href="{{ route('devices.index') }}" class="btn btn-secondary">{{ __('common.cancel') }}</a>
        </form>
    </div>
@endsection
