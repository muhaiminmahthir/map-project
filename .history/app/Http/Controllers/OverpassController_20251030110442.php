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
            ]);

            $ring = $data['geometry']['coordinates'][0];

            // Optional: limit polygon size (prevents huge queries)
            if (count($ring) > 500) {
                return response()->json([
                    'error' => 'Polygon too detailed; please draw a smaller area.'
                ], 422);
            }

            // Overpass needs "lat lon lat lon ..."
            $polyStr = collect($ring)->map(function ($c) {
                // $c = [lng, lat]
                if (!is_array($c) || count($c) < 2) {
                    throw new \RuntimeException('Invalid coordinate pair.');
                }
                return $c[1].' '.$c[0]; // lat lon
            })->implode(' ');

            $cacheKey = 'overpass:' . md5($polyStr);

            $json = Cache::remember($cacheKey, now()->addMinutes(15), function () use ($polyStr) {
                $ql = <<<QL
[out:json][timeout:40];
(
  way["highway"]["name"](poly:"$polyStr");
);
out tags;
QL;
                $resp = Http::timeout(40)
                    ->withHeaders([
                        // Overpass prefers a clear UA: https://wiki.openstreetmap.org/wiki/Overpass_API/Overpass_QL
                        'User-Agent' => 'Muhaimin-MapProject/1.0 (contact: example@example.com)',
                    ])
                    ->asForm()
                    ->post('https://overpass-api.de/api/interpreter', ['data' => $ql]);

                if (!$resp->ok()) {
                    Log::warning('Overpass non-200', ['code' => $resp->status(), 'body' => $resp->body()]);
                    // Surface a readable error code to the client instead of 500
                    return response()->json([
                        'error' => 'Overpass error',
                        'status' => $resp->status()
                    ], 502)->throwResponse();
                }

                return $resp->json();
            });

            if (!is_array($json)) {
                // In case Cache stored a Response from throwResponse()
                return $json; // returns the Response object (502) directly
            }

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
        } catch (\Throwable $e) {
            // Log the full error, return a readable message
            Log::error('roads() failed', ['msg' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return response()->json([
                'error' => 'Server error in roads endpoint',
                'hint'  => 'Enable curl extension or check laravel.log',
            ], 500);
        }
    }
}
