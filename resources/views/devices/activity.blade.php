@extends('layouts.app')

@section('content')
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <form method="GET" action="{{ route('devices.activity', ['id' => $id ]) }}">
        <label for="range">{{ __('devices.select_time_range') }}</label>
        <select name="range" id="range" onchange="this.form.submit()">
            <option value="1h" {{ $range === '1h' ? 'selected' : '' }}>{{ __('devices.range_1h') }}</option>
            <option value="6h" {{ $range === '6h' ? 'selected' : '' }}>{{ __('devices.range_6h') }}</option>
            <option value="1d" {{ $range === '1d' ? 'selected' : '' }}>{{ __('devices.range_1d') }}</option>
            <option value="7d" {{ $range === '7d' ? 'selected' : '' }}>{{ __('devices.range_7d') }}</option>
            <option value="30d" {{ $range === '30d' ? 'selected' : '' }}>{{ __('devices.range_30d') }}</option>
            <option value="90d" {{ $range === '90d' ? 'selected' : '' }}>{{ __('devices.range_90d') }}</option>
            <option value="all" {{ $range === 'all' ? 'selected' : '' }}>{{ __('devices.range_all') }}</option>
        </select>
    </form>

    <canvas id="deviceChart" width="400" height="140"></canvas>

    <script>
    const ctx = document.getElementById('deviceChart').getContext('2d');

    const labels = {!! json_encode(array_keys($data)) !!};
    const counts = {!! json_encode(array_values($data)) !!};

    const chart = new Chart(ctx, {
        type: 'line',
        data: {
            labels: labels,
            datasets: [{
                label: '{{ $range === "1h" || $range === "6h" || $range === "1d" ? __('devices.reports_per_minute') : ($range === "7d" ? __('devices.reports_per_hour') : __('devices.reports_per_day')) }}',
                data: counts,
                borderWidth: 2,
                fill: false,
                borderColor: 'blue',
                tension: 0.3,
                pointRadius: 2,
            }]
        },
        options: {
            scales: {
                x: {
                    title: { display: true, text: '{{ __('common.time') }}' },
                    ticks: {
                        maxRotation: 90,
                        minRotation: 45
                    }
                },
                y: {
                    title: { display: true, text: '{{ __('devices.chart_reports') }}' },
                    beginAtZero: true
                }
            }
        }
    });
    </script>
@endsection
