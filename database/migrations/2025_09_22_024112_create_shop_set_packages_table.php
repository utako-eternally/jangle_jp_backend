<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shop_set_packages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_set_id')->constrained('shop_sets')->onDelete('cascade');
            $table->string('name');
            $table->integer('duration')->nullable();
            $table->enum('day_type', ['WEEKDAY', 'WEEKEND', 'HOLIDAY']);
            $table->text('details')->nullable();
            $table->timestamps();
            
            $table->unique(['shop_set_id', 'name', 'day_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_set_packages');
    }
};