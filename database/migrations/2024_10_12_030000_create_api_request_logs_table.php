<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('api_request_logs', function (Blueprint $table) {
            $table->id();
            $table->string('job_key')->nullable()->index();
            $table->uuid('run_id')->nullable()->index();
            $table->string('tag')->nullable()->index();
            $table->string('method', 10);
            $table->string('host')->nullable();
            $table->string('path')->nullable();
            $table->text('url');
            $table->unsignedSmallInteger('status_code')->nullable()->index();
            $table->boolean('is_error')->default(false)->index();
            $table->unsignedBigInteger('duration_ms')->nullable();
            $table->unsignedBigInteger('response_bytes')->nullable();
            $table->longText('response_body')->nullable();
            $table->string('exception_class')->nullable();
            $table->text('exception_message')->nullable();
            $table->timestamp('requested_at')->useCurrent();
            $table->timestamps();

            $table->index(['job_key', 'requested_at'], 'api_request_logs_job_time_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_request_logs');
    }
};
