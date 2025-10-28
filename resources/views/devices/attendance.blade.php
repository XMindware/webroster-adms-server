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
        <form method="GET" action="{{ route('devices.attendance') }}" id="oficinaForm">
            <input type="hidden" name="page" value="{{ request('page', 1) }}">
            <select name="selectedOficina" class="form-control" id="selectedOficina">
                @foreach ($oficinas as $oficina)
                    <option value="{{ $oficina->idoficina }}" 
                        {{ $oficina->idoficina == $selectedOficina ? 'selected' : '' }}>
                        {{ $oficina->ubicacion }}
                    </option>
                @endforeach
            </select>
            <input type="checkbox" name="desfasados" id="desfasados" 
                {{ request('desfasados') ? 'checked' : '' }}>
            <label for="desfasados">Diff>20min</label><br>
            <button type="submit" class="btn btn-primary mt-2">Filter</button>
        </form>
    </div>
    <br>

    <div class="table-responsive">
        <table class="table table-bordered data-table">
            <thead class="thead-dark">
                <tr>
                    <th>ID</th>
                    <th>Device</th>
                    <th>Employee ID</th>
                    <th>Employee</th>
                    <th>Timestamp</th>
                    <th>Updated at</th>
                    <th>Diff</th>
                    <th>Checada Uniqueid</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                @foreach($attendances as $attendance)
                    <tr>
                        <td>{{ $attendance->id }}</td>
                        <td>{{ $attendance->device ? $attendance->device->name : 'No registrado' }}</td>
                        <td>{{ $attendance->employee_id }}</td>
                        <td>{!! $attendance->getEmployee()?->fullname ?? '<em>Unknown</em>' !!}</td>
                        <td>{{ $attendance->timestamp }}</td>
                        <td>{{ $attendance->updated_at }}</td>
                        <td class="{{ $attendance->updated_at->diffInMinutes($attendance->timestamp) > 3 ? 'text-danger' : '' }}">
                            {{ $attendance->updated_at->diffForHumans($attendance->timestamp) }}
                        </td>
                        <td>{{ $attendance->response_uniqueid }}</td>
                        <td>
                            <a href="{{ route('devices.attendance.edit', $attendance->id) }}" class="btn btn-primary">Edit</a>
                        </td>
                        <td>
                            <button onclick="fixAttendance({{ $attendance->id }})" class="btn btn-primary">Fix</button>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    
    <div class="d-felx justify-content-center">
        {{ $attendances->links() }}  
    </div>
</div>

<script>
function fixAttendance(id) {
    // Open in new tab
    const newWindow = window.open('{{ url("devices/retrieve/attendance/fix") }}/' + id, '_blank');
    
    // Poll to check when the new window has loaded content
    const checkLoaded = setInterval(() => {
        try {
            // Check if window is accessible and has finished
            if (!newWindow || newWindow.closed) {
                clearInterval(checkLoaded);
                return;
            }
        } catch (e) {
            // Cross-origin restrictions
        }
    }, 1000);
    
    // Also reload the parent page after a delay to refresh data
    setTimeout(() => {
        if (newWindow.closed) {
            window.location.reload();
        }
    }, 3000);
}
</script>
@endsection
