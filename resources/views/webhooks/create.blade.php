@extends('layouts.app')

@section('content')
    <div class="container">
        <h2>{{ $title ?? __('webhooks.create_webhook') }}</h2>

        @if ($errors->any())
            <div class="alert alert-danger">
                <ul class="mb-0">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="post" action="{{ route('webhooks.store') }}">
            @csrf
            <div class="form-group mb-3">
                <label for="device_id">{{ __('webhooks.device') }}</label>
                <select name="device_id" id="device_id" class="form-control" required>
                    <option value="">{{ __('webhooks.select_device') }}</option>
                    @foreach ($devices as $device)
                        <option value="{{ $device->id }}" {{ old('device_id') == $device->id ? 'selected' : '' }}>
                            {{ $device->serial_number }}@if($device->idreloj) ({{ $device->idreloj }})@endif
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="form-group mb-3">
                <label for="url">{{ __('webhooks.webhook_url') }}</label>
                <input type="url" name="url" class="form-control" id="url" value="{{ old('url') }}" placeholder="https://example.com/hooks/attendance">
                <small class="form-text text-muted">{{ __('webhooks.url_help') }}</small>
            </div>
            <button type="submit" class="btn btn-primary">{{ __('common.create') }}</button>
            <a href="{{ route('webhooks.index') }}" class="btn btn-secondary">{{ __('common.cancel') }}</a>
        </form>
    </div>
@endsection
