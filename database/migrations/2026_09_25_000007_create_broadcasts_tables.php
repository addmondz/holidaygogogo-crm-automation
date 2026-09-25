<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('channel_id')->constrained()->cascadeOnDelete();
            $table->string('meta_id')->nullable();
            $table->string('name');
            $table->string('language', 20);
            $table->string('category')->nullable();
            $table->string('status')->nullable();
            $table->string('parameter_format')->nullable(); // POSITIONAL | NAMED
            $table->json('components')->nullable();
            $table->timestamps();

            $table->unique(['channel_id', 'name', 'language']);
        });

        Schema::create('broadcasts', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('channel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('whatsapp_template_id')->nullable()->constrained()->nullOnDelete();
            $table->json('template_params')->nullable();
            $table->text('body')->nullable(); // Messenger blasts are plain text
            $table->json('audience')->nullable();
            $table->string('status')->default('draft')->index();
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('total_recipients')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('broadcast_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('broadcast_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('message_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status')->default('pending');
            $table->text('error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('replied_at')->nullable();
            $table->timestamps();

            $table->unique(['broadcast_id', 'contact_id']);
            $table->index(['broadcast_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('broadcast_recipients');
        Schema::dropIfExists('broadcasts');
        Schema::dropIfExists('whatsapp_templates');
    }
};
