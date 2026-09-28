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
            {{ __('devices.migration_explainer') }}
        </div>

        <form method="post" action="{{ route('devices.runMigrateDevice') }}">
            @csrf

            <div class="form-group mb-3">
                <label for="source">{{ __('devices.migration_source') }}</label>
                @include('devices._device_select', ['name' => 'source'])
                <small class="form-text text-muted">{{ __('devices.migration_source_help') }}</small>
            </div>

            <div class="form-group mb-3">
                <label for="target">{{ __('devices.migration_target') }}</label>
                @include('devices._device_select', ['name' => 'target'])
                <small class="form-text text-muted">{{ __('devices.migration_target_help') }}</small>
            </div>

            <div class="alert alert-warning">
                <strong>{{ __('common.warning') }}</strong> {{ __('devices.migration_warning') }}
            </div>

            <button type="submit" class="btn btn-primary">{{ __('devices.migration_queue') }}</button>
            <a href="{{ route('devices.index') }}" class="btn btn-secondary">{{ __('common.cancel') }}</a>
        </form>
    </div>
@endsection
