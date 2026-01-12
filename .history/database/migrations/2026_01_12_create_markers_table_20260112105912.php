<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * This creates the markers table in PostGIS for storing point features
     * like manholes, electric poles, telco poles, etc.
     * 
     * NOTE: This migration ONLY runs on the PostGIS connection.
     * It will not affect your MySQL database.
     */
    public function up(): void
    {
        // Skip if not using PostGIS or if table already exists
        try {
            $postgis = DB::connection('postgis');
            
            // Check if table already exists
            $exists = $postgis->select("
                SELECT EXISTS (
                    SELECT FROM information_schema.tables 
                    WHERE table_name = 'markers'
                )
            ");
            
            if ($exists[0]->exists ?? false) {
                echo "Markers table already exists in PostGIS, skipping.\n";
                return;
            }
            
            // Create the markers table
            $postgis->statement("
                CREATE TABLE markers (
                    id SERIAL PRIMARY KEY,
                    view_id INTEGER NOT NULL REFERENCES map_views(id) ON DELETE CASCADE,
                    marker_name VARCHAR(255),
                    marker_type VARCHAR(50) NOT NULL DEFAULT 'generic',
                    description TEXT,
                    geom geometry(Point, 4326),
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )
            ");
            
            // Create indexes
            $postgis->statement("CREATE INDEX markers_view_id_idx ON markers(view_id)");
            $postgis->statement("CREATE INDEX markers_geom_idx ON markers USING GIST(geom)");
            $postgis->statement("CREATE INDEX markers_type_idx ON markers(marker_type)");
            
            // Add comment
            $postgis->statement("COMMENT ON TABLE markers IS 'Point markers for utility features like manholes, poles, etc.'");
            
            echo "Markers table created successfully in PostGIS.\n";
            
        } catch (\Exception $e) {
            // If PostGIS connection fails, just skip
            echo "Skipping markers migration (PostGIS not available or error): " . $e->getMessage() . "\n";
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        try {
            DB::connection('postgis')->statement("DROP TABLE IF EXISTS markers CASCADE");
        } catch (\Exception $e) {
            echo "Could not drop markers table: " . $e->getMessage() . "\n";
        }
    }
};