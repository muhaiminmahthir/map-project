<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\OverpassController;
use App\Http\Controllers\GeoController;
use App\Http\Controllers\MapViewController;
use App\Http\Controllers\SpatialController;

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
Route::get('/view-state/{key}', [MapViewController::class, 'show'])->name('api.views.load');
Route::post('/view-state/{key}', [MapViewController::class, 'store'])->name('api.views.save');
// Include spatial routes
require __DIR__.'/spatial_routes.php';
Route::post('/views/{viewKey}/roads', [SpatialController::class, 'saveRoad'])->name('spatial.roads.save');