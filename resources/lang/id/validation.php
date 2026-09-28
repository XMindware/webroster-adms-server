<?php

/*
|--------------------------------------------------------------------------
| Validation messages (Indonesian)
|--------------------------------------------------------------------------
|
| Laravel falls back to the English messages shipped with the framework for
| any key that is missing here, so this file only needs to cover the rules
| the application actually uses. Add more as new rules appear.
|
*/

return [
    'required' => 'Kolom :attribute wajib diisi.',
    'email' => 'Kolom :attribute harus berupa alamat email yang valid.',
    'integer' => 'Kolom :attribute harus berupa bilangan bulat.',
    'numeric' => 'Kolom :attribute harus berupa angka.',
    'string' => 'Kolom :attribute harus berupa teks.',
    'boolean' => 'Kolom :attribute harus bernilai benar atau salah.',
    'date' => 'Kolom :attribute bukan tanggal yang valid.',
    'url' => 'Kolom :attribute bukan URL yang valid.',
    'regex' => 'Format kolom :attribute tidak valid.',
    'confirmed' => 'Konfirmasi :attribute tidak cocok.',
    'unique' => 'Nilai :attribute sudah digunakan.',
    'exists' => 'Nilai :attribute yang dipilih tidak valid.',
    'in' => 'Nilai :attribute yang dipilih tidak valid.',

    'min' => [
        'numeric' => 'Kolom :attribute minimal :min.',
        'file' => 'Berkas :attribute minimal :min kilobyte.',
        'string' => 'Kolom :attribute minimal :min karakter.',
        'array' => 'Kolom :attribute minimal :min item.',
    ],
    'max' => [
        'numeric' => 'Kolom :attribute maksimal :max.',
        'file' => 'Berkas :attribute maksimal :max kilobyte.',
        'string' => 'Kolom :attribute maksimal :max karakter.',
        'array' => 'Kolom :attribute maksimal :max item.',
    ],
    'size' => [
        'numeric' => 'Kolom :attribute harus :size.',
        'file' => 'Berkas :attribute harus :size kilobyte.',
        'string' => 'Kolom :attribute harus :size karakter.',
        'array' => 'Kolom :attribute harus berisi :size item.',
    ],
    'between' => [
        'numeric' => 'Kolom :attribute harus antara :min dan :max.',
        'file' => 'Berkas :attribute harus antara :min dan :max kilobyte.',
        'string' => 'Kolom :attribute harus antara :min dan :max karakter.',
        'array' => 'Kolom :attribute harus berisi antara :min dan :max item.',
    ],

    /*
    |--------------------------------------------------------------------------
    | Nama kolom yang mudah dibaca
    |--------------------------------------------------------------------------
    */

    'attributes' => [
        'name' => 'nama',
        'email' => 'email',
        'password' => 'kata sandi',
        'password_confirmation' => 'konfirmasi kata sandi',
        'device_id' => 'perangkat',
        'url' => 'URL',
        'idoficina' => 'kantor',
        'idreloj' => 'ID jam',
        'serial_number' => 'nomor seri',
    ],
];
