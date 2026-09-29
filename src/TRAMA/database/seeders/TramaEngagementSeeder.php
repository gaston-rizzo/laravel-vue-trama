<?php

namespace Database\Seeders;

use Database\Seeders\Support\JsonSeedLoader;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class TramaEngagementSeeder extends Seeder
{
    public function run(): void
    {
        JsonSeedLoader::insert('article_views', 'article_views.json', 500);

        $comments = JsonSeedLoader::rows('comments.json');
        $main = array_values(array_filter(
            $comments,
            static fn (array $row): bool => $row['parent_id'] === null,
        ));
        $replies = array_values(array_filter(
            $comments,
            static fn (array $row): bool => $row['parent_id'] !== null,
        ));

        foreach (array_chunk($main, 400) as $chunk) {
            DB::table('comments')->insert($chunk);
        }
        foreach (array_chunk($replies, 400) as $chunk) {
            DB::table('comments')->insert($chunk);
        }

        JsonSeedLoader::insert('comment_likes', 'comment_likes.json', 500);
        JsonSeedLoader::insert('comment_reports', 'comment_reports.json', 400);
    }
}
