<?php

/* ============================================================================
 * MIGRATION: expand_trama_editorial_management.php
 * ============================================================================
 *
 * Amplía el CMS de TRAMA con administración de cuentas y flujo de revisión.
 *
 * Incorpora estado y actividad de usuarios, administración completa de
 * etiquetas, devoluciones editoriales con observaciones y datos adicionales
 * para comparar y restaurar versiones sin perder trazabilidad.
 * ============================================================================ */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Agrega las estructuras necesarias para los nuevos módulos del panel editorial.
     */
    public function up(): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            // Las migraciones iniciales del proyecto usaban borrado en cascada.
            // En instalaciones existentes se reemplaza por RESTRICT para que una
            // cuenta o categoría nunca elimine noticias ni revisiones históricas.
            Schema::table('articles', function (Blueprint $table): void {
                $table->dropForeign(['author_id']);
                $table->dropForeign(['category_id']);
                $table->foreign('author_id')->references('id')->on('users')->restrictOnDelete();
                $table->foreign('category_id')->references('id')->on('categories')->restrictOnDelete();
            });

            Schema::table('article_revisions', function (Blueprint $table): void {
                $table->dropForeign(['user_id']);
                $table->foreign('user_id')->references('id')->on('users')->restrictOnDelete();
            });
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('is_active')->default(true)->index()->after('avatar');
            $table->timestamp('last_login_at')->nullable()->index()->after('is_active');
            $table->timestamp('disabled_at')->nullable()->after('last_login_at');
            $table->timestamp('password_reset_required_at')->nullable()->after('disabled_at');
        });

        Schema::table('media_assets', function (Blueprint $table): void {
            // Distingue portadas históricas de imágenes insertadas en el cuerpo.
            $table->string('usage', 30)->default('cover')->index()->after('disk');
        });

        Schema::table('tags', function (Blueprint $table): void {
            $table->string('description', 260)->nullable()->after('slug');
            $table->boolean('is_active')->default(true)->index()->after('description');
            $table->foreignId('merged_into_id')
                ->nullable()
                ->after('is_active')
                ->constrained('tags')
                ->nullOnDelete();
        });

        Schema::create('article_review_feedback', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('article_id')->constrained()->cascadeOnDelete();
            $table->foreignId('returned_by')->constrained('users')->restrictOnDelete();
            $table->text('message');
            $table->timestamp('resolved_at')->nullable()->index();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['article_id', 'resolved_at']);
        });

        Schema::table('article_revisions', function (Blueprint $table): void {
            $table->json('changed_fields')->nullable()->after('snapshot');
            $table->foreignId('restored_from_revision_id')
                ->nullable()
                ->after('changed_fields')
                ->constrained('article_revisions')
                ->nullOnDelete();
        });
    }

    /**
     * Revierte únicamente las estructuras agregadas por esta ampliación.
     */
    public function down(): void
    {
        Schema::table('article_revisions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('restored_from_revision_id');
            $table->dropColumn('changed_fields');
        });

        Schema::dropIfExists('article_review_feedback');

        Schema::table('media_assets', function (Blueprint $table): void {
            $table->dropColumn('usage');
        });

        Schema::table('tags', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('merged_into_id');
            $table->dropColumn(['description', 'is_active']);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn([
                'is_active',
                'last_login_at',
                'disabled_at',
                'password_reset_required_at',
            ]);
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            Schema::table('article_revisions', function (Blueprint $table): void {
                $table->dropForeign(['user_id']);
                $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            });

            Schema::table('articles', function (Blueprint $table): void {
                $table->dropForeign(['author_id']);
                $table->dropForeign(['category_id']);
                $table->foreign('author_id')->references('id')->on('users')->cascadeOnDelete();
                $table->foreign('category_id')->references('id')->on('categories')->cascadeOnDelete();
            });
        }
    }
};
