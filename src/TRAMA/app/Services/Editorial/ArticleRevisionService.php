<?php

/* ============================================================================
 * SERVICE: ArticleRevisionService.php
 * ============================================================================
 *
 * Registra y compara versiones históricas de noticias.
 *
 * Reúne la creación de snapshots para que todas las acciones editoriales
 * produzcan revisiones consistentes y aptas para comparación o restauración.
 * ============================================================================ */

namespace App\Services\Editorial;

use App\Models\Article;
use App\Models\ArticleRevision;
use App\Models\User;

class ArticleRevisionService
{
    /**
     * Registra una revisión usando el estado actual de la noticia.
     *
     * @param  array<string, mixed>|null  $previousSnapshot
     */
    public function record(
        Article $article,
        User $user,
        string $action,
        ?string $statusFrom = null,
        ?array $previousSnapshot = null,
        ?ArticleRevision $restoredFrom = null,
    ): ArticleRevision {
        // Asegura que el snapshot incluya ids y nombres de etiquetas actuales.
        $article->load('tags:id,name,slug');
        // Copia completa del estado actual de la noticia después de la acción.
        $snapshot = $article->revisionSnapshot();

        return $article->revisions()->create([
            // Usuario que ejecutó la acción editorial.
            'user_id' => $user->id,
            // Nombre legible de la acción: published, archived, resubmitted, etc.
            'action' => $action,
            // Estado anterior si la acción cambió el flujo.
            'status_from' => $statusFrom,
            // Estado final de la noticia al registrar la revisión.
            'status_to' => $article->status,
            // Copia completa que permite auditar o restaurar.
            'snapshot' => $snapshot,
            // Lista de campos que cambiaron respecto del snapshot previo.
            'changed_fields' => $this->changedFields($previousSnapshot, $snapshot),
            // Si esta revisión nació de una restauración, apunta a la revisión original.
            'restored_from_revision_id' => $restoredFrom?->id,
        ]);
    }

    /**
     * Obtiene los nombres de campos cuyo valor cambió entre dos snapshots.
     *
     * Antes de comparar, normaliza los campos que pueden llegar con tipos
     * diferentes desde formularios, JSON o revisiones antiguas.
     *
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>  $after
     * @return list<string>
     */
    public function changedFields(?array $before, array $after): array
    {
        if ($before === null) {
            /*
            * En la primera revisión no existe un estado anterior.
            *
            * Todos los campos del snapshot se consideran parte
            * de la creación de la noticia.
            */
            return array_keys($after);
        }

        /*
        * captured_at cambia cada vez que se genera un snapshot,
        * pero no representa una modificación editorial.
        */
        $ignored = ['captured_at'];

        /*
        * Reúne las claves presentes en cualquiera de los snapshots
        * para detectar tanto valores agregados como eliminados.
        */
        $keys = array_unique([
            ...array_keys($before),
            ...array_keys($after),
        ]);

        return array_values(
            array_filter(
                $keys,
                function (string $key) use ($before, $after, $ignored): bool {
                    /*
                    * Los campos técnicos ignorados no deben generar
                    * una diferencia dentro del historial.
                    */
                    if (in_array($key, $ignored, true)) {
                        return false;
                    }

                    /*
                    * Obtiene los valores anterior y actualizado.
                    *
                    * Cuando una clave no existe, se considera null.
                    */
                    $beforeValue = $before[$key] ?? null;
                    $afterValue = $after[$key] ?? null;

                    /*
                    * category_id puede llegar como entero desde MySQL
                    * y como texto desde un formulario HTML.
                    *
                    * Ejemplo:
                    *
                    *      valor anterior: 1
                    *      valor nuevo:    "1"
                    *
                    * Ambos representan la misma categoría y no deben
                    * registrarse como una modificación.
                    */
                    if ($key === 'category_id') {
                        $beforeValue = $this->normalizeNullableInteger(
                            $beforeValue
                        );

                        $afterValue = $this->normalizeNullableInteger(
                            $afterValue
                        );
                    }

                    /*
                    * Después de normalizar los tipos, la comparación
                    * estricta detecta únicamente cambios reales.
                    */
                    return $beforeValue !== $afterValue;
                }
            )
        );
    }

    /**
     * Convierte un identificador opcional en entero.
     *
     * Ejemplos:
     *
     *      null  → null
     *      ''    → null
     *      '1'   → 1
     *      1     → 1
     */
    private function normalizeNullableInteger(mixed $value): ?int
    {
        if (
            $value === null
            || $value === ''
        ) {
            return null;
        }

        return (int) $value;
    }
}
