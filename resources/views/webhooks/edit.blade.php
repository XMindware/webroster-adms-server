@extends('layouts.app')

@section('content')
    <div class="container">
        <h2>{{ $title ?? __('webhooks.edit_webhook') }}</h2>

        @if ($errors->any())
            <div class="alert alert-danger">
                <ul class="mb-0">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="post" action="{{ route('webhooks.update', ['id' => $webhook->id ]) }}">
            @csrf
            <div class="form-group mb-3">
                <label for="device_id">{{ __('webhooks.device') }}</label>
                <select name="device_id" id="device_id" class="form-control" required>
                    @foreach ($devices as $device)
                        <option value="{{ $device->id }}" {{ $webhook->device_id == $device->id ? 'selected' : '' }}>
                            {{ $device->serial_number }}@if($device->idreloj) ({{ $device->idreloj }})@endif
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="form-group mb-3">
                <label for="url">{{ __('webhooks.webhook_url') }}</label>
                <input type="url" name="url" class="form-control" id="url" value="{{ old('url', $webhook->url) }}" placeholder="https://example.com/hooks/attendance">
            </div>
            <div class="form-group mb-3">
                <label>{{ __('webhooks.secret') }}</label>
                <code class="d-block p-2 mb-1 bg-light text-break">{{ $webhook->secret }}</code>
                <small class="form-text text-muted">{{ __('webhooks.secret_help') }}</small>
            </div>
            <button type="submit" class="btn btn-primary">{{ __('common.update') }}</button>
            <a href="{{ route('webhooks.delete', ['id' => $webhook->id ]) }}" class="btn btn-danger">{{ __('common.delete') }}</a>
            <a href="{{ route('webhooks.index') }}" class="btn btn-secondary">{{ __('common.cancel') }}</a>
        </form>

        <form method="post" action="{{ route('webhooks.secret', ['id' => $webhook->id ]) }}" class="mt-3">
            @csrf
            <button type="submit" class="btn btn-warning">{{ __('webhooks.regenerate_secret') }}</button>
        </form>
    </div>
@endsection
