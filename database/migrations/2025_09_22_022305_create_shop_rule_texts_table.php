<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shop_rule_texts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained('shops')->onDelete('cascade');
            $table->enum('category', ['MAIN_RULES', 'PENALTY_RULES', 'MANNER_RULES']);
            $table->text('content');
            $table->integer('display_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            
            $table->unique(['shop_id', 'category']);
            $table->index('shop_id');
            $table->index('display_order');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_rule_texts');
    }
};