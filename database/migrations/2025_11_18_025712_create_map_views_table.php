<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('map_views', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique(); // Unique identifier for the view set
            $table->json('data'); // Full JS "views" object (view1, view2, etc.) as JSON
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('map_views');
    }
};
