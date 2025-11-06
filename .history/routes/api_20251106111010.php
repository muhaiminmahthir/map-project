<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\OverpassController;
use App\Http\Controllers\GeoController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application.
| These routes are loaded by the RouteServiceProvider and all
| are assigned the "api" middleware group.
|
*/

Route::post('/roads', [OverpassController::class, 'roads'])->name('api.roads');
Route::get('/admin-areas', [GeoController::class, 'adminAreas'])->name('api.admin-areas');
Route::get('/admin-geometry/{relId}', [GeoController::class, 'geometry'])->name('api.admin-geometry');
