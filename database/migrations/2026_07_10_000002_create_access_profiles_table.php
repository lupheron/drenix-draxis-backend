<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('access_profiles', function (Blueprint $table) {
            $table->id();
            $table->string('label')->unique();
            $table->string('company', 10); // JM | WF | BP
            $table->string('role_type'); // ceo | head_hr
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('access_profiles');
    }
};
