<?php

/* ============================================================================
 * COMMAND: RebuildArticleSearchIndex.php
 * ============================================================================
 *
 * Reconstruye el texto utilizado por el buscador editorial para todas las
 * noticias existentes en TRAMA.
 *
 * Procesa los registros en bloques para mantener estable el uso de memoria,
 * reutiliza categoría y etiquetas precargadas y actualiza únicamente
 * search_text, sin modificar updated_at ni generar revisiones editoriales.
 * ============================================================================ */

namespace App\Console\Commands;

use App\Models\Article;
use App\Services\Editorial\ArticleSearchIndexer;
use Illuminate\Console\Command;

class RebuildArticleSearchIndex extends Command
{
    /**
     * Nombre y opciones disponibles para ejecutar el comando.
     *
     * La opción chunk permite controlar cuántas noticias se procesan en cada
     * bloque. El valor predeterminado funciona tanto para desarrollo como para
     * reconstrucciones grandes.
     *
     * @var string
     */
    protected $signature = 'articles:rebuild-search-index
                            {--chunk=500 : Cantidad de noticias procesadas por bloque}';

    /**
     * Descripción mostrada en el listado de comandos de Artisan.
     *
     * @var string
     */
    protected $description = 'Reconstruye search_text para las noticias existentes sin modificar updated_at.';

    /**
     * Recibe el servicio que construye y guarda el texto buscable.
     */
    public function __construct(
        private readonly ArticleSearchIndexer $searchIndexer,
    ) {
        parent::__construct();
    }

    /**
     * Ejecuta la reconstrucción completa del índice editorial.
     */
    public function handle(): int
    {
        $chunkSize = (int) $this->option('chunk');

        if ($chunkSize < 1) {
            $this->error('El tamaño del bloque debe ser mayor que cero.');

            return self::FAILURE;
        }

        /*
         * El conteo permite mostrar un progreso exacto durante la reconstrucción.
         * Los registros eliminados mediante SoftDeletes quedan excluidos.
         */
        $total = Article::query()->count();

        if ($total === 0) {
            $this->info('No hay noticias para reconstruir.');

            return self::SUCCESS;
        }

        $this->info("Reconstruyendo el índice de búsqueda de {$total} noticias...");

        $progressBar = $this->output->createProgressBar($total);
        $progressBar->start();

        /*
         * Se seleccionan únicamente las columnas necesarias para construir
         * search_text. Categoría y etiquetas se cargan una vez por cada bloque.
         */
        Article::query()
            ->select([
                'id',
                'category_id',
                'title',
                'subtitle',
                'excerpt',
                'body',
            ])
            ->with([
                'category:id,name',
                'tags:id,name',
            ])
            ->chunkById(
                $chunkSize,
                function ($articles) use ($progressBar): void {
                    foreach ($articles as $article) {
                        /*
                         * false indica que las relaciones ya vienen precargadas
                         * y no deben consultarse nuevamente para cada noticia.
                         */
                        $this->searchIndexer->index(
                            $article,
                            reloadRelations: false,
                        );

                        $progressBar->advance();
                    }
                }
            );

        $progressBar->finish();

        $this->newLine(2);
        $this->info("Índice reconstruido correctamente para {$total} noticias.");

        return self::SUCCESS;
    }
}