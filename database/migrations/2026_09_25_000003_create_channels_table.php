<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('channels', function (Blueprint $table) {
            $table->id();
            $table->string('type'); // whatsapp | messenger
            $table->string('name');
            // WhatsApp: phone number ID. Messenger: Facebook Page ID.
            $table->string('external_id');
            // WhatsApp: WhatsApp Business Account ID (used to sync templates).
            $table->string('business_account_id')->nullable();
            $table->string('display_phone')->nullable();
            $table->text('access_token')->nullable(); // encrypted
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['type', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channels');
    }
};
