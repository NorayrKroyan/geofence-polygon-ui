<?php

use App\Http\Controllers\Api\GeofenceController;
use App\Http\Controllers\Api\GpsPointController;
use App\Http\Controllers\Api\MobileBootstrapController;
use App\Http\Controllers\Api\SettingController;
use Illuminate\Support\Facades\Route;

Route::get('/settings', [SettingController::class, 'index']);
Route::put('/settings', [SettingController::class, 'upsert']);

Route::get('/geofences', [GeofenceController::class, 'index']);
Route::post('/geofences', [GeofenceController::class, 'store']);
Route::put('/geofences/{geofence}', [GeofenceController::class, 'update']);
Route::delete('/geofences/{geofence}', [GeofenceController::class, 'destroy']);

Route::get('/gps-points', [GpsPointController::class, 'index']);
Route::post('/gps-points', [GpsPointController::class, 'store']);
Route::post('/gps-points/test', [GpsPointController::class, 'storeTest']);

Route::get('/mobile/bootstrap', [MobileBootstrapController::class, 'index']);
