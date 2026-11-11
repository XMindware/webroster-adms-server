@extends('layouts.app')

@section('content')
    <div class="container">
        <h2>{{ __('agentes.pull_employees') }}</h2>
        <form method="post" action="{{ route('agentes.runpull') }}">
            @csrf
            <div class="form-group">
                <label for="oficina_id">{{ __('devices.oficina') }}</label>
                <select name="oficina_id" class="form-control" id="oficina_id">
                    @foreach ($oficinas as $oficina)
                        <option value="{{ $oficina->id }}">{{ $oficina->ubicacion }}</option>
                    @endforeach
                </select>
            </div>
            <button type="submit" class="btn btn-primary">{{ __('agentes.run_request') }}</button>
        </form>
    </div>
@endsection
