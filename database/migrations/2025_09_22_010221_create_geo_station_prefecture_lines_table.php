<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('geo_station_prefecture_lines', function (Blueprint $table) {
            $table->foreignId('prefecture_id')->constrained('geo_prefectures');
            $table->foreignId('station_line_id')->constrained('geo_station_lines');
            $table->primary(['prefecture_id', 'station_line_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('geo_station_prefecture_lines');
    }
};