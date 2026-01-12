<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\SpatialController;

/*
|--------------------------------------------------------------------------
| Spatial API Routes
|--------------------------------------------------------------------------
|
| Routes for the simplified map editor with areas, roads, and markers.
| Include this file in routes/api.php
|
*/

Route::prefix('spatial')->group(function () {
    
    // ==========================================
    // View Management
    // ==========================================
    
    // List all views
    Route::get('/views', [SpatialController::class, 'listViews'])
        ->name('spatial.views.list');
    
    // Get single view with all features
    Route::get('/views/{key}', [SpatialController::class, 'getView'])
        ->name('spatial.views.show');
    
    // Create new view
    Route::post('/views', [SpatialController::class, 'createView'])
        ->name('spatial.views.create');
    
    // Update view (rename)
    Route::put('/views/{key}', [SpatialController::class, 'updateView'])
        ->name('spatial.views.update');
    
    // Delete view
    Route::delete('/views/{key}', [SpatialController::class, 'deleteView'])
        ->name('spatial.views.delete');
    
    // ==========================================
    // Areas (Polygons - houses/lots)
    // ==========================================
    
    // Save new area
    Route::post('/views/{viewKey}/areas', [SpatialController::class, 'saveArea'])
        ->name('spatial.areas.save');
    
    // Update area (rename)
    Route::put('/views/{viewKey}/areas/{areaId}', [SpatialController::class, 'updateArea'])
        ->name('spatial.areas.update');
    
    // ==========================================
    // Roads (Polylines)
    // ==========================================
    
    // Save new road
    Route::post('/views/{viewKey}/roads', [SpatialController::class, 'saveRoad'])
        ->name('spatial.roads.save');
    
    // Update road (rename)
    Route::put('/views/{viewKey}/roads/{roadId}', [SpatialController::class, 'updateRoad'])
        ->name('spatial.roads.update');
    
    // ==========================================
    // Markers (Points - manholes, poles, etc.)
    // ==========================================
    
    // Save new marker
    Route::post('/views/{viewKey}/markers', [SpatialController::class, 'saveMarker'])
        ->name('spatial.markers.save');
    
    // Update marker
    Route::put('/views/{viewKey}/markers/{markerId}', [SpatialController::class, 'updateMarker'])
        ->name('spatial.markers.update');
    
    // ==========================================
    // Export
    // ==========================================
    
    // Export view as GeoJSON
    Route::get('/export/{viewKey}', [SpatialController::class, 'exportGeoJSON'])
        ->name('spatial.export');
    
    // ==========================================
    // Migration Helper (one-time use)
    // ==========================================
    
    Route::post('/sync-from-json', [SpatialController::class, 'syncFromJSON'])
        ->name('spatial.sync');
});
