<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // テーブルが既に存在するため、一旦削除してから再作成
        Schema::dropIfExists('shop_free_prices');
        
        Schema::create('shop_free_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_free_rate_id')->constrained('shop_frees');
            $table->enum('user_type', ['GENERAL', 'STUDENT', 'SENIOR', 'WOMAN']);
            $table->integer('price');
            $table->text('details')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_free_prices');
    }
};