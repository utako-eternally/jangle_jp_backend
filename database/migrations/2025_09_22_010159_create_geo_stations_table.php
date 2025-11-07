<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('geo_stations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('station_group_id')->nullable()->constrained('geo_station_groups');
            $table->foreignId('station_line_id')->constrained('geo_station_lines');
            $table->foreignId('prefecture_id')->constrained('geo_prefectures');
            $table->string('name', 64);
            $table->string('name_kana');
            $table->decimal('latitude', 9, 6)->default(0);
            $table->decimal('longitude', 9, 6)->default(0);
            $table->integer('line_order');
            $table->boolean('is_grouped')->default(false);
            
            // インデックス
            $table->index(['latitude', 'longitude'], 'idx_geo_stations_lat_lng');
            $table->index('latitude', 'idx_geo_stations_lat');
            $table->index('longitude', 'idx_geo_stations_lng');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('geo_stations');
    }
};