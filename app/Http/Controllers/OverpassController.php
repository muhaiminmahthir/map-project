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
            // 1) Validate geometry (Polygon with at least 4 points)
            $data = $request->validate([
                'geometry' => 'required|array',
                'geometry.type' => 'required|string|in:Polygon',
                'geometry.coordinates' => 'required|array|min:1',
                'geometry.coordinates.0' => 'required|array|min:4',
            ]);

            $ring = $data['geometry']['coordinates'][0];

            // Optional safety: limit overly-detailed polygons
            if (count($ring) > 1000) {
                return response()->json([
                    'error' => 'Polygon too detailed; please draw a smaller area.',
                ], 422);
            }

            // Overpass wants: "lat lon lat lon ..."
            $polyStr = collect($ring)->map(function ($c) {
                if (!is_array($c) || count($c) < 2) {
                    throw new \RuntimeException('Invalid coordinate pair.');
                }
                return $c[1] . ' ' . $c[0]; // lat lon
            })->implode(' ');

            // 2) Cache per polygon (independent of mirror used)
            $cacheKey = 'overpass:names:' . md5($polyStr);

            $json = Cache::remember($cacheKey, now()->addMinutes(15), function () use ($polyStr) {

                // Lean query: we only need tags.name for roads
                $ql = <<<QL
[out:json][timeout:40];
(
  way["highway"]["name"](poly:"$polyStr");
);
out tags;
QL;

                $endpoints = [
                    'https://overpass-api.de/api/interpreter',
                    'https://z.overpass-api.de/api/interpreter',
                    'https://overpass.kumi.systems/api/interpreter',
                    //'https://overpass.openstreetmap.fr/api/interpreter',
                ];

                $lastError = null;

                foreach ($endpoints as $url) {
                    try {
                        $resp = Http::timeout(40)
                            ->retry(2, 500) // 2 quick retries, 500ms backoff
                            ->withOptions([
                                'verify' => true,
                                'force_ip_resolve' => 'v4', // <-- key for GCP/IPv6 quirks
                                'connect_timeout' => 10,
                            ])
                            ->retry(2, 500)
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

                // If all mirrors failed, propagate a recognizable failure
                throw new \RuntimeException('All Overpass endpoints failed: ' . ($lastError ?? 'unknown'));
            });

            if (!is_array($json)) {
                // In case something odd got cached
                Log::warning('Unexpected cache payload type', ['type' => gettype($json)]);
                return response()->json(['error' => 'Cache payload invalid'], 500);
            }

            // 3) Collect unique names
            $names = collect($json['elements'] ?? [])
                ->map(fn ($e) => $e['tags']['name'] ?? null)
                ->filter()
                ->unique()
                ->values()
                ->all();

            return response()->json([
                'count' => count($names),
                'names' => $names,
            ]);

        } catch (\Illuminate\Validation\ValidationException $ve) {
            return response()->json(['error' => 'Invalid geometry', 'details' => $ve->errors()], 422);
        } catch (\RuntimeException $re) {
            // All mirrors failed etc. -> return 502 (bad upstream) instead of 500
            return response()->json(['error' => $re->getMessage()], 502);
        } catch (\Throwable $e) {
            Log::error('roads() failed', ['msg' => $e->getMessage()]);
            return response()->json([
                'error' => 'Server error in roads endpoint',
                'hint'  => 'Check laravel.log for details',
            ], 500);
        }
    }
}
