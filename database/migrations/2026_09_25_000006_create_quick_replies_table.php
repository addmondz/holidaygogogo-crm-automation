<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quick_replies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->boolean('is_shared')->default(false);
            $table->string('shortcut', 50);
            $table->string('title');
            $table->text('body');
            $table->string('attachment_path')->nullable();
            $table->string('attachment_name')->nullable();
            $table->string('attachment_mime')->nullable();
            $table->timestamps();

            $table->index('shortcut');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quick_replies');
    }
};
