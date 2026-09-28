@extends('layouts.app')

@section('content')
    <div class="container">
        <h2>{{ $title }}</h2>

        @if(session('success'))
            <div class="alert alert-success">{{ session('success') }}</div>
        @endif
        @if(session('error'))
            <div class="alert alert-danger">{{ session('error') }}</div>
        @endif

        <div class="alert alert-info">
            {{ __('devices.pull_explainer') }}
        </div>

        <form method="post" action="{{ route('devices.runRetrieveFingerData') }}">
            @csrf

            <div class="form-group mb-3">
                <label for="device">{{ __('devices.device') }}</label>
                @include('devices._device_select', ['name' => 'device'])
            </div>

            <fieldset class="mb-3">
                <legend class="h6">{{ __('devices.pull_mode') }}</legend>

                <div class="form-check">
                    <input class="form-check-input" type="radio" name="mode" id="mode-bulk" value="bulk" checked>
                    <label class="form-check-label" for="mode-bulk">
                        <strong>{{ __('devices.pull_mode_bulk') }}</strong>
                        <small class="d-block text-muted">{{ __('devices.pull_mode_bulk_help') }}</small>
                    </label>
                </div>

                <div class="form-check">
                    <input class="form-check-input" type="radio" name="mode" id="mode-roster" value="roster">
                    <label class="form-check-label" for="mode-roster">
                        <strong>{{ __('devices.pull_mode_roster') }}</strong>
                        <small class="d-block text-muted">{{ __('devices.pull_mode_roster_help') }}</small>
                    </label>
                </div>

                <div class="form-check">
                    <input class="form-check-input" type="radio" name="mode" id="mode-pin" value="pin">
                    <label class="form-check-label" for="mode-pin">
                        <strong>{{ __('devices.pull_mode_pin') }}</strong>
                        <small class="d-block text-muted">{{ __('devices.pull_mode_pin_help') }}</small>
                    </label>
                </div>
            </fieldset>

            <div class="form-group mb-3">
                <label for="pin">{{ __('devices.employee_pin') }}</label>
                <input type="text" name="pin" id="pin" class="form-control" placeholder="1234">
            </div>

            <button type="submit" class="btn btn-primary">{{ __('devices.queue_pull') }}</button>
            <a href="{{ route('devices.fingerprints') }}" class="btn btn-secondary">
                {{ __('devices.fingerprints_captured') }}
            </a>
        </form>
    </div>
@endsection
