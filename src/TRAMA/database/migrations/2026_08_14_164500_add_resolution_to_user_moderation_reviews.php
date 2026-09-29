<?php

/* ============================================================================
 * MIGRATION: add_resolution_to_user_moderation_reviews.php
 * ============================================================================
 *
 * Completa el flujo de derivaciones administrativas de usuarios registrados.
 *
 * Hasta ahora users conservaba únicamente la solicitud activa. Esta migración:
 *
 *      - agrega el resultado de la última revisión a users para consultas rápidas;
 *      - crea user_moderation_reviews como historial de cada derivación;
 *      - migra las derivaciones existentes al historial sin perder contexto;
 *      - considera resueltas como "blocked" las derivaciones históricas de
 *        cuentas que ya estaban bloqueadas al ejecutar la migración.
 * ============================================================================ */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Agrega el estado de resolución y crea el historial administrativo.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            if (! Schema::hasColumn('users', 'moderation_review_resolved_at')) {
                $table->timestamp('moderation_review_resolved_at')
                    ->nullable()
                    ->index()
                    ->after('moderation_review_article_title');
            }

            if (! Schema::hasColumn('users', 'moderation_review_resolution')) {
                $table->string('moderation_review_resolution', 20)
                    ->nullable()
                    ->after('moderation_review_resolved_at');
            }

            if (! Schema::hasColumn('users', 'moderation_review_resolved_by_id')) {
                $table->foreignId('moderation_review_resolved_by_id')
                    ->nullable()
                    ->after('moderation_review_resolution')
                    ->constrained('users')
                    ->nullOnDelete();
            }
        });

        if (! Schema::hasTable('user_moderation_reviews')) {
            Schema::create('user_moderation_reviews', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')
                    ->constrained('users')
                    ->cascadeOnDelete();
                $table->timestamp('requested_at')->index();
                $table->string('reason', 40);
                $table->string('note', 300);
                $table->foreignId('requested_by_id')
                    ->nullable()
                    ->constrained('users')
                    ->nullOnDelete();
                $table->foreignId('comment_id')
                    ->nullable()
                    ->constrained('comments')
                    ->nullOnDelete();
                $table->text('comment_excerpt')->nullable();
                $table->string('article_title', 180)->nullable();
                $table->timestamp('resolved_at')->nullable()->index();
                $table->string('resolution', 20)->nullable();
                $table->foreignId('resolved_by_id')
                    ->nullable()
                    ->constrained('users')
                    ->nullOnDelete();
                $table->timestamps();

                $table->index(['user_id', 'resolved_at']);
            });
        }

        /*
         * Convierte las derivaciones existentes en el primer registro de
         * historial. Si la cuenta ya estaba bloqueada, la solicitud se considera
         * resuelta mediante bloqueo para no dejar un pendiente imposible.
         */
        DB::table('users')
            ->whereNotNull('moderation_review_requested_at')
            ->orderBy('id')
            ->chunkById(200, function ($users): void {
                foreach ($users as $user) {
                    $wasBlocked = ! (bool) $user->is_active;
                    $resolvedAt = $wasBlocked
                        ? ($user->disabled_at ?: $user->moderation_review_requested_at)
                        : null;
                    $resolution = $wasBlocked ? 'blocked' : null;

                    DB::table('user_moderation_reviews')->insert([
                        'user_id' => $user->id,
                        'requested_at' => $user->moderation_review_requested_at,
                        'reason' => $user->moderation_review_reason ?: 'other',
                        'note' => $user->moderation_review_note ?: 'Derivación migrada desde el estado anterior.',
                        'requested_by_id' => $user->moderation_review_requested_by_id,
                        'comment_id' => $user->moderation_review_comment_id,
                        'comment_excerpt' => $user->moderation_review_comment_excerpt,
                        'article_title' => $user->moderation_review_article_title,
                        'resolved_at' => $resolvedAt,
                        'resolution' => $resolution,
                        'resolved_by_id' => null,
                        'created_at' => $user->moderation_review_requested_at,
                        'updated_at' => $resolvedAt ?: $user->moderation_review_requested_at,
                    ]);

                    if ($wasBlocked) {
                        DB::table('users')
                            ->where('id', $user->id)
                            ->update([
                                'moderation_review_resolved_at' => $resolvedAt,
                                'moderation_review_resolution' => 'blocked',
                                'moderation_review_resolved_by_id' => null,
                            ]);
                    }
                }
            });
    }

    /**
     * Revierte únicamente las estructuras agregadas por esta migración.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_moderation_reviews');

        Schema::table('users', function (Blueprint $table): void {
            if (Schema::hasColumn('users', 'moderation_review_resolved_by_id')) {
                $table->dropConstrainedForeignId('moderation_review_resolved_by_id');
            }

            $columns = array_values(array_filter([
                Schema::hasColumn('users', 'moderation_review_resolution')
                    ? 'moderation_review_resolution'
                    : null,
                Schema::hasColumn('users', 'moderation_review_resolved_at')
                    ? 'moderation_review_resolved_at'
                    : null,
            ]));

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
