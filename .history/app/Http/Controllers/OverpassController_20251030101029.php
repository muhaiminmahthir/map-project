<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;

class OverpassController extends Controller
{
    public function roads(Request $request){
        $data = $request->validate([
            'geometry' => 'required|array', // GeoJSON geometry: Polygon
        ]);

        $geom = $data['geometry'];

        // Accept Polygon only (turn circle/rectangle into polygon on the client)
        if($geom['type'] !== 'Polygon'){
            return response()->json(['error' => 'Only Polygon supported.'], 422);
        }

        //Outer Ring
        $ring = $geom['coordinates'][0] ?? null;
        if(!$ring || count($ring) < 4){
            return response()->json(['error' => 'Invalid Polygon.'], 422);
        }

        // Overpass wants "latitude longitude latitude longitude ..."
        $polyStr = collect($ring)->map(fn($c) => $c[1] . ' ' . $c[0])->implode(' ');

        // cache key by polygon hash
        $cacheKey = 'overpass' . md5($polyStr);
        $json = Cache::remember($cacheKey, now()->addMinutes(15), function() use($polyStr){
            $ql = <<< QL
[out:json][timeout:25];
(
way["highway"]["name"](poly:"$polyStr");
);
out tags;
QL;

            $response = Http::timeout(25)
                ->asForm()
                ->post('https://overpass-api.de/api/interpreter', ['data' => $ql]);     
                
            if(!$response->ok()){
                abort(502, 'Overpass API error.');
            }
            return $response->json();
        });

        $names = collect($json['elements'] ?? [])
            ->map(fn($el) => $el['tags']['name'] ?? null)
            ->filter()
            ->unique()
            ->values()
            ->all();

        return response()->json([
            'count' => count($names),
            'names' => $names,
        ]);
    }
}
