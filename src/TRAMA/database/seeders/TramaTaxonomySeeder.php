<?php

namespace Database\Seeders;

use Database\Seeders\Support\JsonSeedLoader;
use Illuminate\Database\Seeder;

class TramaTaxonomySeeder extends Seeder
{
    public function run(): void
    {
        JsonSeedLoader::insert('categories', 'categories.json', 100);
        JsonSeedLoader::insert('tags', 'tags.json', 100);
    }
}
