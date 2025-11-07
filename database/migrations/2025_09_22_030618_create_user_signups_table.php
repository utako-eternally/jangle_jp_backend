<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_signups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users');
            $table->string('email')->unique();
            $table->enum('role', ['BUSINESS', 'ADMIN'])->default('BUSINESS');
            $table->boolean('email_verified')->default(false);
            $table->boolean('completed')->default(false);
            $table->timestamp('completed_at')->nullable();
            $table->string('verification_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_signups');
    }
};