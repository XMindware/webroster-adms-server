<?php

return [
    'title' => 'Oficinas',
    'create_oficina' => 'Create Oficina',
    'edit_oficina' => 'Edit Oficina',
    'oficina_name' => 'Oficina Name',
    'ubicacion' => 'Ubicacion',
    'location' => 'Location',

    // Form fields
    'idempresa' => 'Company ID',
    'idoficina' => 'Office ID',
    'public_url' => 'Public URL',
    'token' => 'Token',
    'iatacode' => 'IATA Code',
    'city_timezone' => 'City Timezone',
    'timezone' => 'Timezone',
    'last_updated' => 'Last Updated',
    'confirm_delete' => 'Are you sure you want to delete this oficina?',
    
    // Messages
    'created_successfully' => 'Oficina created successfully.',
    'updated_successfully' => 'Oficina updated successfully.',
    'deleted_successfully' => 'Oficina deleted successfully.',
    'error_creating' => 'Error creating oficina.',
    'error_updating' => 'Error updating oficina.',
    'error_deleting' => 'Error deleting oficina.',
    'not_found' => 'Oficina not found',
    'none_configured' => 'No oficinas are configured.',
    'not_found_for_checkin' => 'Office not found, the check-in could not be sent.',

    // Generic timezones (UTC, GMT, Etc/*) are valid identifiers but are never
    // the right answer for an office with a terminal in it.
    'generic_timezone' => 'Generic timezone',
    'generic_timezone_help' => 'UTC and GMT are not local timezones. Terminals here get no clock correction until this is set to a city zone such as Asia/Jakarta.',
];
