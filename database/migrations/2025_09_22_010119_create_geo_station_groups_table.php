<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('geo_station_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name', 64);
            $table->string('name_kana');
            $table->foreignId('prefecture_id')->constrained('geo_prefectures');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('geo_station_groups');
    }
};