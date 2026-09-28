<?php

return [
    'title' => 'Webhooks',
    'create_webhook' => 'Crear Webhook',
    'edit_webhook' => 'Editar Webhook',

    // Form fields
    'device' => 'Dispositivo',
    'select_device' => 'Seleccione un dispositivo',
    'webhook_url' => 'URL del Webhook',
    'url_help' => 'Endpoint que recibirá un POST con los datos de asistencia en cada envío.',

    // Table / messages
    'updated' => 'Actualizado',
    'confirm_delete' => '¿Está seguro de que desea eliminar este webhook?',

    // Mensajes de respuesta
    'created_successfully' => 'Webhook creado correctamente',
    'updated_successfully' => 'Webhook actualizado correctamente',
    'deleted_successfully' => 'Webhook eliminado correctamente',
    'not_found' => 'Webhook no encontrado',

    // Secreto de firma
    'secret' => 'Secreto de firma',
    'secret_help' => 'Cada envío incluye X-Webhook-Signature: HMAC-SHA256 de "{timestamp}.{cuerpo sin procesar}" con este secreto, más X-Webhook-Timestamp. Rechace marcas de tiempo antiguas para evitar repeticiones.',
    'regenerate_secret' => 'Regenerar secreto',
    'secret_regenerated' => 'Secreto de firma regenerado',

    // Historial de envíos
    'delivery_history' => 'Historial de envíos',
    'delivery_history_for' => 'Envíos a :device',
    'view_deliveries' => 'Historial',
    'back_to_webhooks' => 'Volver a webhooks',
    'no_deliveries' => 'Todavía no hay envíos registrados.',
    'result' => 'Resultado',
    'delivered' => 'Entregado',
    'failed' => 'Fallido',
    'records' => 'Registros',
    'attempt' => 'Intento',
    'duration' => 'Duración',
    'error' => 'Error',
];
