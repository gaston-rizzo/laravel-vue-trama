<?php

namespace Database\Seeders;

use Database\Seeders\Support\JsonSeedLoader;
use Illuminate\Database\Seeder;

class TramaArticlesSeeder extends Seeder
{
    public function run(): void
    {
        JsonSeedLoader::insert('articles', 'articles.json', 250);
        JsonSeedLoader::insert('article_tag', 'article_tag.json', 500);
        JsonSeedLoader::insert('media_assets', 'media_assets.json', 250);
    }
}
