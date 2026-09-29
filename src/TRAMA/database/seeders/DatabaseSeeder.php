<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        DB::disableQueryLog();

        DB::transaction(function (): void {
            $this->call([
                TramaContentSeeder::class,
                AdvertisementSeeder::class,
                TramaIntegritySeeder::class,
            ]);
        }, 3);
    }
}
