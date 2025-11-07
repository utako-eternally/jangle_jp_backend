<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shop_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained('shops');
            $table->enum('rule', [
                'KUITAN_ALLOWED', 'KUITAN_PROHIBITED', 'ATODZUKE_ALLOWED', 
                'SAKIZUKE_ONLY', 'TENPAI_RENCHAN', 'AGARI_RENCHAN', 
                'KATA_TEN_ALLOWED', 'RED_TILES', 'POTCHI_TILES', 
                'SPECIAL_TILES', 'SPEED_BATTLE', 'RANKING_MATCH', 
                'NO_HAKOSHITA', 'NO_FU_CALCULATION', 'TONPU', 'TONNAN'
            ]);
            $table->timestamps();
            
            $table->unique(['shop_id', 'rule']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_rules');
    }
};