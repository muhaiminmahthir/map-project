<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\OverpassController;

//Route::get('/', function () {
//    return view('welcome');
//});

Route::view('/', 'map'); //map route
Route::get('/', [OverpassController::class, 'showMap']);