<?php

use App\Http\Controllers\UsersController;
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

Route::controller(AuthController::class)->group(function(){
    Route::get('/registration','registration')->middleware('isLoggedIn');
    Route::post('/registration-user','registerUser')->name('isLoggedIn');
    Route::get('/login','login')->middleware('alreadyLoggedIn');
    Route::post('/login-user','loginUser')->name('login-user');
    Route::get('/logout','logout')->name('logout');
});

Route::controller(DeviceController::class)->group(function(){
    Route::get('/devices/{id}/edit','Edit')->name('devices.edit');
    Route::get('devices','Index')->name('devices.index');
    Route::get('devices/create','Create')->name('devices.create');
    Route::post('devices/{id}/update','Update')->name('devices.update');
    Route::post('devices/store','Store')->name('devices.store');
    Route::get('devices/{id}/populate','Populate')->name('devices.populate');
    Route::get('devices-log','DeviceLog')->name('devices.DeviceLog');
    Route::get('finger-log','FingerLog')->name('devices.FingerLog');
    Route::get('attendance','Attendance')->name('devices.Attendance');
    Route::get('devices/delete/employee','DeleteEmployeeRecord')->name('devices.DeleteEmployeeRecord');
    Route::post('devices/delete/employee','RunDeleteFingerRecord')->name('devices.RunDeleteFingerRecord');
    Route::get('devices/retrieve/fingerdata','RetrieveFingerData')->name('devices.RetrieveFingerData');
})->middleware('isLoggedIn');

Route::controller(AgentesController::class)->group(function(){    
    Route::get('agentes', 'index')->name('agentes.index');
    Route::get('agentes/pull', 'pullAgentes')->name('agentes.pull');
    Route::post('agentes/runpull', 'runPullAgentes')->name('agentes.runpull');
})->middleware('isLoggedIn');

// handshake
Route::get('/iclock/cdata', [iclockController::class, 'handshake']);
// request dari device
Route::post('/iclock/cdata', [iclockController::class, 'receiveRecords']);

Route::get('/iclock/test', [iclockController::class, 'test']);
Route::get('/iclock/getrequest', [iclockController::class, 'getrequest']);




Route::get('/', function () {
    return redirect('devices') ;
});
