@extends('layouts.app')

@section('content')
    <div class="container">
        <h2>{{ $title }}</h2>
        <table class="table table-bordered data-table w-100" id="devices">
            <thead>
                <tr>
                    <th>{{ __('common.id') }}</th>
                    <th>{{ __('common.url') }}</th>
                    <th class="w-50">{{ __('common.data') }}</th>
                    <th>{{ __('devices.id') }}</th>
                    <th class="w-20">{{ __('common.date') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($deviceLogs as $d)
                    <tr>
                        <td>{{ $d->id }}</td>
                        <td>{{ $d->url }}</td>
                        <td class="text-wrap">{{ $d->data }}</td>
                        <td>{{ $d->idreloj }}</td>
                        <td>{{ $d->created_at }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

    </div>
@endsection
