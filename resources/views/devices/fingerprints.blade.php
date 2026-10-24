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

        <form method="get" class="row g-2 align-items-end mb-3">
            <div class="col-auto">
                <label for="selectedOficina" class="form-label">{{ __('devices.oficina') }}</label>
                <select name="oficina_id" id="selectedOficina" class="form-control">
                    <option value="">{{ __('devices.all_offices') }}</option>
                    @foreach ($oficinas as $oficina)
                        <option value="{{ $oficina->id }}" @selected($selectedOficinaId == $oficina->id)>
                            {{ $oficina->ubicacion }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="col-auto">
                <label for="pin" class="form-label">{{ __('devices.employee_pin') }}</label>
                <input type="text" name="pin" id="pin" class="form-control" value="{{ $pin }}">
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-primary">{{ __('devices.filter') }}</button>
                <a href="{{ route('devices.retrieveFingerData') }}" class="btn btn-success">
                    {{ __('devices.pull_fingerprints') }}
                </a>
            </div>
        </form>

        <table class="table table-bordered w-100">
            <thead>
                <tr>
                    <th>{{ __('devices.employee_pin') }}</th>
                    <th>{{ __('devices.employee_name') }}</th>
                    <th>{{ __('devices.finger') }}</th>
                    <th>{{ __('devices.device') }}</th>
                    <th>{{ __('devices.template_size') }}</th>
                    <th>{{ __('devices.valid') }}</th>
                    <th>{{ __('devices.source') }}</th>
                    <th>{{ __('devices.captured_at') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($templates as $template)
                    <tr>
                        <td>{{ $template->pin }}</td>
                        <td>{{ $template->employee?->fullname }}</td>
                        <td>#{{ $template->fid }}</td>
                        <td>{{ $template->deviceRow?->name ?: $template->sn }}</td>
                        <td>{{ number_format((int) $template->size) }}</td>
                        <td class="text-center">
                            @if ($template->valid)
                                <i class="fas fa-check-circle text-success"></i>
                            @else
                                <i class="fas fa-times-circle text-danger"></i>
                            @endif
                        </td>
                        <td>
                            <span class="badge bg-{{ $template->source === 'query' ? 'info' : 'secondary' }}">
                                {{ $template->source }}
                            </span>
                        </td>
                        <td>{{ optional($template->captured_at)->format('Y-m-d H:i') }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="text-center text-muted py-4">
                            {{ __('devices.no_templates_yet') }}
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        {{ $templates->links() }}
    </div>
@endsection
