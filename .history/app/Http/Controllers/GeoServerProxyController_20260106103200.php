<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class GeoServerProxyController extends Controller
{
    /**
     * GeoServer base URL - UPDATE THIS
     */
    private $geoserverBase = 'http://geoserversafe.duckdns.org:65437/geoserver';

    /**
     * Proxy requests to GeoServer to bypass CORS
     */
    public function proxy(Request $request)
    {
        // Get the path from query parameter
        $path = $request->query('path', '');
        
        if (empty($path)) {
            return response()->json([
                'error' => 'Missing path parameter'
            ], 400);
        }

        // Build query string (exclude 'path' parameter)
        $queryParams = $request->except('path');
        $queryString = http_build_query($queryParams);

        // Build full GeoServer URL
        $geoserverUrl = $this->geoserverBase . '/' . $path;
        if (!empty($queryString)) {
            $geoserverUrl .= '?' . $queryString;
        }

        try {
            // Make request to GeoServer
            $response = Http::timeout(30)
                ->withoutVerifying() // Skip SSL verification if needed
                ->get($geoserverUrl);

            // Get content type from GeoServer response
            $contentType = $response->header('Content-Type') ?? 'application/octet-stream';

            // Return response with same content type
            return response($response->body(), $response->status())
                ->header('Content-Type', $contentType)
                ->header('Access-Control-Allow-Origin', '*');

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to connect to GeoServer',
                'message' => $e->getMessage(),
                'target_url' => $geoserverUrl
            ], 502);
        }
    }
}