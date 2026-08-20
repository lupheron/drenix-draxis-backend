<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('access_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('access_request_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('access_profile_id')->constrained()->cascadeOnDelete();
            $table->string('username')->unique();
            $table->string('password');
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('access_accounts');
    }
};
