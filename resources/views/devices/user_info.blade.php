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
            {{ __('devices.query_user_explainer') }}
        </div>

        <form method="post" action="{{ route('devices.runQueryUser') }}">
            @csrf

            <div class="form-group mb-3">
                <label for="device">{{ __('devices.device') }}</label>
                @include('devices._device_select', ['name' => 'device'])
            </div>

            <div class="form-group mb-3">
                <label for="pin">{{ __('devices.employee_pin') }}</label>
                <input type="text" name="pin" id="pin" class="form-control" placeholder="1234" required>
            </div>

            <div class="form-check mb-3">
                <input class="form-check-input" type="checkbox" name="with_templates" id="with_templates" value="1" checked>
                <label class="form-check-label" for="with_templates">
                    {{ __('devices.query_with_templates') }}
                    <small class="d-block text-muted">{{ __('devices.query_with_templates_help') }}</small>
                </label>
            </div>

            <button type="submit" class="btn btn-primary">{{ __('devices.query_user_queue') }}</button>
            <a href="{{ route('devices.index') }}" class="btn btn-secondary">{{ __('common.cancel') }}</a>
        </form>
    </div>
@endsection
