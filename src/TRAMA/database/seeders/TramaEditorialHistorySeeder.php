<?php

namespace Database\Seeders;

use Database\Seeders\Support\JsonSeedLoader;
use Illuminate\Database\Seeder;

class TramaEditorialHistorySeeder extends Seeder
{
    public function run(): void
    {
        JsonSeedLoader::insert(
            'article_revisions',
            'article_revisions.json',
            250,
            static function (array $row): array {
                $row['snapshot'] = $row['snapshot'] === null
                    ? null
                    : json_encode($row['snapshot'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $row['changed_fields'] = $row['changed_fields'] === null
                    ? null
                    : json_encode($row['changed_fields'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                return $row;
            },
        );

        JsonSeedLoader::insert(
            'article_review_feedback',
            'article_review_feedback.json',
            250,
        );
    }
}
