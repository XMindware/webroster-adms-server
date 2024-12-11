@extends('layouts.app')

@section('content')
<div class="container">
    <h2 class="mb-4">Checadas</h2>

    @if(session('status'))
        <div class="alert alert-success">
            {{ session('status') }}
        </div>
    @endif
    <div class="form-group">
        <label for="oficina">Offices</label>
        <form method="GET" action="{{ route('agentes.index', ['selectedOficina' => $selectedOficina]) }}" id="oficinaForm">
            <select name="selectedOficina" class="form-control" id="selectedOficina">
                @foreach ($oficinas as $oficina)
                    <option value="{{ $oficina->idoficina }}" 
                        {{ $oficina->idoficina == $selectedOficina ? 'selected' : '' }}>
                        {{ $oficina->ubicacion }}
                    </option>
                @endforeach
            </select>
        </form>
    </div>
    <br>

    <div class="table-responsive">
        <table class="table table-bordered data-table">
            <thead class="thead-dark">
                <tr>
                    <th>ID</th>
                    <th>SN</th>
                    <th>Employee ID</th>
                    <th>Employee</th>
                    <th>Timestamp</th>
                    <th>Checada Uniqueid</th>
                </tr>
            </thead>
            <tbody>
                @foreach($attendances as $attendance)
                    <tr>
                        <td>{{ $attendance->id }}</td>
                        <td>{{ $attendance->sn }}</td>
                        <td>{{ $attendance->employee_id }}</td>
                        <td>{!! $attendance->getEmployee()?->fullname ?? '<em>Unknown</em>' !!}</td>
                        <td>{{ $attendance->timestamp }}</td>
                        <td>{{ $attendance->response_uniqueid }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    
    <div class="d-felx justify-content-center">
        {{ $attendances->links() }}  
    </div>
</div>
@endsection