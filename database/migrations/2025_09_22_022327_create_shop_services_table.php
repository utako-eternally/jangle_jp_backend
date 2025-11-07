<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shop_services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained('shops')->onDelete('cascade');
            $table->enum('service_type', [
                'FREE_DRINK', 'FREE_DRINK_SET', 'STUDENT_DISCOUNT', 
                'FEMALE_DISCOUNT', 'SENIOR_DISCOUNT', 'PARKING_AVAILABLE', 
                'PARKING_SUBSIDY', 'NON_SMOKING', 'HEATED_TOBACCO_ALLOWED', 
                'SMOKING_ALLOWED', 'FOOD_AVAILABLE', 'ALCOHOL_AVAILABLE', 
                'DELIVERY_MENU', 'OUTSIDE_FOOD_ALLOWED', 'PRIVATE_ROOM', 
                'FEMALE_TOILET', 'AUTO_TABLE', 'SCORE_MANAGEMENT', 'FREE_WIFI'
            ]);
            $table->timestamps();
            
            $table->unique(['shop_id', 'service_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_services');
    }
};