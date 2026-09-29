<?php

/* ============================================================================
 * MIGRATION: add_moderation_review_context_to_users_table.php
 * ============================================================================
 *
 * Guarda el contexto que un editor adjunta al solicitar revisión administrativa
 * de un usuario registrado desde moderación.
 * ============================================================================ */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            if (! Schema::hasColumn('users', 'moderation_review_reason')) {
                $table->string('moderation_review_reason', 40)
                    ->nullable()
                    ->after('moderation_review_requested_at');
            }

            if (! Schema::hasColumn('users', 'moderation_review_note')) {
                $table->string('moderation_review_note', 300)
                    ->nullable()
                    ->after('moderation_review_reason');
            }

            if (! Schema::hasColumn('users', 'moderation_review_requested_by_id')) {
                $table->foreignId('moderation_review_requested_by_id')
                    ->nullable()
                    ->after('moderation_review_note')
                    ->constrained('users')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('users', 'moderation_review_comment_id')) {
                $table->foreignId('moderation_review_comment_id')
                    ->nullable()
                    ->after('moderation_review_requested_by_id')
                    ->constrained('comments')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('users', 'moderation_review_comment_excerpt')) {
                $table->text('moderation_review_comment_excerpt')
                    ->nullable()
                    ->after('moderation_review_comment_id');
            }

            if (! Schema::hasColumn('users', 'moderation_review_article_title')) {
                $table->string('moderation_review_article_title', 180)
                    ->nullable()
                    ->after('moderation_review_comment_excerpt');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            if (Schema::hasColumn('users', 'moderation_review_comment_id')) {
                $table->dropConstrainedForeignId('moderation_review_comment_id');
            }

            if (Schema::hasColumn('users', 'moderation_review_requested_by_id')) {
                $table->dropConstrainedForeignId('moderation_review_requested_by_id');
            }

            $columns = [
                'moderation_review_article_title',
                'moderation_review_comment_excerpt',
                'moderation_review_note',
                'moderation_review_reason',
            ];

            foreach ($columns as $column) {
                if (Schema::hasColumn('users', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
