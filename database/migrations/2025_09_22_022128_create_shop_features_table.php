<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shop_features', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained('shops');
            $table->enum('feature', ['HEALTH', 'NO_RATE', 'GIRL_MAHJONG', 'MALE_PRO', 'FEMALE_PRO']);
            $table->timestamps();
            
            $table->unique(['shop_id', 'feature']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_features');
    }
};