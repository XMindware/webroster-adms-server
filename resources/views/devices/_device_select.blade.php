{{--
    A device <select>, shared by the fingerprint-pull, user-info and migration
    forms. Expects $name (the form field name); $devices comes from the parent.

    Offline terminals are labelled rather than hidden: a command queued for a
    terminal that is not polling sits in device_commands indefinitely, and
    "nothing happened" is otherwise very hard to tell apart from a bug.
--}}
<select name="{{ $name }}" id="{{ $name }}" class="form-control" required>
    @foreach ($devices as $device)
        <option value="{{ $device->id }}">
            {{ $device->name ?: $device->serial_number }}
            @if ($device->oficina)
                — {{ $device->oficina->ubicacion }}
            @endif
            @if (!$device->online || abs($device->online->diffInMinutes(now())) > 5)
                ({{ __('devices.offline_warning') }})
            @endif
        </option>
    @endforeach
</select>
