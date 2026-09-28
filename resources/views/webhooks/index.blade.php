@extends('layouts.app')

@section('content')
    <div class="container">
        <h2>{{ $title }}</h2>
        <a href="{{ route('webhooks.create') }}" class="btn btn-primary mb-3">{{ __('webhooks.create_webhook') }}</a>
        @if(session('success'))
            <div class="alert alert-success">{{ session('success') }}</div>
        @endif
        @if(session('error'))
            <div class="alert alert-danger">{{ session('error') }}</div>
        @endif
        <table class="table table-bordered data-table" id="webhooks">
            <thead>
                <tr>
                    <th>{{ __('common.id') }}</th>
                    <th>{{ __('webhooks.device') }}</th>
                    <th>{{ __('common.url') }}</th>
                    <th>{{ __('webhooks.updated') }}</th>
                    <th>{{ __('common.actions') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($webhooks as $webhook)
                    <tr>
                        <td>{{ $webhook->id }}</td>
                        <td>{{ optional($webhook->device)->serial_number ?? '#' . $webhook->device_id }}</td>
                        <td class="text-wrap">{{ $webhook->url }}</td>
                        <td>{{ $webhook->updated_at?->diffForHumans() }}</td>
                        <td>
                            <a href="{{ route('webhooks.deliveries', ['id' => $webhook->id ]) }}" class="btn btn-secondary">{{ __('webhooks.view_deliveries') }}</a>
                            <a href="{{ route('webhooks.edit', ['id' => $webhook->id ]) }}" class="btn btn-primary">{{ __('common.edit') }}</a>
                            <a href="{{ route('webhooks.delete', ['id' => $webhook->id ]) }}" class="btn btn-danger delete-btn">{{ __('common.delete') }}</a>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="modal fade" id="confirmModal" tabindex="-1" role="dialog" aria-labelledby="confirmModalLabel" aria-hidden="true">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="confirmModalLabel">{{ __('common.confirm_action') }}</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="{{ __('common.close') }}">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    {{ __('webhooks.confirm_delete') }}
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" id="cancelModal" data-dismiss="modal">{{ __('common.cancel') }}</button>
                    <button type="button" class="btn btn-primary" id="confirmBtn">{{ __('common.confirm') }}</button>
                </div>
            </div>
        </div>
    </div>
@endsection

@section('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            let targetUrl = '';
            document.querySelectorAll('.delete-btn').forEach(function (button) {
                button.addEventListener('click', function (event) {
                    event.preventDefault();
                    targetUrl = this.href;
                    $('#confirmModal').modal('show');
                });
            });
            document.getElementById('confirmBtn').addEventListener('click', function () {
                window.location.href = targetUrl;
            });
            document.getElementById('cancelModal').addEventListener('click', function () {
                $('#confirmModal').modal('hide');
            });
        });
    </script>
@endsection
