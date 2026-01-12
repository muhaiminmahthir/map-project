<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * This creates the markers table in PostGIS for storing point features
     * like manholes, electric poles, telco poles, etc.
     */
    public function up(): void
    {
        // Use the PostGIS connection
        $pdo = DB::connection('postgis')->getPdo();
        
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS markers (
                id SERIAL PRIMARY KEY,
                view_id INTEGER NOT NULL REFERENCES map_views(id) ON DELETE CASCADE,
                marker_name VARCHAR(255),
                marker_type VARCHAR(50) NOT NULL DEFAULT 'generic',
                description TEXT,
                geom geometry(Point, 4326),
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            );
            
            CREATE INDEX IF NOT EXISTS markers_view_id_idx ON markers(view_id);
            CREATE INDEX IF NOT EXISTS markers_geom_idx ON markers USING GIST(geom);
            CREATE INDEX IF NOT EXISTS markers_type_idx ON markers(marker_type);
        ");
        
        // Add comment for documentation
        $pdo->exec("COMMENT ON TABLE markers IS 'Point markers for utility features like manholes, poles, etc.';");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $pdo = DB::connection('postgis')->getPdo();
        $pdo->exec("DROP TABLE IF EXISTS markers CASCADE;");
    }
};
