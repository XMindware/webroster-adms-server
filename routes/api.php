<?php

use App\Http\Controllers\Api\DeviceStatusController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

// The iClock/ADMS endpoints a terminal calls live in routes/web.php: terminals
// reach them by plain URL and carry no API token.

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

// WebRoster -> ADMS: read-only device status for one empresa. Authenticated
// with a per-empresa API key (`php artisan empresa-keys:create <idempresa>`)
// rather than a session, because the caller is another server.
Route::middleware('empresa.key')->prefix('empresa')->group(function () {
    Route::get('/devices', [DeviceStatusController::class, 'index']);
    Route::get('/devices/{serial_number}', [DeviceStatusController::class, 'show']);
});
