@extends('layouts.app')

@section('content')
    <div class="container">
        <h2>{{ __('devices.log_finger_title') }}</h2>
        <table class="table table-bordered data-table" id="fingers-log">
            <thead>
                <tr>
                    <th>{{ __('common.id') }}</th>
                    <th>{{ __('common.data') }}</th>
                </tr>
            </thead>
            <tbody>
            </tbody>
        </table>
    </div>
@endsection
