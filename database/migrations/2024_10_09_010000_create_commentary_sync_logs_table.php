<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('commentary_sync_logs', function (Blueprint $table) {
            $table->id();
            $table->uuid('run_id');
            $table->string('action');
            $table->string('status')->nullable();
            $table->text('message')->nullable();
            $table->json('context')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commentary_sync_logs');
    }
};
