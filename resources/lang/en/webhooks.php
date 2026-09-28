<?php

return [
    'title' => 'Webhooks',
    'create_webhook' => 'Create Webhook',
    'edit_webhook' => 'Edit Webhook',

    // Form fields
    'device' => 'Device',
    'select_device' => 'Select device',
    'webhook_url' => 'Webhook URL',
    'url_help' => 'Endpoint that will receive a POST with attendance data on every push.',

    // Table / messages
    'updated' => 'Updated',
    'confirm_delete' => 'Are you sure you want to delete this webhook?',

    // Response messages
    'created_successfully' => 'Webhook created successfully',
    'updated_successfully' => 'Webhook updated successfully',
    'deleted_successfully' => 'Webhook deleted successfully',
    'not_found' => 'Webhook not found',

    // Signing secret
    'secret' => 'Signing secret',
    'secret_help' => 'Every delivery carries X-Webhook-Signature: HMAC-SHA256 of "{timestamp}.{raw body}" keyed with this secret, plus X-Webhook-Timestamp. Reject timestamps older than a few minutes to stop replays.',
    'regenerate_secret' => 'Regenerate secret',
    'secret_regenerated' => 'Signing secret regenerated',

    // Delivery history
    'delivery_history' => 'Delivery history',
    'delivery_history_for' => 'Deliveries to :device',
    'view_deliveries' => 'History',
    'back_to_webhooks' => 'Back to webhooks',
    'no_deliveries' => 'No deliveries recorded yet.',
    'result' => 'Result',
    'delivered' => 'Delivered',
    'failed' => 'Failed',
    'records' => 'Records',
    'attempt' => 'Attempt',
    'duration' => 'Duration',
    'error' => 'Error',
];
