<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class GeoController extends Controller
{
    // Overpass endpoint
    private string $overpass = 'https://overpass-api.de/api/interpreter';

    // Helpers
    private function areaIdFromRel(int $relId): int {
        // For relations, Overpass "area" id = 3600000000 + rel id
        return 3600000000 + $relId;
    }

    /**
     * GET /api/admin-areas
     * Params:
     *  - level: admin_level (2 country, 4 state/province, 6/7 district)
     *  - parent_rel (optional): parent relation id (to list children inside it)
     *  - q (optional): name filter (case-insensitive)
     *  - limit (optional): default 100
     */
    public function adminAreas(Request $r)
    {
        $level = $r->integer('level');
        abort_if(!$level, 400, 'level required');

        $parentRel = $r->integer('parent_rel');
        $q         = trim((string)$r->query('q', ''));
        $limit     = (int)($r->query('limit', 100));

        $cacheKey = "areas:l{$level}:p{$parentRel}:q".md5($q).":lim{$limit}";
        return Cache::remember($cacheKey, now()->addHours(12), function() use ($level,$parentRel,$q,$limit) {

            if ($parentRel) {
                $areaId = $this->areaIdFromRel($parentRel);
                $filter = $q ? "[\"name\"~\"$q\",i]" : "";
                $query = <<<QL
[out:json][timeout:25];
area($areaId)->.p;
rel(area.p)["boundary"="administrative"]["admin_level"="$level"]$filter;
out ids tags bb $limit;
QL;
            } else {
                // Search globally by level + name (kept small via `limit`)
                $filter = $q ? "[\"name\"~\"$q\",i]" : "";
                $query = <<<QL
[out:json][timeout:25];
rel["boundary"="administrative"]["admin_level"="$level"]$filter;
out ids tags bb $limit;
QL;
            }

            $resp = Http::timeout(30)->asForm()
                ->withHeaders(['User-Agent' => 'MapDashboard/1.0 (contact: you@example.com)'])
                ->post($this->overpass, ['data' => $query]);

            $json = $resp->json();
            $items = collect($json['elements'] ?? [])->map(function($e){
                $name = $e['tags']['name'] ?? ('rel/'.$e['id']);
                $bbox = isset($e['bounds'])
                    ? [$e['bounds']['minlat'],$e['bounds']['minlon'],$e['bounds']['maxlat'],$e['bounds']['maxlon']]
                    : null;

                return [
                    'rel_id'      => $e['id'],
                    'name'        => $name,
                    'admin_level' => $e['tags']['admin_level'] ?? null,
                    'bbox'        => $bbox,
                ];
            })->values();

            return ['items' => $items];
        });
    }

    /**
     * GET /api/admin-geometry/{relId}
     * Returns bbox and (optionally) polygon geometry.
     * Frontend can just use bbox to fit map.
     */
    public function geometry(int $relId)
    {
        $cacheKey = "geom:rel:$relId";
        return Cache::remember($cacheKey, now()->addHours(24), function() use ($relId) {

            $query = <<<QL
[out:json][timeout:25];
rel($relId);
out ids tags bb;
QL;

            $resp = Http::timeout(30)->asForm()
                ->withHeaders(['User-Agent' => 'MapDashboard/1.0 (contact: you@example.com)'])
                ->post($this->overpass, ['data' => $query]);

            $json = $resp->json();
            $el = $json['elements'][0] ?? null;

            $bbox = isset($el['bounds'])
                ? [$el['bounds']['minlat'],$el['bounds']['minlon'],$el['bounds']['maxlat'],$el['bounds']['maxlon']]
                : null;

            return [
                'rel_id' => $relId,
                'name'   => $el['tags']['name'] ?? null,
                'bbox'   => $bbox,
                // If later you want full polygon, add a second query with `out geom;` and convert to GeoJSON.
            ];
        });
    }
}
