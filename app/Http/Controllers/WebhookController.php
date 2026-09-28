<?php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class WebhookController extends Controller
{
    public function index(Request $request)
    {
        $title = __('webhooks.title');
        $webhooks = Webhook::with('device')->orderBy('id', 'DESC')->get();
        return view('webhooks.index', compact('webhooks', 'title'));
    }

    /**
     * Read-only history of what was POSTed to this webhook, newest first.
     *
     * Rows are written by SendWebhookJob through WebhookDeliveryLogger; nothing
     * on this screen writes. It exists because the alternative - reading
     * storage/logs/webhook.log over SSH - is not available to whoever is
     * looking at a terminal that "is not sending anything".
     */
    public function deliveries($id)
    {
        $webhook = Webhook::with('device')->find($id);

        if (!$webhook) {
            return redirect()->route('webhooks.index')->with('error', __('webhooks.not_found'));
        }

        $title = __('webhooks.delivery_history');

        $deliveries = WebhookDelivery::where('webhook_id', $webhook->id)
            ->orderByDesc('id')
            ->paginate(50);

        return view('webhooks.deliveries', compact('webhook', 'deliveries', 'title'));
    }

    public function create(Request $request)
    {
        $title = __('webhooks.create_webhook');
        $devices = Device::whereDoesntHave('webhook')->orderBy('serial_number')->get();
        return view('webhooks.create', compact('devices', 'title'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'device_id' => 'required|integer|exists:devices,id|unique:webhooks,device_id',
            'url' => 'nullable|url|max:255',
        ]);

        Webhook::create([
            'device_id' => $request->input('device_id'),
            'url' => $request->input('url'),
        ]);

        return redirect()->route('webhooks.index')->with('success', __('webhooks.created_successfully'));
    }

    public function edit($id)
    {
        $webhook = Webhook::find($id);
        if (!$webhook) {
            return redirect()->route('webhooks.index')->with('error', __('webhooks.not_found'));
        }
        $title = __('webhooks.edit_webhook');
        $devices = Device::orderBy('serial_number')->get();
        return view('webhooks.edit', compact('webhook', 'devices', 'title'));
    }

    public function update(Request $request, $id)
    {
        $webhook = Webhook::find($id);
        if (!$webhook) {
            return redirect()->route('webhooks.index')->with('error', __('webhooks.not_found'));
        }

        $request->validate([
            'device_id' => 'required|integer|exists:devices,id|unique:webhooks,device_id,' . $webhook->id,
            'url' => 'nullable|url|max:255',
        ]);

        $webhook->device_id = $request->input('device_id');
        $webhook->url = $request->input('url');
        $webhook->save();

        return redirect()->route('webhooks.index')->with('success', __('webhooks.updated_successfully'));
    }

    /**
     * Rotate the signing secret. A secret that may have leaked has to be
     * replaceable; regenerating does not touch the URL or the device.
     */
    public function regenerateSecret($id)
    {
        $webhook = Webhook::find($id);

        if (!$webhook) {
            return redirect()->route('webhooks.index')->with('error', __('webhooks.not_found'));
        }

        $webhook->secret = Str::random(40);
        $webhook->save();

        return redirect()->route('webhooks.index')->with('success', __('webhooks.secret_regenerated'));
    }

    public function delete(Request $request)
    {
        $webhook = Webhook::find($request->input('id'));
        if (!$webhook) {
            return redirect()->route('webhooks.index')->with('error', __('webhooks.not_found'));
        }
        $webhook->delete();
        return redirect()->route('webhooks.index')->with('success', __('webhooks.deleted_successfully'));
    }
}
