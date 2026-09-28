<?php

return [
    'title' => 'Webhook',
    'create_webhook' => 'Buat Webhook',
    'edit_webhook' => 'Edit Webhook',

    // Form fields
    'device' => 'Perangkat',
    'select_device' => 'Pilih perangkat',
    'webhook_url' => 'URL Webhook',
    'url_help' => 'Endpoint yang akan menerima POST berisi data kehadiran pada setiap pengiriman.',

    // Table / messages
    'updated' => 'Diperbarui',
    'confirm_delete' => 'Apakah Anda yakin ingin menghapus webhook ini?',

    // Pesan respons
    'created_successfully' => 'Webhook berhasil dibuat',
    'updated_successfully' => 'Webhook berhasil diperbarui',
    'deleted_successfully' => 'Webhook berhasil dihapus',
    'not_found' => 'Webhook tidak ditemukan',

    // Secret penanda tangan
    'secret' => 'Rahasia penanda tangan',
    'secret_help' => 'Setiap pengiriman menyertakan X-Webhook-Signature: HMAC-SHA256 dari "{timestamp}.{body mentah}" dengan rahasia ini, plus X-Webhook-Timestamp. Tolak timestamp yang lebih tua dari beberapa menit untuk mencegah replay.',
    'regenerate_secret' => 'Buat ulang rahasia',
    'secret_regenerated' => 'Rahasia penanda tangan berhasil dibuat ulang',

    // Riwayat pengiriman
    'delivery_history' => 'Riwayat pengiriman',
    'delivery_history_for' => 'Pengiriman ke :device',
    'view_deliveries' => 'Riwayat',
    'back_to_webhooks' => 'Kembali ke webhook',
    'no_deliveries' => 'Belum ada pengiriman yang tercatat.',
    'result' => 'Hasil',
    'delivered' => 'Terkirim',
    'failed' => 'Gagal',
    'records' => 'Record',
    'attempt' => 'Percobaan',
    'duration' => 'Durasi',
    'error' => 'Kesalahan',
];
