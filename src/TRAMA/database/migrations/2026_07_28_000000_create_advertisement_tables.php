<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Crea las tablas necesarias para administrar banners y reportes.
     */
    public function up(): void
    {
        Schema::create('advertisements', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);
            $table->string('brand', 80)->index();
            $table->string('placement', 60)->index();
            $table->string('image_path');
            $table->string('target_url');
            $table->dateTime('starts_at')->index();
            $table->dateTime('ends_at')->index();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();

            $table->index(['placement', 'is_active', 'starts_at', 'ends_at']);
        });

        Schema::create('advertisement_daily_metrics', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('advertisement_id')->constrained()->cascadeOnDelete();
            $table->date('date')->index();
            $table->unsignedInteger('impressions_count')->default(0);
            $table->unsignedInteger('clicks_count')->default(0);
            $table->timestamps();

            $table->unique(['advertisement_id', 'date']);
        });
    }

    /**
     * Elimina las tablas de métricas y banners.
     */
    public function down(): void
    {
        Schema::dropIfExists('advertisement_daily_metrics');
        Schema::dropIfExists('advertisements');
    }
};
