<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\OverpassController;

//Route::middleware('throttle:30,1')->post('/roads', [OverpassController::class, 'roads']); // 30 requests/min per IP to Overpass
Route::post('/roads', [OverpassController::class, 'roads'])->('api.roads');