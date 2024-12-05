@extends('layouts.app')

@section('content')
    <div class="container">
        <h2>{{ $title }}</h2>
        <table class="table table-bordered data-table" id="devices">
            <thead>
                <tr>
                    <th>Id</th>
                    <th>Url</th>
                    <th>Data</th>
                    <th>ID Reloj</th>
                    <th>Fecha</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($deviceLogs as $d)
                    <tr>
                        <td>{{ $d->id }}</td>
                        <td>{{ $d->url }}</td>
                        <td>{{ $d->data }}</td>
                        <td>{{ $d->idreloj }}</td>
                        <td>{{ $d->created_at->toDateTimeString() }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

    </div>
@endsection
