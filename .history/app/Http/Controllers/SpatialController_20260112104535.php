<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * SpatialController
 * 
 * Handles saving and retrieving spatial data from PostGIS.
 * Supports: Views, Areas (polygons), Roads (polylines), Markers (points)
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
     * Get a single view with all its areas, roads, and markers
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
        $areas = [];
        try {
            $areasRaw = $this->postgis()
                ->table('areas')
                ->select([
                    'id',
                    'area_name',
                    DB::raw("ST_AsGeoJSON(geom)::json as geometry"),
                    'created_at'
                ])
                ->where('view_id', $view->id)
                ->get();

            foreach ($areasRaw as $area) {
                if (is_string($area->geometry)) {
                    $area->geometry = json_decode($area->geometry, true);
                }
                $areas[] = $area;
            }
        } catch (\Exception $e) {
            Log::warning('Failed to load areas for view ' . $key . ': ' . $e->getMessage());
        }

        // Get roads (custom drawn roads)
        $roads = [];
        try {
            $roadsRaw = $this->postgis()
                ->table('roads')
                ->select([
                    'id',
                    'road_name',
                    'road_type',
                    DB::raw("ST_AsGeoJSON(geom) as geometry")
                ])
                ->where('view_id', $view->id)
                ->get();

            foreach ($roadsRaw as $road) {
                if (is_string($road->geometry)) {
                    $road->geometry = json_decode($road->geometry, true);
                }
                $roads[] = $road;
            }
        } catch (\Exception $e) {
            Log::warning('Failed to load roads for view ' . $key . ': ' . $e->getMessage());
        }

        // Get markers (may not exist if migration hasn't run)
        $markers = [];
        try {
            $markersRaw = $this->postgis()
                ->table('markers')
                ->select([
                    'id',
                    'marker_name',
                    'marker_type',
                    'description',
                    DB::raw("ST_AsGeoJSON(geom) as geometry")
                ])
                ->where('view_id', $view->id)
                ->get();

            foreach ($markersRaw as $marker) {
                if (is_string($marker->geometry)) {
                    $marker->geometry = json_decode($marker->geometry, true);
                }
                $markers[] = $marker;
            }
        } catch (\Exception $e) {
            // Markers table might not exist yet - this is OK
            Log::info('Markers table not available for view ' . $key . ': ' . $e->getMessage());
        }

        return response()->json([
            'view' => $view,
            'areas' => $areas,
            'roads' => $roads,
            'markers' => $markers
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

        // Cascade delete will handle areas, roads, markers
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
            'geometry.coordinates' => 'required|array'
        ]);

        $view = $this->postgis()
            ->table('map_views')
            ->where('view_key', $viewKey)
            ->first();

        if (!$view) {
            return response()->json(['error' => 'View not found'], 404);
        }

        $geomSQL = $this->geomFromGeoJSON($validated['geometry']);

        $areaId = $this->postgis()
            ->table('areas')
            ->insertGetId([
                'view_id' => $view->id,
                'area_name' => $validated['name'] ?? null,
                'geom' => DB::raw($geomSQL),
                'created_at' => now(),
                'updated_at' => now()
            ]);

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

        $view = $this->postgis()
            ->table('map_views')
            ->where('view_key', $viewKey)
            ->first();

        if (!$view) {
            return response()->json(['error' => 'View not found'], 404);
        }

        $area = $this->postgis()
            ->table('areas')
            ->where('id', $areaId)
            ->where('view_id', $view->id)
            ->first();

        if (!$area) {
            return response()->json(['error' => 'Area not found in this view'], 404);
        }

        $this->postgis()
            ->table('areas')
            ->where('id', $areaId)
            ->update([
                'area_name' => $validated['area_name'],
                'updated_at' => now()
            ]);

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
     * Save a road (polyline) to a view
     */
    public function saveRoad(Request $request, string $viewKey)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'geometry' => 'required|array',
            'geometry.type' => 'required|string|in:LineString',
            'geometry.coordinates' => 'required|array'
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
                'view_id' => $view->id,
                'area_id' => null,
                'road_name' => $validated['name'],
                'road_type' => 'custom',
                'geom' => DB::raw($geomSQL),
                'created_at' => now(),
                'updated_at' => now()
            ]);

        $this->postgis()
            ->table('map_views')
            ->where('id', $view->id)
            ->update(['updated_at' => now()]);

        return response()->json([
            'status' => 'saved',
            'road_id' => $roadId
        ], 201);
    }

    /**
     * Update a road's name
     */
    public function updateRoad(Request $request, string $viewKey, int $roadId)
    {
        $validated = $request->validate([
            'road_name' => 'nullable|string|max:255'
        ]);

        $view = $this->postgis()
            ->table('map_views')
            ->where('view_key', $viewKey)
            ->first();

        if (!$view) {
            return response()->json(['error' => 'View not found'], 404);
        }

        $road = $this->postgis()
            ->table('roads')
            ->where('id', $roadId)
            ->where('view_id', $view->id)
            ->first();

        if (!$road) {
            return response()->json(['error' => 'Road not found in this view'], 404);
        }

        $this->postgis()
            ->table('roads')
            ->where('id', $roadId)
            ->update([
                'road_name' => $validated['road_name'],
                'updated_at' => now()
            ]);

        $this->postgis()
            ->table('map_views')
            ->where('id', $view->id)
            ->update(['updated_at' => now()]);

        return response()->json([
            'status' => 'updated',
            'road_id' => $roadId,
            'road_name' => $validated['road_name']
        ]);
    }

    /**
     * Save a marker (point) to a view
     */
    public function saveMarker(Request $request, string $viewKey)
    {
        $validated = $request->validate([
            'name' => 'nullable|string|max:255',
            'marker_type' => 'required|string|max:50',
            'description' => 'nullable|string|max:500',
            'geometry' => 'required|array',
            'geometry.type' => 'required|string|in:Point',
            'geometry.coordinates' => 'required|array|size:2'
        ]);

        $view = $this->postgis()
            ->table('map_views')
            ->where('view_key', $viewKey)
            ->first();

        if (!$view) {
            return response()->json(['error' => 'View not found'], 404);
        }

        $geomSQL = $this->geomFromGeoJSON($validated['geometry']);

        $markerId = $this->postgis()
            ->table('markers')
            ->insertGetId([
                'view_id' => $view->id,
                'marker_name' => $validated['name'] ?? null,
                'marker_type' => $validated['marker_type'],
                'description' => $validated['description'] ?? null,
                'geom' => DB::raw($geomSQL),
                'created_at' => now(),
                'updated_at' => now()
            ]);

        $this->postgis()
            ->table('map_views')
            ->where('id', $view->id)
            ->update(['updated_at' => now()]);

        return response()->json([
            'status' => 'saved',
            'marker_id' => $markerId
        ], 201);
    }

    /**
     * Update a marker's name
     */
    public function updateMarker(Request $request, string $viewKey, int $markerId)
    {
        $validated = $request->validate([
            'marker_name' => 'nullable|string|max:255',
            'marker_type' => 'sometimes|string|max:50',
            'description' => 'nullable|string|max:500'
        ]);

        $view = $this->postgis()
            ->table('map_views')
            ->where('view_key', $viewKey)
            ->first();

        if (!$view) {
            return response()->json(['error' => 'View not found'], 404);
        }

        $marker = $this->postgis()
            ->table('markers')
            ->where('id', $markerId)
            ->where('view_id', $view->id)
            ->first();

        if (!$marker) {
            return response()->json(['error' => 'Marker not found in this view'], 404);
        }

        $updateData = ['updated_at' => now()];
        
        if (array_key_exists('marker_name', $validated)) {
            $updateData['marker_name'] = $validated['marker_name'];
        }
        if (isset($validated['marker_type'])) {
            $updateData['marker_type'] = $validated['marker_type'];
        }
        if (array_key_exists('description', $validated)) {
            $updateData['description'] = $validated['description'];
        }

        $this->postgis()
            ->table('markers')
            ->where('id', $markerId)
            ->update($updateData);

        $this->postgis()
            ->table('map_views')
            ->where('id', $view->id)
            ->update(['updated_at' => now()]);

        return response()->json([
            'status' => 'updated',
            'marker_id' => $markerId
        ]);
    }

    /**
     * Export a view as GeoJSON FeatureCollection
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
            ->where('view_id', $view->id)
            ->select([
                'id',
                'road_name',
                'road_type',
                DB::raw("ST_AsGeoJSON(geom)::json as geometry")
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
                    'feature_type' => 'road'
                ]
            ];
        }

        // Export markers
        $markers = $this->postgis()
            ->table('markers')
            ->where('view_id', $view->id)
            ->select([
                'id',
                'marker_name',
                'marker_type',
                'description',
                DB::raw("ST_AsGeoJSON(geom)::json as geometry")
            ])
            ->get();

        foreach ($markers as $marker) {
            $features[] = [
                'type' => 'Feature',
                'geometry' => json_decode($marker->geometry, true),
                'properties' => [
                    'id' => $marker->id,
                    'name' => $marker->marker_name,
                    'marker_type' => $marker->marker_type,
                    'description' => $marker->description,
                    'feature_type' => 'marker'
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
     * Sync current view data from existing JSON storage to PostGIS
     * This is a migration helper
     */
    public function syncFromJSON(Request $request)
    {
        $validated = $request->validate([
            'views' => 'required|array'
        ]);

        $imported = 0;

        foreach ($validated['views'] as $key => $viewData) {
            $this->postgis()
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

                        $this->postgis()
                            ->table('areas')
                            ->insert([
                                'view_id' => $view->id,
                                'area_name' => $areaData['area_name'] ?? $areaData['customName'] ?? null,
                                'geom' => DB::raw($geomSQL),
                                'created_at' => now(),
                                'updated_at' => now()
                            ]);

                        $imported++;
                    }
                }
            }

            // Import roads
            if (!empty($viewData['roads']) || !empty($viewData['customRoads'])) {
                $roads = $viewData['roads'] ?? $viewData['customRoads'] ?? [];
                foreach ($roads as $roadData) {
                    if (!empty($roadData['geometry'])) {
                        $geomSQL = $this->geomFromGeoJSON($roadData['geometry']);

                        $this->postgis()
                            ->table('roads')
                            ->insert([
                                'view_id' => $view->id,
                                'area_id' => null,
                                'road_name' => $roadData['road_name'] ?? $roadData['name'] ?? 'Unnamed',
                                'road_type' => 'custom',
                                'geom' => DB::raw($geomSQL),
                                'created_at' => now(),
                                'updated_at' => now()
                            ]);

                        $imported++;
                    }
                }
            }

            // Import markers
            if (!empty($viewData['markers'])) {
                foreach ($viewData['markers'] as $markerData) {
                    if (!empty($markerData['geometry'])) {
                        $geomSQL = $this->geomFromGeoJSON($markerData['geometry']);

                        $this->postgis()
                            ->table('markers')
                            ->insert([
                                'view_id' => $view->id,
                                'marker_name' => $markerData['marker_name'] ?? $markerData['name'] ?? null,
                                'marker_type' => $markerData['marker_type'] ?? 'generic',
                                'description' => $markerData['description'] ?? null,
                                'geom' => DB::raw($geomSQL),
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