<?php
namespace App\Http\Controllers;
use App\Models\MapView;
use Illuminate\Http\Request;

class MapViewController extends Controller
{
    // GET /api/view-state/{key}
    public function show(string $key)
    {
        $record = MapView::where('key', $key)->first();

        if (! $record) {
            return response()->json(['message' => 'not found'], 404);
        }

        // Just return the stored "data" (views object)
        return response()->json($record->data);
    }

    // POST /api/view-state/{key}
    public function store(Request $request, string $key)
    {
        $validated = $request->validate([
            'data' => 'required|array', // this will be the JS "views" object
        ]);
        $record = MapView::updateOrCreate(
            ['key' => $key],
            ['data' => $validated['data']]
        );

        return response()->json([
            'status' => 'ok',
            'updated_at' => $record->updated_at,
        ]);
    }
}
