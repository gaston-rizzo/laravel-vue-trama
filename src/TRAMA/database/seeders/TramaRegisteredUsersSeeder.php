<?php

namespace Database\Seeders;

use Database\Seeders\Support\JsonSeedLoader;
use Illuminate\Database\Seeder;

class TramaRegisteredUsersSeeder extends Seeder
{
    // Contraseña demo de todas las cuentas: password
    private const DEMO_PASSWORD_HASH = '$2y$12$LqJeOcNQYXhX3seeTaomhuvKyuyVcISyc7PC.PwbHCFlS7a.Ml9Xm';

    public function run(): void
    {
        JsonSeedLoader::insert(
            'users',
            'registered_users.json',
            400,
            static function (array $row): array {
                // Estas columnas apuntan a comments/users que todavía no existen.
                // TramaModerationSeeder restaura el resumen final una vez cargada
                // toda la evidencia histórica.
                foreach ([
                    'moderation_review_requested_at',
                    'moderation_review_reason',
                    'moderation_review_note',
                    'moderation_review_requested_by_id',
                    'moderation_review_comment_id',
                    'moderation_review_comment_excerpt',
                    'moderation_review_article_title',
                    'moderation_review_resolved_at',
                    'moderation_review_resolution',
                    'moderation_review_resolved_by_id',
                ] as $field) {
                    $row[$field] = null;
                }

                $row['password'] = self::DEMO_PASSWORD_HASH;
                $row['remember_token'] = null;

                return $row;
            },
        );
    }
}
