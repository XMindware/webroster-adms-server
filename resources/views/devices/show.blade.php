@extends('layouts.app')

@section('content')
    <div class="container">
        <h2>{{ __('devices.show_title') }}</h2>
        <p>{{ __('common.name') }}: {{ $device->name }}</p>
        <p>{{ __('devices.serial_number') }}: {{ $device->no_sn }}</p>
        <p>{{ __('devices.id') }}: {{ $device->idreloj }}</p>
        <p>{{ __('devices.online') }}: {{ $device->online }}</p>
        <a href="{{ route('devices.edit', $device->id) }}" class="btn btn-primary">{{ __('common.edit') }}</a>
    </div>
@endsection
