<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('geo_cities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('prefecture_id')->constrained('geo_prefectures');
            $table->string('name', 64);
            $table->string('name_kana');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('geo_cities');
    }
};