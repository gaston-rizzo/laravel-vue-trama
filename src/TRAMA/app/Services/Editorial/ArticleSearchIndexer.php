<?php

/* ============================================================================
 * SERVICE: ArticleSearchIndexer.php
 * ============================================================================
 *
 * Construye y mantiene el texto utilizado para buscar noticias dentro del
 * panel editorial de TRAMA mediante el índice FULLTEXT de la tabla articles.
 *
 * Reúne título, subtítulo, bajada, cuerpo convertido a texto plano, categoría
 * y etiquetas. La actualización se realiza sin modificar updated_at ni disparar
 * eventos editoriales, para que una reconstrucción del índice no parezca una
 * edición real de la noticia.
 * ============================================================================ */

namespace App\Services\Editorial;

use App\Models\Article;
use App\Services\HtmlSanitizer;
use Illuminate\Support\Facades\DB;

class ArticleSearchIndexer
{
    /**
     * Recibe el servicio encargado de convertir HTML en texto visible.
     */
    public function __construct(
        private readonly HtmlSanitizer $htmlSanitizer,
    ) {
    }

    /**
     * Construye el texto completo utilizado por el índice FULLTEXT.
     *
     * Durante un guardado editorial recarga categoría y etiquetas porque pueden
     * haberse modificado inmediatamente antes. Durante una reconstrucción masiva,
     * permite reutilizar relaciones ya precargadas para evitar consultas repetidas.
     */
    public function build(
        Article $article,
        bool $reloadRelations = true,
    ): string {
       /*
        * En create(), update() y restore() se fuerzan las relaciones para obtener
        * la categoría y las etiquetas definitivas después de cada sincronización.
        *
        * El comando de reconstrucción cargará estas relaciones por bloques y
        * enviará false para evitar dos consultas adicionales por cada noticia.
        */
        if ($reloadRelations) {
            $article->load([
                'category:id,name',
                'tags:id,name',
            ]);
        } else {
            $article->loadMissing([
                'category:id,name',
                'tags:id,name',
            ]);
        }

       /*
        * Cada fragmento será convertido a texto plano antes de incorporarlo al
        * documento de búsqueda.
        */
        $parts = [
            $article->title,
            $article->subtitle,
            $article->excerpt,
            $article->body,
            $article->category?->name,
        ];

       /*
        * Agrega individualmente los nombres de todas las etiquetas asociadas.
        */
        foreach ($article->tags as $tag) {
            $parts[] = $tag->name;
        }

        $normalizedParts = [];

       /*
        * Descarta valores nulos o vacíos y normaliza espacios, entidades HTML
        * y etiquetas mediante HtmlSanitizer.
        */
        foreach ($parts as $part) {
            $text = $this->htmlSanitizer->toPlainText(
                is_string($part) ? $part : null
            );

            if ($text !== '') {
                $normalizedParts[] = $text;
            }
        }

        return implode(' ', $normalizedParts);
    }

    /**
     * Reconstruye search_text sin alterar la fecha de actualización.
     *
     * El segundo argumento permite aprovechar relaciones precargadas durante una
     * reconstrucción masiva, evitando consultas repetidas por cada noticia.
     */
    public function index(
        Article $article,
        bool $reloadRelations = true,
    ): void {
        $searchText = $this->build(
            $article,
            $reloadRelations,
        );

        DB::table($article->getTable())
            ->where($article->getKeyName(), $article->getKey())
            ->update([
                'search_text' => $searchText,
            ]);

       /*
        * Mantiene sincronizada la instancia actual del modelo sin ejecutar una
        * consulta adicional ni volver a guardar la noticia.
        */
        $article->setAttribute('search_text', $searchText);
    }
}