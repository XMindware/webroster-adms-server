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
            {{ __('devices.remove_fingerprints_explainer') }}
        </div>

        <div class="alert alert-warning">
            {{ __('devices.remove_fingerprints_warning') }}
        </div>

        <form method="post" action="{{ route('devices.runRemoveFingerprints') }}">
            @csrf

            <div class="form-group mb-3">
                <label for="device">{{ __('devices.device') }}</label>
                @include('devices._device_select', ['name' => 'device'])
            </div>

            <div class="form-group mb-3">
                <label for="pin">{{ __('devices.employee_pin') }}</label>
                <input type="text" name="pin" id="pin" class="form-control" placeholder="1234" required>
            </div>

            <div class="form-group mb-3">
                <label for="finger">{{ __('devices.finger') }}</label>
                <select name="finger" id="finger" class="form-control" required>
                    {{-- Fingers are labelled by index only. The mapping from index
                         to an actual finger is a device convention this project
                         has never verified, and guessing it would invite an
                         operator to delete the wrong one. --}}
                    @foreach (\App\Services\RemoveFingerprintService::FIDS as $fid)
                        <option value="{{ $fid }}">{{ __('devices.finger_n', ['n' => $fid]) }}</option>
                    @endforeach
                    <option value="all">{{ __('devices.finger_all') }}</option>
                </select>
                <small class="form-text text-muted">{{ __('devices.finger_help') }}</small>
            </div>

            <button type="submit" class="btn btn-danger">{{ __('devices.remove_fingerprints_queue') }}</button>
            <a href="{{ route('devices.index') }}" class="btn btn-secondary">{{ __('common.cancel') }}</a>
        </form>
    </div>
@endsection
