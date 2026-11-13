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

        <div class="d-flex gap-2 mb-3">
            <a href="{{ route('devices.index') }}" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> {{ __('devices.back_to_devices') }}
            </a>
        </div>

        <p class="text-muted">{{ __('devices.backups_explainer') }}</p>

        <table class="table table-bordered align-middle">
            <thead>
                <tr>
                    <th>{{ __('devices.device') }}</th>
                    <th>{{ __('devices.backup_generated_at') }}</th>
                    <th>{{ __('devices.backup_size') }}</th>
                    <th>{{ __('devices.backup_contents') }}</th>
                    <th>{{ __('devices.backup_created_by') }}</th>
                    <th>{{ __('common.actions') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($backups as $backup)
                    <tr>
                        <td>
                            {{ $backup->device_name ?: $backup->serial_number }}
                            <div class="small text-muted">{{ $backup->serial_number }}</div>
                        </td>
                        <td>{{ optional($backup->created_at)->format('Y-m-d H:i') }}</td>
                        <td>{{ $backup->humanSize() }}</td>
                        <td>
                            {{ __('devices.backup_contents_value', [
                                'employees' => number_format($backup->employees_count),
                                'fingerprints' => number_format($backup->fingerprints_count),
                                'faces' => number_format($backup->faces_count),
                                'attendances' => number_format($backup->attendances_count),
                            ]) }}
                        </td>
                        <td>{{ $backup->created_by ?: __('devices.value_not_available') }}</td>
                        <td>
                            <a href="{{ route('devices.backups.download', ['id' => $backup->id ]) }}"
                               class="btn btn-primary btn-sm">
                                <i class="fas fa-download"></i> {{ __('devices.backup_download') }}
                            </a>
                            <form method="post" action="{{ route('devices.backups.delete', ['id' => $backup->id ]) }}"
                                  class="d-inline"
                                  onsubmit="return confirm(@js(__('devices.backup_delete_confirm')))">
                                @csrf
                                <button type="submit" class="btn btn-danger btn-sm">
                                    <i class="fas fa-trash"></i> {{ __('devices.backup_delete') }}
                                </button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="text-center text-muted py-4">
                            {{ __('devices.no_backups_yet') }}
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        {{ $backups->links() }}
    </div>
@endsection
