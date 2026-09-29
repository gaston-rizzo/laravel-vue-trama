<?php

/* ============================================================================
 * SERVICE: TagMergeService.php
 * ============================================================================
 *
 * Fusiona etiquetas duplicadas sin perder relaciones históricas.
 *
 * Reasigna noticias a la etiqueta principal, evita filas duplicadas en la tabla
 * pivote y conserva la etiqueta secundaria como registro inactivo.
 * ============================================================================ */

namespace App\Services\Editorial;

use App\Models\Tag;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TagMergeService
{
    /**
     * Fusiona una etiqueta secundaria dentro de una etiqueta principal.
     */
    public function merge(Tag $source, Tag $target): Tag
    {
        if ($source->is($target)) {
            // No tiene sentido fusionar una etiqueta consigo misma.
            throw ValidationException::withMessages([
                'target_tag_id' => 'Seleccioná una etiqueta diferente como destino.',
            ]);
        }

        // La fusión mueve relaciones, borra filas de la tabla pivote y desactiva
        // la etiqueta origen. Todo debe confirmarse junto para no dejar datos a medias.
        return DB::transaction(function () use ($source, $target): Tag {
            // Bloquea ambas etiquetas para impedir que otra petición las edite o
            // fusione al mismo tiempo.
            $locked = Tag::query()
                ->whereKey([$source->id, $target->id])
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            // Después del bloqueo se reemplazan las instancias originales por
            // las filas recién leídas dentro de la transacción.
            $source = $locked->get($source->id);
            $target = $locked->get($target->id);

            if (! $source || ! $target) {
                // Alguna etiqueta pudo haber sido eliminada antes de tomar el bloqueo.
                throw ValidationException::withMessages([
                    'target_tag_id' => 'Alguna de las etiquetas ya no está disponible.',
                ]);
            }

            if ($source->merged_into_id !== null) {
                // Una etiqueta ya fusionada no puede volver a usarse como origen.
                throw ValidationException::withMessages([
                    'target_tag_id' => 'La etiqueta de origen ya fue fusionada anteriormente.',
                ]);
            }

            if (! $target->is_active || $target->merged_into_id !== null) {
                // La etiqueta destino debe ser la etiqueta final, no otra secundaria.
                throw ValidationException::withMessages([
                    'target_tag_id' => 'La etiqueta principal debe estar activa y no puede estar fusionada.',
                ]);
            }

            // insertOrIgnore evita violar la clave primaria compuesta cuando una
            // noticia ya utiliza ambas etiquetas.
            $articleIds = DB::table('article_tag')
                // Busca todas las noticias que usan la etiqueta secundaria.
                ->where('tag_id', $source->id)
                // Solo necesita ids de artículos para recrear vínculos hacia la etiqueta principal.
                ->pluck('article_id');

            foreach ($articleIds as $articleId) {
                // Crea el vínculo artículo + etiqueta principal si no existía.
                DB::table('article_tag')->insertOrIgnore([
                    'article_id' => $articleId,
                    'tag_id' => $target->id,
                ]);
            }

            // Elimina todos los vínculos restantes con la etiqueta secundaria.
            DB::table('article_tag')->where('tag_id', $source->id)->delete();

            // Keep historical aliases pointing to the final target instead of
            // leaving chains such as "Inteligencia Artificial -> IA -> Tecnologia".
            Tag::query()
                ->where('merged_into_id', $source->id)
                ->update([
                    'is_active' => false,
                    'merged_into_id' => $target->id,
                ]);

            // La etiqueta secundaria queda inactiva y apuntando a la principal
            // para conservar trazabilidad del nombre anterior.
            $source->update([
                'is_active' => false,
                'merged_into_id' => $target->id,
            ]);

            // Devuelve la etiqueta principal actualizada desde la base.
            return $target->refresh();
        }, attempts: 3);
    }
}
