<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shops', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('phone')->nullable();
            $table->string('website_url')->nullable();
            $table->string('open_hours')->nullable();
            $table->integer('table_count')->nullable();
            $table->integer('score_table_count')->nullable();
            $table->integer('auto_table_count')->nullable();
            $table->foreignId('prefecture_id')->nullable()->constrained('geo_prefectures');
            $table->foreignId('city_id')->nullable()->constrained('geo_cities');
            $table->string('address_pref');
            $table->string('address_city');
            $table->string('address_town');
            $table->string('address_street')->nullable();
            $table->string('address_building', 128)->nullable();
            $table->decimal('lat', 10, 8)->nullable();
            $table->decimal('lng', 11, 8)->nullable();
            $table->boolean('is_paid')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shops');
    }
};