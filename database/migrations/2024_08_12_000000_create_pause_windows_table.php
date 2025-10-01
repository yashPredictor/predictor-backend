<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('pause_windows', function (Blueprint $table): void {
            $table->id();
            $table->unsignedSmallInteger('starts_at')->default(60);
            $table->unsignedSmallInteger('ends_at')->default(480);
            $table->string('timezone')->default(config('app.timezone', 'Asia/Kolkata'));
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });

        DB::table('pause_windows')->insert([
            'starts_at' => 60,
            'ends_at'   => 480,
            'timezone'  => config('app.timezone', 'Asia/Kolkata'),
            'enabled'   => true,
            'created_at'=> now(),
            'updated_at'=> now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('pause_windows');
    }
};
