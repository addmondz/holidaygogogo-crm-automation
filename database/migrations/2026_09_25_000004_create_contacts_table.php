<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contacts', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            // Digits only, including country code (e.g. 60123456789).
            $table->string('phone', 32)->nullable()->unique();
            $table->string('email')->nullable();
            $table->string('status')->default('new')->index();
            $table->string('source')->nullable();
            $table->string('avatar_url', 1024)->nullable();
            $table->timestamp('opted_out_at')->nullable();
            $table->timestamps();

            $table->index('name');
        });

        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('color', 20)->default('slate');
            // Auto-apply when an incoming message contains any of these words.
            $table->json('keywords')->nullable();
            $table->timestamps();
        });

        Schema::create('contact_tag', function (Blueprint $table) {
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->primary(['contact_id', 'tag_id']);
            $table->index('tag_id');
        });

        Schema::create('notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('body');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notes');
        Schema::dropIfExists('contact_tag');
        Schema::dropIfExists('tags');
        Schema::dropIfExists('contacts');
    }
};
