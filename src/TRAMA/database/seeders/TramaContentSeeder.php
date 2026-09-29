<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class TramaContentSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            TramaStaffSeeder::class,
            TramaTaxonomySeeder::class,
            TramaRegisteredUsersSeeder::class,
            TramaArticlesSeeder::class,
            TramaEditorialHistorySeeder::class,
            TramaEngagementSeeder::class,
            TramaModerationSeeder::class,
            TramaContactSeeder::class,
        ]);
    }
}
