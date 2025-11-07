<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shop_menus', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained('shops')->onDelete('cascade');
            $table->string('item_name', 100);
            $table->enum('category', ['FOOD', 'DRINK', 'ALCOHOL', 'OTHER'])->default('OTHER');
            $table->unsignedInteger('price');
            $table->text('description')->nullable();
            $table->boolean('is_available')->default(true);
            $table->timestamps();
            
            $table->index(['shop_id', 'category'], 'idx_shop_category');
            $table->index(['shop_id', 'is_available'], 'idx_shop_available');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_menus');
    }
};