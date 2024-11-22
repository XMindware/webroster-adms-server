@extends('layouts.app')

@section('content')
    <div class="container">
        <h2>{{ $title }}</h2>
        <a href="{{ route('devices.create') }}" class="btn btn-primary mb-3">Create Device</a>
        <table class="table table-bordered data-table" id="devices">
            <thead>
                <tr>
                    <th></th>
                    <th>ID</th>
                    <th>Oficina</th>
                    <th>Ubicacion</th>
                    <th>Online</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($log as $d)
                    <tr>
                        <td><input type="checkbox" name="device" value="{{ $d->id }}"></td>
                        <td>{{ $d->idreloj }}</td>
                        <td>{{ $d->oficina->ubicacion }}</td>
                        <td>{{ $d->name }}</td>
                        <td>{{ $d->online }}</td>
                        <td>
                            <a href="{{ route('devices.populate', ['id' => $d->id ]) }}" class="btn btn-info">Update Employees</a>                            
                            <a href="{{ route('devices.edit', ['id' => $d->id ]) }}" class="btn btn-primary">Edit</a>
                            <!-- dropdown menu TBD
                            <div class="dropdown d-inline-block">
                                <button class="btn btn-secondary dropdown-toggle" type="button" id="dropdownMenuButton" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                                    Actions
                                </button>
                                <div class="dropdown-menu" aria-labelledby="dropdownMenuButton">
                                    <a class="dropdown-item" href="{{ route('devices.DeleteEmployeeRecord', ['id' => $d->id ]) }}" disabled>Delete Employee</a>
                                    <a class="dropdown-item" href="{{ route('devices.RetrieveFingerData', ['id' => $d->id ]) }}" disabled>Pull Fingerprint data</a>
                                </div>
                            </div>
                            -->
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        <div class="d-flex justify-content-center">
            <button class="btn btn-danger" id="delete-all">Update Selected</button>
        </div>
    </div>
@endsection
