<?php

namespace Database\Seeders;

use Database\Seeders\Support\JsonSeedLoader;
use Illuminate\Database\Seeder;

class TramaContactSeeder extends Seeder
{
    public function run(): void
    {
        JsonSeedLoader::insert('contact_messages', 'contact_messages.json', 100);
    }
}
