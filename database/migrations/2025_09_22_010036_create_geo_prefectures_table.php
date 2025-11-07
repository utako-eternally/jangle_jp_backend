<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('geo_prefectures', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('name_kana');
            $table->string('code', 64);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('geo_prefectures');
    }
};