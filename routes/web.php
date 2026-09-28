<?php

use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\OficinaController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/
use App\Http\Controllers\DeviceController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\iclockController;
use App\Http\Controllers\AgentesController;
use App\Http\Controllers\WebhookController;

Route::controller(AuthController::class)->group(function(){
    Route::get('/registration','registration')->middleware('isLoggedIn');
    Route::post('/registration-user','registerUser')->name('isLoggedIn');
    Route::get('/login','login')->middleware('alreadyLoggedIn')->name('login');
    Route::post('/login-user','loginUser')->name('login-user');
    Route::get('/logout','logout')->name('logout');
    Route::get('/language/{locale}', function ($locale) {
        if (in_array($locale, config('app.available_locales'))) {
            session(['locale' => $locale]);
        }
        return redirect()->back();
    })->name('language.switch');
});

Route::middleware(['auth'])
    ->controller(DeviceController::class)
    ->group(function () {
        Route::get('devices', 'index')->name('devices.index');
        Route::get('devices/create', 'create')->name('devices.create');
        Route::post('devices/store', 'store')->name('devices.store');
        Route::get('devices/delete', 'deleteDevice')->name('devices.delete');
        Route::get('devices/{id}/edit', 'edit')->name('devices.edit');
        Route::post('devices/{id}/update', 'update')->name('devices.update');
        Route::get('devices/{id}/populate', 'populate')->name('devices.populate');
        Route::get('devices/{id}/restart', 'restart')->name('devices.restart');
        Route::get('devices/{id}/set-time', 'setTime')->name('devices.setTime');
        Route::get('devices-log', 'deviceLog')->name('devices.deviceLog');
        Route::get('finger-log', 'fingerLog')->name('devices.fingerLog');
        Route::get('fingerprints', 'fingerprints')->name('devices.fingerprints');
        Route::get('devices/delete/employee', 'deleteEmployeeRecord')->name('devices.deleteEmployeeRecord');
        Route::post('devices/delete/employee', 'runDeleteFingerRecord')->name('devices.runDeleteFingerRecord');
        Route::get('devices/retrieve/fingerdata', 'retrieveFingerData')->name('devices.retrieveFingerData');
        Route::post('devices/retrieve/fingerdata', 'runRetrieveFingerData')->name('devices.runRetrieveFingerData');
        Route::get('devices/{id}/pull-fingerprints', 'pullFingerprints')->name('devices.pullFingerprints');
        Route::post('devices/{id}/push-fingerprints', 'pushFingerprints')->name('devices.pushFingerprints');
        // Ask one terminal about one employee.
        Route::get('devices/query-user', 'queryUser')->name('devices.queryUser');
        Route::post('devices/query-user', 'runQueryUser')->name('devices.runQueryUser');
        // Move an office's enrolment onto a replacement terminal.
        Route::get('devices/migrate', 'migrateDevice')->name('devices.migrateDevice');
        Route::post('devices/migrate', 'runMigrateDevice')->name('devices.runMigrateDevice');
        // Drop individual fingers from one terminal, keeping the user record.
        Route::get('devices/remove-fingerprints', 'removeFingerprints')->name('devices.removeFingerprints');
        Route::post('devices/remove-fingerprints', 'runRemoveFingerprints')->name('devices.runRemoveFingerprints');
        Route::get('/devices/activity/{id}', 'devicesActivity')->name('devices.activity');
        Route::get('/devices/monitor', 'monitor')->name('devices.monitor');
    });

Route::middleware(['auth'])
    ->controller(AttendanceController::class)
    ->group(function () {
        Route::get('attendance', 'index')->name('devices.attendance');
        Route::get('devices/retrieve/attendance/{id}', 'edit')->name('devices.attendance.edit');
        Route::get('devices/retrieve/attendance/fix/{id}', 'fix')->name('devices.attendance.fix');
        Route::post('devices/retrieve/attendance', 'update')->name('devices.attendance.update');
    });

Route::middleware(['auth'])
    ->controller(OficinaController::class)
    ->group(function () {
        Route::get('oficinas', 'index')->name('devices.oficinas');
        Route::get('oficinas/create', 'create')->name('oficinas.create');
        Route::post('oficinas/store', 'store')->name('oficinas.store');
        Route::get('oficinas/{id}/edit', 'edit')->name('oficinas.edit');
        Route::post('oficinas/{id}/update', 'update')->name('oficinas.update');
        Route::get('oficinas/delete', 'destroy')->name('oficinas.delete');
    });

Route::middleware(['auth'])
    ->controller(AgentesController::class)
    ->group(function(){
        Route::get('agentes', 'index')->name('agentes.index');
        Route::get('agentes/pull', 'pullAgentes')->name('agentes.pull');
        Route::post('agentes/runpull', 'runPullAgentes')->name('agentes.runpull');
        Route::post('agentes/runpurge', 'runPurgeRemoved')->name('agentes.runpurge');
    });

Route::middleware(['auth'])
    ->controller(WebhookController::class)
    ->group(function () {
        Route::get('webhooks', 'index')->name('webhooks.index');
        Route::get('webhooks/create', 'create')->name('webhooks.create');
        Route::post('webhooks/store', 'store')->name('webhooks.store');
        Route::get('webhooks/{id}/edit', 'edit')->name('webhooks.edit');
        Route::get('webhooks/{id}/deliveries', 'deliveries')->name('webhooks.deliveries');
        Route::post('webhooks/{id}/update', 'update')->name('webhooks.update');
        Route::post('webhooks/{id}/secret', 'regenerateSecret')->name('webhooks.secret');
        Route::get('webhooks/delete', 'delete')->name('webhooks.delete');
    });

// handshake
Route::get('/iclock/cdata', [iclockController::class, 'handshake']);
// Records pushed by the terminal.
Route::post('/iclock/cdata', [iclockController::class, 'receiveRecords']);
Route::post('/iclock/devicecmd', [iclockController::class, 'deviceCommand']);
Route::get('/iclock/test', [iclockController::class, 'test']);
Route::get('/iclock/getrequest', [iclockController::class, 'getrequest']);
Route::get('/iclock/rtdata', [iclockController::class, 'rtdata']);
Route::post('/iclock/querydata', [iclockController::class, 'querydata']);
Route::post('/iclock/upload-log', [iclockController::class, 'uploadLog']);
Route::get('/api/test', [iclockController::class, 'quickStatus']);




Route::get('/', function () {
    return redirect('devices') ;
});
