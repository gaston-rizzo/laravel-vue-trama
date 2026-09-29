<?php

namespace Database\Seeders\Support;

use Illuminate\Support\Facades\DB;
use JsonException;
use RuntimeException;

final class JsonSeedLoader
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function rows(string $filename): array
    {
        $path = base_path('database/seeders/data/'.$filename);

        if (! is_file($path)) {
            throw new RuntimeException("No se encontró el archivo de seed: {$path}");
        }

        try {
            $rows = json_decode(
                (string) file_get_contents($path),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $exception) {
            throw new RuntimeException(
                "JSON inválido en {$filename}: {$exception->getMessage()}",
                previous: $exception,
            );
        }

        if (! is_array($rows)) {
            throw new RuntimeException("El archivo {$filename} no contiene una lista válida.");
        }

        return $rows;
    }

    /**
     * Inserta un archivo JSON por lotes para no inflar memoria ni consultas.
     *
     * @param callable(array<string, mixed>): array<string, mixed>|null $transform
     */
    public static function insert(
        string $table,
        string $filename,
        int $chunkSize = 500,
        ?callable $transform = null,
    ): void {
        $rows = self::rows($filename);

        if ($transform !== null) {
            $rows = array_map($transform, $rows);
        }

        foreach (array_chunk($rows, $chunkSize) as $chunk) {
            if ($chunk !== []) {
                DB::table($table)->insert($chunk);
            }
        }
    }
}
