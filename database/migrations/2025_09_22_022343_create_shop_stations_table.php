<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shop_stations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained('shops')->onDelete('cascade');
            $table->foreignId('station_id')->constrained('geo_stations')->onDelete('cascade');
            $table->foreignId('station_group_id')->nullable()->constrained('geo_station_groups')->onDelete('cascade');
            $table->decimal('distance_km', 8, 3)->comment('店舗から駅までの距離（km）');
            $table->boolean('is_nearest')->default(false)->comment('最寄り駅フラグ');
            $table->integer('walking_minutes')->nullable()->comment('徒歩時間（分）');
            $table->enum('accuracy', ['high', 'medium', 'low'])->default('medium')->comment('距離精度');
            $table->timestamps();
            
            $table->unique(['shop_id', 'station_id'], 'unique_shop_station');
            $table->index('shop_id');
            $table->index('station_id');
            $table->index(['shop_id', 'is_nearest']);
            $table->index('distance_km');
            $table->index('station_group_id');
            $table->index(['shop_id', 'station_group_id', 'is_nearest'], 'idx_shop_station_group_nearest');
            $table->index(['station_group_id', 'distance_km'], 'idx_station_group_distance');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_stations');
    }
};