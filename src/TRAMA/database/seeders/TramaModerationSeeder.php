<?php

namespace Database\Seeders;

use Database\Seeders\Support\JsonSeedLoader;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class TramaModerationSeeder extends Seeder
{
    public function run(): void
    {
        JsonSeedLoader::insert(
            'user_moderation_reviews',
            'user_moderation_reviews.json',
            250,
        );

        // users conserva un resumen rápido de la revisión administrativa más
        // reciente. Se restaura al final porque moderation_review_comment_id
        // referencia comments y no podía insertarse al crear las cuentas.
        foreach (JsonSeedLoader::rows('registered_users.json') as $row) {
            if (
                $row['moderation_review_requested_at'] === null
                && $row['blocked_reason'] === null
            ) {
                continue;
            }

            DB::table('users')
                ->where('id', $row['id'])
                ->update([
                    'blocked_reason' => $row['blocked_reason'],
                    'blocked_note' => $row['blocked_note'],
                    'moderation_review_requested_at' => $row['moderation_review_requested_at'],
                    'moderation_review_reason' => $row['moderation_review_reason'],
                    'moderation_review_note' => $row['moderation_review_note'],
                    'moderation_review_requested_by_id' => $row['moderation_review_requested_by_id'],
                    'moderation_review_comment_id' => $row['moderation_review_comment_id'],
                    'moderation_review_comment_excerpt' => $row['moderation_review_comment_excerpt'],
                    'moderation_review_article_title' => $row['moderation_review_article_title'],
                    'moderation_review_resolved_at' => $row['moderation_review_resolved_at'],
                    'moderation_review_resolution' => $row['moderation_review_resolution'],
                    'moderation_review_resolved_by_id' => $row['moderation_review_resolved_by_id'],
                    'updated_at' => $row['updated_at'],
                ]);
        }
    }
}
