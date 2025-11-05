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
            // ---- 1) Validate input ----
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

            // Overpass wants "lat lon lat lon ..."
            $polyStr = collect($ring)->map(function ($c) {
                if (!is_array($c) || count($c) < 2) {
                    throw new \RuntimeException('Invalid coordinate pair.');
                }
                return $c[1] . ' ' . $c[0]; // lat lon
            })->implode(' ');

            $withGeom   = (bool)$request->boolean('with_geom');
            $nameFilter = $request->input('name'); // optional; if set, we fetch geometry for this exact name

            // ---- 2) Build Overpass QL ----
            $ql = $this->buildQuery($polyStr, $withGeom, $nameFilter);

            // ---- 3) Cache key (polygon + mode + name) ----
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
                    // 'https://overpass.openstreetmap.fr/api/interpreter', // often flaky
                ];

                $lastError = null;

                foreach ($endpoints as $url) {
                    try {
                        $resp = Http::timeout(40)
                            ->retry(2, 500) // 2 retries, 500ms backoff
                            ->withOptions([
                                'verify' => true,
                                'force_ip_resolve' => 'v4', // avoid IPv6 route issues
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
                            'url' => $url,
                            'status' => $resp->status(),
                            'body' => mb_substr($resp->body(), 0, 300),
                        ]);
                        $lastError = "HTTP {$resp->status()} from $url";
                    } catch (\Throwable $e) {
                        Log::warning('Overpass connect failed', [
                            'url' => $url,
                            'err' => $e->getMessage(),
                        ]);
                        $lastError = $e->getMessage();
                        continue; // try next mirror
                    }
                }

                throw new \RuntimeException('All Overpass endpoints failed: ' . ($lastError ?? 'unknown'));
            });

            if (!is_array($json)) {
                Log::warning('Unexpected cache payload type', ['type' => gettype($json)]);
                return response()->json(['error' => 'Cache payload invalid'], 500);
            }

            // ---- 4) Response shaping ----
            if (!$withGeom) {
                // Names-only mode (fast)
                $names = collect($json['elements'] ?? [])
                    ->map(fn($e) => $e['tags']['name'] ?? null)
                    ->filter()
                    ->unique()
                    ->values()
                    ->all();

                return response()->json([
                    'count' => count($names),
                    'names' => $names,
                ]);
            }

            // with_geom=true → build GeoJSON FeatureCollection
            $features = collect($json['elements'] ?? [])
                ->filter(fn($e) => ($e['type'] ?? '') === 'way' && !empty($e['geometry']))
                ->map(function ($e) {
                    $coords = array_map(fn($p) => [$p['lon'], $p['lat']], $e['geometry']); // LineString
                    return [
                        'type' => 'Feature',
                        'geometry' => ['type' => 'LineString', 'coordinates' => $coords],
                        'properties' => [
                            'name' => $e['tags']['name'] ?? null,
                            'highway' => $e['tags']['highway'] ?? null,
                        ],
                    ];
                })
                ->values()
                ->all();

            return response()->json([
                'type' => 'FeatureCollection',
                'features' => $features,
            ]);

        } catch (\Illuminate\Validation\ValidationException $ve) {
            return response()->json(['error' => 'Invalid geometry', 'details' => $ve->errors()], 422);
        } catch (\RuntimeException $re) {
            return response()->json(['error' => $re->getMessage()], 502);
        } catch (\Throwable $e) {
            Log::error('roads() failed', ['msg' => $e->getMessage()]);
            return response()->json([
                'error' => 'Server error in roads endpoint',
                'hint'  => 'Check laravel.log',
            ], 500);
        }
    }

    private function buildQuery(string $polyStr, bool $withGeom, ?string $nameFilter): string
    {
        // Base filter: highway ways with a name
        $filter = 'way["highway"]["name"]';

        if ($nameFilter !== null && $nameFilter !== '') {
            // Exact match for clean UX; to support partials use ~"pattern"
            $safe = addcslashes($nameFilter, "\"\\");
            $filter = 'way["highway"]["name"="'.$safe.'"]';
        }

        // Output — tags-only for speed, or geom+tags for highlighting
        if ($withGeom) {
            return <<<QL
[out:json][timeout:40];
(
  $filter(poly:"$polyStr");
);
out geom tags;
QL;
        } else {
            return <<<QL
[out:json][timeout:40];
(
  $filter(poly:""$polyStr"");
);
out tags;
QL;
        }
    }
}
