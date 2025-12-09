<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * SpatialController
 * 
 * Handles saving and retrieving spatial data from PostGIS.
 * This works alongside your existing MapViewController.
 * 
 * Routes to add in routes/api.php:
 * 
 * Route::prefix('spatial')->group(function () {
 *     Route::get('/views', [SpatialController::class, 'listViews']);
 *     Route::get('/views/{key}', [SpatialController::class, 'getView']);
 *     Route::post('/views', [SpatialController::class, 'createView']);
 *     Route::put('/views/{key}', [SpatialController::class, 'updateView']);
 *     Route::delete('/views/{key}', [SpatialController::class, 'deleteView']);
 *     
 *     Route::post('/views/{viewKey}/areas', [SpatialController::class, 'saveArea']);
 *     Route::post('/views/{viewKey}/roads', [SpatialController::class, 'saveRoad']);
 *     
 *     Route::get('/export/{viewKey}', [SpatialController::class, 'exportGeoJSON']);
 * });
 */
class SpatialController extends Controller
{
    /**
     * Get PostGIS database connection
     */
    private function postgis()
 {
        return DB::connection('postgis');
    }

    /**
     * Convert GeoJSON geometry to PostGIS format
     */
    private function geomFromGeoJSON(array $geometry): string
    {
        $json = json_encode($geometry);
        return "ST_SetSRID(ST_GeomFromGeoJSON('{$json}'), 4326)";
    }

    /**
     * List all views
     */
    public function listViews()
    {
        $views = $this->postgis()
            ->table('map_views')
            ->select('id', 'view_key', 'view_name', 'created_at', 'updated_at')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json(['views' => $views]);
    }

    /**
     * Get a single view with all its areas and roads
     */
    public function getView(string $key)
    {
        $view = $this->postgis()
       ->table('map_views')
            ->where('view_key', $key)
            ->first();

        if (!$view) {
            return response()->json(['error' => 'View not found'], 404);
        }

        // Get areas with geometry as GeoJSON
        $areas = $this->postgis()
            ->table('areas')
            ->select([
                'id',
                'area_name',
                DB::raw("ST_AsGeoJSON(geom)::json as geometry"),
                'created_at'
            ])
            ->where('view_id', $view->id)
            ->get();

            foreach ($areas as $area) {
                if (is_string($area->geometry)) {
                    $area->geometry = json_decode($area->geometry, true);
                }

                $area->roads = $this->postgis()
                    ->table('roads')
                    ->select([
                        'id',
                        'road_name',
                        'road_type',
                        'osm_id',
                        'is_highlighted',
                        DB::raw("ST_AsGeoJSON(geom) as geometry")
                    ])
                    ->where('area_id', $area->id)
                    ->get();

                foreach ($area->roads as $road) {
                    if (is_string($road->geometry)) {
                        $road->geometry = json_decode($road->geometry, true);
                    }
                }

                $area->buildings = $this->postgis()
                    ->table('buildings')
                    ->select([
                        'id',
                        'building_name',
                        'lot_no',
                        DB::raw("ST_AsGeoJSON(geom) as geometry")
                    ])
                    ->where('area_id', $area->id)
                    ->get();

                foreach ($area->buildings as $bld) {
                    if (is_string($bld->geometry)) {
                        $bld->geometry = json_decode($bld->geometry, true);
                    }
                }
            }
            
            $customRoads = $this->postgis()
                ->table('roads')
                ->select([
                    'id',
                    'road_name',
                    'road_type',
                    'osm_id',
                    'is_highlighted',
                    DB::raw("ST_AsGeoJSON(geom) as geometry")
                ])
                ->where('view_id', $view->id)
                ->whereNull('area_id')
                ->get();

            foreach ($customRoads as $road) {
                if (is_string($road->geometry)) {
                    $road->geometry = json_decode($road->geometry, true);
                }
            }

        return response()->json([
            'view' => $view,
            'areas' => $areas,
            'custom_roads' => $customRoads
        ]);
    }

    /**
     * Create a new view
     */
    public function createView(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'key' => 'sometimes|string|max:100'
        ]);

        $key = $validated['key'] ?? 'view_' . time() . '_' . rand(1000, 9999);

        // Check if key already exists
    $exists = $this->postgis()
            ->table('map_views')
            ->where('view_key', $key)
            ->exists();

        if ($exists) {
            return response()->json(['error' => 'View key already exists'], 409);
        }

        $id = $this->postgis()
            ->table('map_views')
            ->insertGetId([
                'view_key' => $key,
                'view_name' => $validated['name'],
                'created_at' => now(),
                'updated_at' => now()
            ]);

        return response()->json([
            'status' => 'created',
            'id' => $id,
            'key' => $key
        ], 201);
    }

    /**
     * Update view name
     */
    public function updateView(Request $request, string $key)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255' 
        ]);

        $updated = $this->postgis()
            ->table('map_views')
            ->where('view_key', $key)
            ->update([
                'view_name' => $validated['name'],
                'updated_at' => now()
            ]);

        if (!$updated) {
            return response()->json(['error' => 'View not found'], 404);
        }

        return response()->json(['status' => 'updated']);
    }

    /**
     * Delete a view and all its data
     */
    public function deleteView(string $key)
    {
        $view = $this->postgis()
            ->table('map_views')
            ->where('view_key', $key)
            ->first();

        if (!$view) {
            return response()->json(['error' => 'View not found'], 404);
        }

        // Cascade delete will handle areas, roads, buildings
        $this->postgis()
            ->table('map_views')
          ->where('id', $view->id)
            ->delete();

        return response()->json(['status' => 'deleted']);
    }

    /**
     * Save an area (polygon) to a view
     */
    public function saveArea(Request $request, string $viewKey)
    {
        $validated = $request->validate([
            'name' => 'nullable|string|max:255',
            'geometry' => 'required|array',
            'geometry.type' => 'required|string|in:Polygon',
            'geometry.coordinates' => 'required|array',
            'roads' => 'sometimes|array',
            'buildings' => 'sometimes|array'
        ]);

        $view = $this->postgis()
            ->table('map_views')
            ->where('view_key', $viewKey)
            ->first();

        if (!$view) {
            return response()->json(['error' => 'View not found'], 404);
        }

        $geomSQL = $this->geomFromGeoJSON($validated['geometry']);

        // Insert area
        $areaId = $this->postgis()
         ->table('areas')
            ->insertGetId([
                'view_id' => $view->id,
                'area_name' => $validated['name'] ?? null,
                'geom' => DB::raw($geomSQL),
                'created_at' => now(),
                'updated_at' => now()
            ]);

        // Insert roads if provided
        if (!empty($validated['roads'])) {
            foreach ($validated['roads'] as $road) {
                if (!empty($road['geometry'])) {
                    $roadGeomSQL = $this->geomFromGeoJSON($road['geometry']);
                    
                    $this->postgis()
                        ->table('roads')
                        ->insert([
                            'area_id' => $areaId,
                            'view_id' => $view->id,
                            'road_name' => $road['name'] ?? $road['label'] ?? null,
                            'road_type' => $road['type'] ?? 'osm',
                            'osm_id' => $road['osm_id'] ?? null,
                            'geom' => DB::raw($roadGeomSQL),
                            'is_highlighted' => $road['is_highlighted'] ?? false,
                            'created_at' => now(),
                            'updated_at' => now()
                        ]);
                }
            }
        }

        // Insert buildings if provided
        if (!empty($validated['buildings'])) {
      foreach ($validated['buildings'] as $building) {
                if (!empty($building['geometry'])) {
                    $buildingGeomSQL = $this->geomFromGeoJSON($building['geometry']);
                    
                    $this->postgis()
                        ->table('buildings')
                        ->insert([
                            'area_id' => $areaId,
                            'building_name' => $building['name'] ?? null,
                            'lot_no' => $building['lot_no'] ?? null,
                            'geom' => DB::raw($buildingGeomSQL),
                            'created_at' => now()
                        ]);
                }
            }
        }

        // Update view timestamp
        $this->postgis()
            ->table('map_views')
            ->where('id', $view->id)
            ->update(['updated_at' => now()]);

        return response()->json([
            'status' => 'saved',
            'area_id' => $areaId
        ], 201);
    }

    /**
     * Update an area's name
     */
    public function updateArea(Request $request, string $viewKey, int $areaId)
    {
        $validated = $request->validate([
            'area_name' => 'nullable|string|max:255'
        ]);

        // Verify the view exists
        $view = $this->postgis()
            ->table('map_views')
            ->where('view_key', $viewKey)
            ->first();

        if (!$view) {
            return response()->json(['error' => 'View not found'], 404);
        }

        // Verify the area belongs to this view
        $area = $this->postgis()
            ->table('areas')
            ->where('id', $areaId)
            ->where('view_id', $view->id)
            ->first();

        if (!$area) {
            return response()->json(['error' => 'Area not found in this view'], 404);
        }

        // Update the area name
        $this->postgis()
            ->table('areas')
            ->where('id', $areaId)
            ->update([
                'area_name' => $validated['area_name'],
                'updated_at' => now()
            ]);

        // Update view timestamp
        $this->postgis()
            ->table('map_views')
            ->where('id', $view->id)
            ->update(['updated_at' => now()]);

        return response()->json([
            'status' => 'updated',
            'area_id' => $areaId,
            'area_name' => $validated['area_name']
        ]);
    }

    /**
     * Save a custom road (not from OSM) - typically drawn by user
     */
    public function saveRoad(Request $request, string $viewKey)
{
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'geometry' => 'required|array',
            'geometry.type' => 'required|string|in:LineString',
            'geometry.coordinates' => 'required|array',
            'area_id' => 'sometimes|integer'
        ]);

        $view = $this->postgis()
            ->table('map_views')
            ->where('view_key', $viewKey)
            ->first();

        if (!$view) {
            return response()->json(['error' => 'View not found'], 404);
        }

        $geomSQL = $this->geomFromGeoJSON($validated['geometry']);

        $roadId = $this->postgis()
            ->table('roads')
            ->insertGetId([
                'area_id' => $validated['area_id'] ?? null,
                'view_id' => $view->id,
                'road_name' => $validated['name'],
                'road_type' => 'custom',
                'geom' => DB::raw($geomSQL),
                'created_at' => now(),
                'updated_at' => now()
            ]);

        return response()->json([
            'status' => 'saved',
        'road_id' => $roadId
        ], 201);
    }

    /**
     * Export a view as GeoJSON FeatureCollection
     * This can be used by external tools or downloaded
     */
    public function exportGeoJSON(string $viewKey)
    {
        $view = $this->postgis()
            ->table('map_views')
            ->where('view_key', $viewKey)
            ->first();

        if (!$view) {
            return response()->json(['error' => 'View not found'], 404);
        }

        $features = [];

        // Export areas
        $areas = $this->postgis()
            ->table('areas')
            ->select([
                'id',
                'area_name',
                DB::raw("ST_AsGeoJSON(geom)::json as geometry")
            ])
            ->where('view_id', $view->id)
            ->get();

        foreach ($areas as $area) {
       $features[] = [
                'type' => 'Feature',
                'geometry' => json_decode($area->geometry, true),
                'properties' => [
                    'id' => $area->id,
                    'name' => $area->area_name,
                    'feature_type' => 'area'
                ]
            ];
        }

        // Export roads
        $roads = $this->postgis()
            ->table('roads')
            ->join('areas', 'roads.area_id', '=', 'areas.id')
            ->where('areas.view_id', $view->id)
            ->select([
                'roads.id',
                'roads.road_name',
                'roads.road_type',
                'roads.osm_id',
                'roads.is_highlighted',
                DB::raw("ST_AsGeoJSON(roads.geom)::json as geometry")
            ])
            ->get();

        foreach ($roads as $road) {
            $features[] = [
                'type' => 'Feature',
                'geometry' => json_decode($road->geometry, true),
                'properties' => [
                    'id' => $road->id,
                    'name' => $road->road_name,
                'type' => $road->road_type,
                    'osm_id' => $road->osm_id,
                    'is_highlighted' => $road->is_highlighted,
                    'feature_type' => 'road'
                ]
            ];
        }

        $featureCollection = [
            'type' => 'FeatureCollection',
            'name' => $view->view_name,
            'features' => $features
        ];

        return response()->json($featureCollection)
            ->header('Content-Disposition', 'attachment; filename="' . $viewKey . '.geojson"');
    }

    /**
     * Sync current view data from the existing JSON storage to PostGIS
     * This is a migration helper - call once to move existing data
     */
    public function syncFromJSON(Request $request)
    {
        $validated = $request->validate([
            'views' => 'required|array'
        ]);

        $imported = 0;

        foreach ($validated['views'] as $key => $viewData) {
            // Create or update view
            $viewId = $this->postgis()
           ->table('map_views')
                ->updateOrInsert(
                    ['view_key' => $key],
                    [
                        'view_name' => $viewData['name'] ?? $key,
                        'updated_at' => now()
                    ]
                );

            $view = $this->postgis()
                ->table('map_views')
                ->where('view_key', $key)
                ->first();

            // Import areas
            if (!empty($viewData['areas'])) {
                foreach ($viewData['areas'] as $areaData) {
                    if (!empty($areaData['geometry'])) {
                        $geomSQL = $this->geomFromGeoJSON($areaData['geometry']);

                        $areaId = $this->postgis()
                            ->table('areas')
                            ->insertGetId([
                                'view_id' => $view->id,
                                'area_name' => $areaData['customName'] ?? null,
                                'geom' => DB::raw($geomSQL),
                                'created_at' => now(),
                                'updated_at' => now()
                            ]);

                        // Note: Roads from JSON don't have geometry
                        // They need to be fetched from Overpass again
                        // or we store just the metadata


                        $imported++;
                    }
                }
            }

            // Import custom roads
            if (!empty($viewData['customRoads'])) {
                foreach ($viewData['customRoads'] as $roadData) {
                    if (!empty($roadData['geometry'])) {
                        $geomSQL = $this->geomFromGeoJSON($roadData['geometry']);

                        $this->postgis()
                            ->table('roads')
                            ->insert([
                                'area_id' => null, // Custom roads may not belong to an area
                                'road_name' => $roadData['name'] ?? 'Unnamed',
                                'road_type' => 'custom',
                                'geom' => DB::raw($geomSQL),
                                'is_highlighted' => $roadData['isHighlighted'] ?? false,
                                'created_at' => now(),
                                'updated_at' => now()
                            ]);

                        $imported++;
                    }
                }
            }
        }

        return response()->json([
            'status' => 'synced',
            'imported_count' => $imported
        ]);
    }
}