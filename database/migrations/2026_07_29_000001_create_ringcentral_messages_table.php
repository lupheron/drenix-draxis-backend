<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ringcentral_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('company', 10);
            $table->string('external_id')->unique();
            $table->string('conversation_id');
            $table->string('direction', 16); // inbound | outbound
            $table->text('body')->nullable();
            $table->string('from_number')->nullable();
            $table->string('to_number')->nullable();
            $table->string('peer_number')->nullable();
            $table->string('peer_name')->nullable();
            $table->timestampTz('sent_at');
            $table->string('status')->nullable();
            $table->json('raw_json')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'sent_at']);
            $table->index(['user_id', 'conversation_id', 'sent_at']);
            $table->index(['company', 'sent_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ringcentral_messages');
    }
};
