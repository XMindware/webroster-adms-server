<?php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Models\Oficina;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Offices (oficinas): CRUD plus the station-API credentials each office holds.
 *
 * Split out of DeviceController, which had accumulated device operations,
 * office management, attendance browsing and monitoring in a single class.
 */
class OficinaController extends Controller
{
    public function index(Request $request)
    {
        $oficinas = Oficina::all();
        $title = __('oficinas.title');

        return view('oficinas.index', compact('oficinas', 'title'));
    }

    public function create(Request $request)
    {
        return view('oficinas.create');
    }

    public function store(Request $request)
    {
        $oficina = new Oficina();
        $oficina->ubicacion = $request->input('ubicacion');
        $oficina->idempresa = $request->input('idempresa');
        $oficina->idoficina = $request->input('idoficina');
        $oficina->public_url = $request->input('public_url');
        $oficina->token = $request->input('token');
        $oficina->iatacode = $request->input('iatacode');
        $oficina->city_timezone = $request->input('city_timezone');
        $oficina->timezone = $this->normalizeTimezone($request->input('timezone'));
        $oficina->save();

        return $this->redirectWithTimezoneWarning($oficina, __('oficinas.created_successfully'));
    }

    public function edit($id)
    {
        $oficina = Oficina::find($id);

        if (!$oficina) {
            return $this->notFound();
        }

        return view('oficinas.edit', compact('oficina'));
    }

    public function update(Request $request, $id)
    {
        $oficina = Oficina::find($id);

        if (!$oficina) {
            return $this->notFound();
        }

        $oficina->ubicacion = $request->input('ubicacion');
        $oficina->idempresa = $request->input('idempresa');
        $oficina->idoficina = $request->input('idoficina');
        $oficina->city_timezone = $request->input('city_timezone');
        $oficina->public_url = $request->input('public_url');
        $oficina->token = $request->input('token');
        $oficina->iatacode = $request->input('iatacode');
        $oficina->timezone = $this->normalizeTimezone($request->input('timezone'));
        $oficina->save();

        return $this->redirectWithTimezoneWarning($oficina, __('oficinas.updated_successfully'));
    }

    public function destroy(Request $request)
    {
        $oficina = Oficina::find($request->input('id'));

        if (!$oficina) {
            return $this->notFound();
        }

        // Removing an office removes the terminals installed in it.
        Device::where('idoficina', $oficina->idoficina)->delete();

        $oficina->delete();

        return redirect()->route('devices.oficinas')->with('success', __('oficinas.deleted_successfully'));
    }

    private function notFound(): RedirectResponse
    {
        return redirect()->route('devices.oficinas')->with('error', __('oficinas.not_found'));
    }

    /**
     * Redirect to the office list, flagging a generic timezone.
     *
     * Saving is never blocked - "UTC" is a valid identifier - but the operator
     * is warned, because terminals in an office with a generic zone silently
     * stop having their clock corrected. See Oficina::timezoneIsGeneric().
     */
    private function redirectWithTimezoneWarning(Oficina $oficina, string $message): RedirectResponse
    {
        $redirect = redirect()->route('devices.oficinas')->with('success', $message);

        if ($oficina->timezoneIsGeneric()) {
            $redirect->with('warning', __('oficinas.generic_timezone_help'));
        }

        return $redirect;
    }

    /**
     * Validate an office timezone before it reaches the database.
     *
     * PHP and Carbon only accept IANA identifiers ("Asia/Jakarta") or an
     * offset written as "+07:00". Offset-style strings such as "UTC+7",
     * "UTC+07:00" or a value with a stray space ("Asia/Jakarta ") are rejected
     * with Carbon\Exceptions\InvalidTimeZoneException.
     *
     * That exception used to surface only later, in iclockController::handshake()
     * and in every attendance filter that reads the office timezone - so a typo
     * here silently broke the device handshake instead of showing a form error.
     * Reject it at the door instead.
     *
     * @throws ValidationException
     */
    private function normalizeTimezone(?string $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        try {
            new \DateTimeZone($value);
        } catch (\Throwable $e) {
            throw ValidationException::withMessages([
                'timezone' => __('devices.invalid_timezone', ['value' => $value]),
            ]);
        }

        return $value;
    }
}
