@extends('layouts.app')

@section('content')
    <div class="container">
        <h2>{{ $title }}</h2>
        <div class="d-flex gap-2 mb-3">
            <a href="{{ route('devices.create') }}" class="btn btn-primary">{{ __('devices.create_device') }}</a>
            <a href="{{ route('devices.deleteEmployeeRecord') }}" class="btn btn-danger">
                <i class="fas fa-user-minus"></i> {{ __('devices.delete_employee_title') }}
            </a>
            <a href="{{ route('devices.queryUser') }}" class="btn btn-info">
                <i class="fas fa-user-search"></i> {{ __('devices.get_user_info') }}
            </a>
            <a href="{{ route('devices.migrateDevice') }}" class="btn btn-warning">
                <i class="fas fa-exchange-alt"></i> {{ __('devices.migrate_device') }}
            </a>
            <a href="{{ route('devices.removeFingerprints') }}" class="btn btn-outline-danger">
                <i class="fas fa-fingerprint"></i> {{ __('devices.remove_fingerprints') }}
            </a>
            <a href="{{ route('devices.monitor') }}" class="btn btn-success">
                <i class="fas fa-traffic-light"></i> {{ __('devices.monitor_status') }}
            </a>
        </div>
        <!-- success message -->
        @if(session('success'))
            <div class="alert alert-success">
                {{ session('success') }}
            </div>
        @endif
        <!-- error message -->
        @if(session('error'))
            <div class="alert alert-danger">
                {{ session('error') }}
            </div>
        @endif
        <table class="table table-bordered data-table" id="devices">
            <thead>
                <tr>
                    <th></th>
                    <th>{{ __('devices.id') }}</th>
                    <th>{{ __('devices.oficina') }}</th>
                    <th>{{ __('devices.ubicacion') }}</th>
                    <th>{{ __('common.online') }}</th>
                    <th>{{ __('devices.last_attendance') }}</th>
                    <th>{{ __('devices.desfases_hoy') }}</th>
                    <th>{{ __('common.actions') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($log as $d)
                    <tr>
                        <td><input type="checkbox" name="device" value="{{ $d->id }}"></td>
                        <td>{{ $d->idreloj }}</td>
                        <td>{{ $d->oficina->ubicacion ?? '' }}</td>
                        <td>{{ $d->name }}</td>
                        <td class="text-center align-middle">
                            @if ($d->online)
                                @php
                                    $diffInMinutes = abs($d->online->diffInMinutes(now()));
                                @endphp

                                @if ($diffInMinutes < 1)
                                    <i class="fas fa-check-circle text-success"></i>
                                @elseif ($diffInMinutes <= 5)
                                    <i class="fas fa-exclamation-circle text-warning"></i>
                                @else
                                    <i class="fas fa-times-circle text-danger"></i>
                                @endif
                            @else
                                <span style="color: gray;">{{ __('common.unknown') }}</span>
                            @endif
                        </td>
                        <td>{{ $d->getLastAttendance() ? $d->getLastAttendance()->created_at->diffForHumans() : __('common.unknown') }}</td>
                        <td>
                        @if (!$d->hayDesfasesHoy())
                                <i class="fas fa-check-circle text-success"></i>                                
                        @else
                            <i class="fas fa-times-circle text-danger"></i>
                        @endif                    
                        </td>
                        <td>
                            <a href="{{ route('devices.populate', ['id' => $d->id ]) }}" class="btn btn-info">{{ __('navigation.employees') }}</a>                            
                            <a href="{{ route('devices.edit', ['id' => $d->id ]) }}" class="btn btn-primary">{{ __('common.edit') }}</a>
                            <a href="{{ route('devices.restart', ['id' => $d->id ]) }}" class="btn btn-primary restart-btn"
                               data-confirm="{{ __('devices.restart_confirm') }}">{{ __('devices.restart') }}</a>
                            <a href="{{ route('devices.setTime', ['id' => $d->id ]) }}" class="btn btn-outline-primary set-time-btn"
                               data-confirm="{{ __('devices.set_time_confirm') }}"
                               title="{{ __('devices.set_time') }}">
                                <i class="fas fa-clock"></i>
                            </a>
                            <a href="{{ route('devices.pullFingerprints', ['id' => $d->id ]) }}" class="btn btn-secondary"
                               title="{{ __('devices.pull_fingerprints') }}">
                                <i class="fas fa-fingerprint"></i>
                            </a>
                            <a href="{{ route('devices.activity', ['id' => $d->id ]) }}" class="btn btn-warning">
                                <i class="fas fa-chart-line"></i>
                            </a>
                            <form method="post" action="{{ route('devices.pushFingerprints', ['id' => $d->id ]) }}" class="d-inline">
                                @csrf
                                <button type="submit" class="btn btn-outline-secondary push-fp-btn"
                                        title="{{ __('devices.push_fingerprints') }}">
                                    <i class="fas fa-share"></i>
                                </button>
                            </form>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        <div class="d-flex justify-content-center">
            <button class="btn btn-danger" id="delete-all">{{ __('devices.update_selected') }}</button>
        </div>
    </div>

    <!--
        Confirm Modal.

        data-bs-dismiss, not data-dismiss: the layout loads Bootstrap 5, which
        renamed the attribute. With the Bootstrap 4 spelling the X in the corner
        did nothing at all - the modal could only be closed with Cancel, which
        works because the script below wires it by hand. Verified in a browser:
        the X left the dialog open.
    -->
    <div class="modal fade" id="confirmModal" tabindex="-1" role="dialog" aria-labelledby="confirmModalLabel" aria-hidden="true">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="confirmModalLabel">{{ __('common.confirm_action') }}</h5>
                    <button type="button" class="close" data-bs-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body" id="confirmText">
                    {{ __('devices.restart_confirm') }}
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" id="cancelModal" data-bs-dismiss="modal">{{ __('common.cancel') }}</button>
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

            // Both actions are one click away from changing a live terminal, so
            // both go through the same confirmation. The wording comes from the
            // button's data-confirm, since a restart and a clock set are not
            // the same question.
            document.querySelectorAll('.restart-btn, .set-time-btn').forEach(function (button) {
                button.addEventListener('click', function (event) {
                    event.preventDefault();
                    targetUrl = this.href;
                    document.getElementById('confirmText').textContent = this.dataset.confirm || '';
                    $('#confirmModal').modal('show');
                });
            });

            document.getElementById('confirmBtn').addEventListener('click', function () {
                window.location.href = targetUrl;
            });

            document.getElementById('cancelModal').addEventListener('click', function () {
                $('#confirmModal').modal('hide');
            });

            // refresh data-table every 25 seconds
            setInterval(function () {
                window.location.reload();
            }, 25000);
        });
    </script>
@endsection
