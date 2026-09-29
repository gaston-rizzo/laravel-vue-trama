<?php

namespace Database\Seeders;

use Database\Seeders\Support\JsonSeedLoader;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class TramaStaffSeeder extends Seeder
{
    // Contraseña demo de todas las cuentas: password
    private const DEMO_PASSWORD_HASH = '$2y$12$LqJeOcNQYXhX3seeTaomhuvKyuyVcISyc7PC.PwbHCFlS7a.Ml9Xm';

    public function run(): void
    {
        $rows = JsonSeedLoader::rows('staff.json');

        foreach ($rows as &$row) {
            $row['password'] = self::DEMO_PASSWORD_HASH;
            $row['remember_token'] = null;
        }
        unset($row);

        DB::table('users')->insert($rows);
    }
}
