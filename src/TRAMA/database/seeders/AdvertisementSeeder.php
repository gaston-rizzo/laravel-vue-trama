<?php

namespace Database\Seeders;

use Database\Seeders\Support\JsonSeedLoader;
use Illuminate\Database\Seeder;

class AdvertisementSeeder extends Seeder
{
    public function run(): void
    {
        // Los quince nombres image_path ya usan las dimensiones finales:
        // 1456x180, 600x500 y 600x1200. El seeder NO renombra archivos físicos;
        // esos WebP se renombran manualmente para que coincidan con la base.
        JsonSeedLoader::insert('advertisements', 'advertisements.json', 100);
        JsonSeedLoader::insert(
            'advertisement_daily_metrics',
            'advertisement_daily_metrics.json',
            300,
        );
    }
}
