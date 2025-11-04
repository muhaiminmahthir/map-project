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
            ]);

            // GeoJSON Polygon -> Overpass poly: "lat lon lat lon ..."
            $ring = $data['geometry']['coordinates'][0] ?? [];
            if (count($ring) < 4) {
                return response()->json(['error' => 'Polygon ring must have at least 4 positions (closed)'], 422);
            }

            // Convert [lng,lat] -> "lat lon" and validate ranges
            $parts = [];
            foreach ($ring as $c) {
                if (!is_array($c) || count($c) < 2) {
                    throw new \RuntimeException('Invalid coordinate format.');
                }
                $lng = $c[0];
                $lat = $c[1];
                if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
                    throw new \RuntimeException('Invalid coordinate range.');
                }
                $parts[] =($lat.' '.$lng);
            }
            $polyStr = implode(' ', $parts);

            $cacheKey = 'overpass:full:' . md5($polyStr);

            $json = Cache::remember($cacheKey, now()->addMinutes(15), function () use ($polyStr) {
                $ql = <<<QL
[out:json][timeout:40];
way["highway"]["name"](poly:"$polyStr");
(._;>;);
out body;
QL;
                $resp = Http::timeout(45)
                    ->withHeaders([
                        'User-Agent' => 'Muhaimin-MapProject/1.0 (contact: example@example.com)',
                    ])
                    ->asForm()
                    ->post('https://overpass-api.de/api/interpreter', ['data' => $ql]);

                if (!$resp->ok()) {
                    Log::warning('Overpass non-200', ['code' => $resp->status(), 'body' => $resp->body()]);
                    return response()->json([
                        'error' => 'Overpass error',
                        'status' => $resp->status()
                    ], 502)->throwResponse();
                }

                return $resp->json();
            });

            if (!is_array($json)) {
                return $json; // a Response from throwResponse()
            }

            // Build node map
            $nodes = [];
            foreach ($json['elements'] ?? [] as $el) {
                if (($el['type'] ?? '') === 'node') {
                    $nodes[$el['id']] = [$el['lat'], $el['lon']];
                }
            }

            $features = [];
            $namesSet = [];

            foreach ($json['elements'] ?? [] as $el) {
                if (($el['type'] ?? '') !== 'way') continue;

                $coords = [];
                foreach ($el['nodes'] as $nid) {
                    if (isset($nodes[$nid])) {
                        [$lat, $lon] = $nodes[$nid];
                        $coords[] = [$lon, $lat]; // GeoJSON is [lng,lat]
                    }
                }

                $name = $el['tags']['name'] ?? '(unnamed)';
                if ($name) $namesSet[$name] = true;

                $features[] = [
                    'type' => 'Feature',
                    'properties' => [
                        'id' => $el['id'],
                        'name' => $name,
                        'highway' => $el['tags']['highway'] ?? null,
                    ],
                    'geometry' => [
                        'type' => 'LineString',
                        'coordinates' => $coords,
                    ],
                ];
            }

            $names = array_keys($namesSet);

            return response()->json([
                'count' => count($names),
                'names' => $names,
                'geojson' => [
                    'type' => 'FeatureCollection',
                    'features' => $features,
                ],
            ]);

        } catch (\Illuminate\Validation\ValidationException $ve) {
            return response()->json(['error' => 'Invalid geometry', 'details' => $ve->errors()], 422);
        } catch (\Throwable $e) {
            Log::error('roads() failed', ['msg' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return response()->json([
                'error' => 'Server error in roads endpoint',
                'hint'  => 'Check PHP cURL/SSL config or laravel.log',
            ], 500);
        }
    }
}
