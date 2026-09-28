@extends('layouts.app')

@section('content')
    <div class="container">
        <h2>{{ $title }}</h2>

        <p class="text-muted">
            {{ __('webhooks.delivery_history_for', ['device' => optional($webhook->device)->serial_number ?? '#' . $webhook->device_id]) }}
            &mdash; <span class="text-wrap">{{ $webhook->url }}</span>
        </p>

        <a href="{{ route('webhooks.index') }}" class="btn btn-secondary mb-3">{{ __('webhooks.back_to_webhooks') }}</a>

        <table class="table table-bordered">
            <thead>
                <tr>
                    <th>{{ __('common.id') }}</th>
                    <th>{{ __('common.date') }}</th>
                    <th>{{ __('webhooks.result') }}</th>
                    <th>{{ __('common.status') }}</th>
                    <th>{{ __('webhooks.records') }}</th>
                    <th>{{ __('webhooks.attempt') }}</th>
                    <th>{{ __('webhooks.duration') }}</th>
                    <th>{{ __('webhooks.error') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($deliveries as $delivery)
                    <tr>
                        <td>{{ $delivery->id }}</td>
                        <td>{{ $delivery->created_at?->format('Y-m-d H:i:s') }}</td>
                        <td>
                            @if ($delivery->successful)
                                <span class="badge bg-success">{{ __('webhooks.delivered') }}</span>
                            @else
                                <span class="badge bg-danger">{{ __('webhooks.failed') }}</span>
                            @endif
                        </td>
                        {{-- A blank status is not missing data: the receiver was
                             never reached, so there is no code to show. --}}
                        <td>{{ $delivery->status ?? '-' }}</td>
                        <td>{{ $delivery->records }}</td>
                        <td>{{ $delivery->attempt }}</td>
                        <td>{{ $delivery->duration_ms !== null ? $delivery->duration_ms . ' ms' : '-' }}</td>
                        <td class="text-wrap">{{ $delivery->error ?? '-' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="text-center">{{ __('webhooks.no_deliveries') }}</td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        {{ $deliveries->links() }}
    </div>
@endsection
