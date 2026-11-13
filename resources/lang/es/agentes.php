<?php

return [
    'title' => 'Empleados',
    'employee' => 'Empleado',
    'employee_id' => 'ID del Empleado',
    'employee_name' => 'Nombre del Empleado',
    'pull_employees' => 'Obtener Empleados',
    'pull_from_device' => 'Obtener desde Dispositivo',
    
    // Messages
    'pulled_successfully' => 'Empleados obtenidos exitosamente.',
    'error_pulling' => 'Error al obtener empleados del dispositivo.',
    'pull_complete' => 'Obtención completada',
    'pull_failed' => 'Obtención fallida',
    'pulled' => 'Obtenidos de la estación',
    'new_agents' => 'Agentes nuevos',
    'updated_agents' => 'Agentes actualizados',
    'restored_agents' => 'Agentes restaurados',
    'removed_agents' => 'Agentes marcados para eliminar',
    'commands_queued' => 'Comandos encolados a dispositivos',
    'close' => 'Cerrar',

    // Removal / purge
    'purge_removed' => 'Eliminar de dispositivos',
    'purge_confirm' => '¿Encolar la eliminación en los dispositivos de los agentes dados de baja en esta estación?',
    'purge_complete' => 'Eliminación encolada',
    'purge_failed' => 'Eliminación fallida',
    'device_deletes_queued' => 'Comandos de eliminación encolados',

    // Tables
    'id_empresa' => 'ID Empresa',
    'id_oficina' => 'ID Oficina',
    'id_agente' => 'ID Agente',
    'shortname' => 'Shortname',
    'fullname' => 'Fullname',
    'last_update' => 'Last Update',
    'fingerprints' => 'Huellas',
    'face' => 'Rostro',
    'biometrics_pulled' => 'Descargado (:count)',
    'biometrics_none' => 'Sin descargar',
    'run_request' => 'Ejecutar Solicitud',

    // Mensajes de respuesta
    'office_not_found' => 'Oficina no encontrada.',
    'station_missing_url' => 'La oficina ":oficina" (:idempresa/:idoficina) no tiene URL pública configurada. Configúrela en el formulario de la oficina.',
    'station_url_is_this_server' => 'La oficina ":oficina" apunta su URL pública a este servidor ADMS (:host) en lugar de a la aplicación de la estación. Reemplácela en el formulario de la oficina por la dirección de la aplicación de la estación.',
    'station_unexpected_body' => 'La estación respondió HTTP :status pero el cuerpo no es JSON: :body',
    'station_http_error' => 'HTTP :status: :body',
];
