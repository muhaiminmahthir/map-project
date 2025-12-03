<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\SpatialController;

/*
|--------------------------------------------------------------------------
| Spatial API Routes
|--------------------------------------------------------------------------
|
| Add these routes to your routes/api.php file
|
*/

Route::prefix('spatial')->group(function () {
    // View management
    Route::get('/views', [SpatialController::class, 'listViews'])
        ->name('spatial.views.list');
    
    Route::get('/views/{key}', [SpatialController::class, 'getView'])
        ->name('spatial.views.show');
    
    Route::post('/views', [SpatialController::class, 'createView'])
        ->name('spatial.views.create');
    
    Route::put('/views/{key}', [SpatialController::class, 'updateView'])
        ->name('spatial.views.update');
    
    Route::delete('/views/{key}', [SpatialController::class, 'deleteView'])
        ->name('spatial.views.delete');
    
    // Saving geometry
    Route::post('/views/{viewKey}/areas', [SpatialController::class, 'saveArea'])
        ->name('spatial.areas.save');
        
    // Export
    Route::get('/export/{viewKey}', [SpatialController::class, 'exportGeoJSON'])
        ->name('spatial.export');
    
    // Migration helper (one-time use)
    Route::post('/sync-from-json', [SpatialController::class, 'syncFromJSON'])
        ->name('spatial.sync');
});
