<?php

/*
|--------------------------------------------------------------------------
| Validation messages (Spanish)
|--------------------------------------------------------------------------
|
| Laravel falls back to the English messages shipped with the framework for
| any key that is missing here, so this file only needs to cover the rules
| the application actually uses. Add more as new rules appear.
|
*/

return [
    'required' => 'El campo :attribute es obligatorio.',
    'email' => 'El campo :attribute debe ser un correo electrónico válido.',
    'integer' => 'El campo :attribute debe ser un número entero.',
    'numeric' => 'El campo :attribute debe ser un número.',
    'string' => 'El campo :attribute debe ser una cadena de texto.',
    'boolean' => 'El campo :attribute debe ser verdadero o falso.',
    'date' => 'El campo :attribute no es una fecha válida.',
    'url' => 'El campo :attribute no es una URL válida.',
    'regex' => 'El formato del campo :attribute no es válido.',
    'confirmed' => 'La confirmación de :attribute no coincide.',
    'unique' => 'El valor de :attribute ya está en uso.',
    'exists' => 'El valor seleccionado de :attribute no es válido.',
    'in' => 'El valor seleccionado de :attribute no es válido.',

    'min' => [
        'numeric' => 'El campo :attribute debe ser al menos :min.',
        'file' => 'El archivo :attribute debe pesar al menos :min kilobytes.',
        'string' => 'El campo :attribute debe tener al menos :min caracteres.',
        'array' => 'El campo :attribute debe tener al menos :min elementos.',
    ],
    'max' => [
        'numeric' => 'El campo :attribute no debe ser mayor que :max.',
        'file' => 'El archivo :attribute no debe pesar más de :max kilobytes.',
        'string' => 'El campo :attribute no debe tener más de :max caracteres.',
        'array' => 'El campo :attribute no debe tener más de :max elementos.',
    ],
    'size' => [
        'numeric' => 'El campo :attribute debe ser :size.',
        'file' => 'El archivo :attribute debe pesar :size kilobytes.',
        'string' => 'El campo :attribute debe tener :size caracteres.',
        'array' => 'El campo :attribute debe contener :size elementos.',
    ],
    'between' => [
        'numeric' => 'El campo :attribute debe estar entre :min y :max.',
        'file' => 'El archivo :attribute debe pesar entre :min y :max kilobytes.',
        'string' => 'El campo :attribute debe tener entre :min y :max caracteres.',
        'array' => 'El campo :attribute debe tener entre :min y :max elementos.',
    ],

    /*
    |--------------------------------------------------------------------------
    | Friendly field names
    |--------------------------------------------------------------------------
    */

    'attributes' => [
        'name' => 'nombre',
        'email' => 'correo electrónico',
        'password' => 'contraseña',
        'password_confirmation' => 'confirmación de contraseña',
        'device_id' => 'dispositivo',
        'url' => 'URL',
        'idoficina' => 'oficina',
        'idreloj' => 'ID del reloj',
        'serial_number' => 'número de serie',
    ],
];
