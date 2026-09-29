<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Crea los reportes realizados por lectores sobre comentarios publicados.
     *
     * Cada usuario puede reportar una sola vez el mismo comentario.
     * El reporte queda abierto hasta que un editor lo revise en el panel
     * de moderación.
     */
    public function up(): void
    {
        Schema::create('comment_reports', function (Blueprint $table): void {
            $table->id();

            // Comentario que fue reportado.
            // Si el comentario se elimina definitivamente, sus reportes también.
            $table->foreignId('comment_id')
                ->constrained('comments')
                ->cascadeOnDelete();

            // Usuario registrado que realizó el reporte.
            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            // Motivo elegido por el lector.
            $table->string('reason', 40);

            // open: todavía necesita revisión.
            // dismissed: el editor revisó el reporte y decidió mantener el comentario.
            // resolved: el editor tomó una acción de moderación sobre el comentario.
            $table->string('status', 20)
                ->default('open')
                ->index();

            // Editor que revisó el reporte.
            // Permite auditar quién resolvió la revisión desde el panel.
            $table->foreignId('reviewed_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            // Momento en que el reporte fue revisado.
            $table->timestamp('reviewed_at')->nullable();

            $table->timestamps();

            /*
             * Un mismo usuario no puede reportar repetidamente
             * el mismo comentario.
             */
            $table->unique([
                'comment_id',
                'user_id',
            ]);

            /*
             * Facilita buscar rápidamente los reportes abiertos
             * pertenecientes a un comentario.
             */
            $table->index([
                'comment_id',
                'status',
            ]);
        });
    }

    /**
     * Elimina la tabla completa de reportes.
     */
    public function down(): void
    {
        Schema::dropIfExists('comment_reports');
    }
};