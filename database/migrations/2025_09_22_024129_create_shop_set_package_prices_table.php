<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shop_set_package_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_set_package_id')->constrained('shop_set_packages')->onDelete('cascade');
            $table->enum('user_type', ['GENERAL', 'STUDENT', 'SENIOR', 'WOMAN']);
            $table->integer('price');
            $table->text('details')->nullable();
            $table->timestamps();
            
            $table->unique(['shop_set_package_id', 'user_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_set_package_prices');
    }
};