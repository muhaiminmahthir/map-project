<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class OverpassController extends Controller
{
    public function roads(Request $request)
    {
        try {
            $data = $request->validate([
                'geometry' => 'required|array',
                'geometry.type' => 'required|string|in:Polygon',
                'geometry.coordinates' => 'required|array|min:1',
                'geometry.coordinates.0' => 'required|array|min:4',
                'with_geom' => 'sometimes|boolean',
                'name' => 'sometimes|string|max:200',
            ]);

            $ring = $data['geometry']['coordinates'][0];
            if (count($ring) > 1000) {
                return response()->json(['error' => 'Polygon too detailed; draw a smaller area.'], 422);
            }

            // Overpass needs "lat lon" pairs
            $polyStr = collect($ring)->map(function ($c) {
                if (!is_array($c) || count($c) < 2) {
                    throw new \RuntimeException('Invalid coordinate pair.');
                }
                return $c[1] . ' ' . $c[0]; // lat lon
            })->implode(' ');

            $withGeom   = (bool)$request->boolean('with_geom');
            $nameFilter = $request->input('name');
            // For names-only listing (with_geom = false) we also want buildings
            $includeBuildings = !$withGeom && ($nameFilter === null || $nameFilter === '');
            $ql = $this->buildQuery($polyStr, $withGeom, $nameFilter, $includeBuildings);

            $cacheKey = 'overpass:' . sha1(json_encode([
                'poly' => $polyStr,
                'geom' => $withGeom,
                'name' => $nameFilter,
            ]));

            $json = Cache::remember($cacheKey, now()->addMinutes(15), function () use ($ql) {
                $endpoints = [
                    'https://overpass.kumi.systems/api/interpreter',
                    'https://z.overpass-api.de/api/interpreter',
                    'https://overpass-api.de/api/interpreter',
                ];

                $lastError = null;

                foreach ($endpoints as $url) {
                    try {
                        $resp = Http::timeout(40)
                            ->retry(2, 500)
                            ->withOptions([
                                'verify' => true,
                                'force_ip_resolve' => 'v4',
                                'connect_timeout' => 10,
                            ])
                            ->withHeaders([
                                'User-Agent' => 'Muhaimin-MapDashboard/1.0 (contact: you@example.com)',
                            ])
                            ->asForm()
                            ->post($url, ['data' => $ql]);

                        if ($resp->ok()) {
                            return $resp->json();
                        }

                        Log::warning('Overpass non-200', [
                            'url' => $url, 'status' => $resp->status(),
                            'body' => mb_substr($resp->body(), 0, 300),
                        ]);
                        $lastError = "HTTP {$resp->status()} from $url";
                    } catch (\Throwable $e) {
                        Log::warning('Overpass connect failed', ['url' => $url, 'err' => $e->getMessage()]);
                        $lastError = $e->getMessage();
                        continue;
                    }
                }

                throw new \RuntimeException('All Overpass endpoints failed: ' . ($lastError ?? 'unknown'));
            });

            if (!is_array($json)) {
                Log::warning('Unexpected cache payload type', ['type' => gettype($json)]);
                return response()->json(['error' => 'Cache payload invalid'], 500);
            }

            if (!$withGeom) {
                $elements = collect($json['elements'] ?? []);

                // ----- Roads -----
                $roads = $elements
                    ->filter(fn($e) =>
                        ($e['type'] ?? '') === 'way'
                        && isset($e['tags']['highway'])
                    )
                    ->map(function ($e) {
                        $tags    = $e['tags'] ?? [];
                        $osmName = $tags['name'] ?? null;
                        $hasName = $osmName !== null && trim($osmName) !== '';
                        $id      = $e['id'] ?? null;

                        if ($hasName) {
                            // Named road → grouped by name
                            return [
                                'key'   => $osmName,        // used for highlight query
                                'label' => $osmName,        // shown in sidebar (editable)
                                'id'    => $id,
                            ];
                        }

                        // Unnamed road → one entry per way ID
                        return [
                            'key'   => '__unnamed__:' . $id, // special key: type + ID
                            'label' => 'Unnamed road',       // initial text (you can rename in JS)
                            'id'    => $id,
                        ];
                    })
                    ->unique('key')
                    ->values()
                    ->all();

                // ----- Buildings / lots -----
                $buildings = $elements
                    ->filter(fn($e) => isset($e['tags']['building']))
                    ->map(function ($e) {
                        $tags = $e['tags'] ?? [];

                        $name  = $tags['name'] ?? null;
                        $lot   = $tags['addr:housenumber'] ?? null;

                        // We only care if it has at least a lot number or a name
                        if ((empty($name) || trim($name) === '') &&
                            (empty($lot)  || trim($lot)  === '')) {
                            return null;
                        }

                        return [
                            'id'     => $e['id'] ?? null,
                            'name'   => $name,
                            'lot_no' => $lot,
                        ];
                    })
                    ->filter()                    // remove nulls
                    ->unique('id')
                    ->values()
                    ->all();

                return response()->json([
                    'count'     => count($roads),
                    'roads'     => $roads,
                    'buildings' => $buildings,
                ]);
            }
            
            // with_geom = true → build GeoJSON for highlighting
            $features = collect($json['elements'] ?? [])
                ->filter(fn($e) => ($e['type'] ?? '') === 'way' && !empty($e['geometry']))
                ->map(function ($e) {
                    $coords  = array_map(fn($p) => [$p['lon'], $p['lat']], $e['geometry']);
                    $tags    = $e['tags'] ?? [];
                    $osmName = $tags['name'] ?? null;
                    $display = ($osmName !== null && trim($osmName) !== '')
                        ? $osmName
                        : 'Unnamed road';

                    return [
                        'type' => 'Feature',
                        'geometry' => [
                            'type' => 'LineString',
                            'coordinates' => $coords,
                        ],
                        'properties' => [
                            'name'     => $display,                // always something
                            'osm_name' => $osmName,                // raw OSM name (can be null)
                            'highway'  => $tags['highway'] ?? null,
                        ],
                    ];
                })
                ->values()
                ->all();


            return response()->json(['type' => 'FeatureCollection', 'features' => $features]);

        } catch (\Illuminate\Validation\ValidationException $ve) {
            return response()->json(['error' => 'Invalid geometry', 'details' => $ve->errors()], 422);
        } catch (\RuntimeException $re) {
            return response()->json(['error' => $re->getMessage()], 502);
        } catch (\Throwable $e) {
            Log::error('roads() failed', ['msg' => $e->getMessage()]);
            return response()->json(['error' => 'Server error in roads endpoint', 'hint' => 'Check laravel.log'], 500);
        }
    }

    private function buildQuery(string $polyStr, bool $withGeom, ?string $nameFilter, bool $includeBuildings = false): string
    {
        // Default: all highways in polygon
        $filter = 'way["highway"]';

        if ($nameFilter !== null && $nameFilter !== '') {

            // Case 1: unnamed road segment, key = "__unnamed__:<id>"
            if (strpos($nameFilter, '__unnamed__:') === 0) {
                $id = substr($nameFilter, strlen('__unnamed__:'));

                if ($withGeom) {
                    // Highlight exactly this way by ID
                    return "[out:json][timeout:40];\n"
                        . "way(" . $id . ");\n"
                        . "out geom tags;";
                }

                // Fallback for non-geom path (not really used, but safe)
                $filter = 'way(' . $id . ')';

            // Case 2: normal named road
            } else {
                $safe   = addcslashes($nameFilter, "\"\\");
                $filter = 'way["highway"]["name"="' . $safe . '"]';
            }
        }

        // Build the inner part of the Overpass union
        $parts   = [];
        $parts[] = '  ' . $filter . '(poly:"' . $polyStr . '");';

        // For the names-only listing (no specific name filter) we ALSO pull buildings
        if (!$withGeom && $includeBuildings && ($nameFilter === null || $nameFilter === '')) {
            $parts[] = '  way["building"](poly:"' . $polyStr . '");';
            $parts[] = '  relation["building"](poly:"' . $polyStr . '");';
        }

        $base = "[out:json][timeout:40];\n"
            . "(\n"
            . implode("\n", $parts) . "\n"
            . ");\n";

        if ($withGeom) {
            // With geometry (highlight roads)
            return $base . "out geom tags;";
        }

        // Names-only listing (roads + possibly buildings)
        return $base . "out tags;";
    }
}