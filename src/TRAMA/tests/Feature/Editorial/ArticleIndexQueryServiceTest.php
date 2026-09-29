<?php

/* ============================================================================
 * TEST: ArticleIndexQueryServiceTest.php
 * ============================================================================
 *
 * Comprueba la consulta optimizada utilizada por el listado editorial.
 *
 * Las pruebas verifican la visibilidad de periodistas y editores, la búsqueda,
 * los filtros estructurados, los contadores de estado, la paginación mediante
 * cursor y la respuesta liviana generada para la tabla administrativa.
 * ============================================================================ */

namespace Tests\Feature\Editorial;

use App\Http\Resources\Editorial\AdminArticleListResource;

use App\Models\Article;
use App\Models\Category;
use App\Models\Tag;
use App\Models\User;

use App\Services\Editorial\ArticleIndexQueryService;
use App\Support\TramaClock;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

use Tests\TestCase;

class ArticleIndexQueryServiceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Número incremental utilizado para generar títulos y slugs únicos.
     */
    private int $articleSequence = 0;

    /**
     * Comprueba que un periodista solamente pueda consultar sus noticias.
     */
    public function test_journalist_only_sees_own_articles(): void
    {
        $journalist = User::factory()->create([
            'role' => 'journalist',
        ]);

        $otherJournalist = User::factory()->create([
            'role' => 'journalist',
        ]);

        $ownDraft = $this->createArticle(
            $journalist,
            'draft',
        );

        $ownReview = $this->createArticle(
            $journalist,
            'review',
        );

        /*
         * Esta noticia pertenece a otra persona y debe quedar fuera del
         * listado, aunque su estado sea published.
         */
        $this->createArticle(
            $otherJournalist,
            'published',
        );

        $page = $this->service()->paginate($journalist, [
            'per_page' => 100,
        ]);

        $visibleIds = collect($page->items())
            ->pluck('id')
            ->sort()
            ->values()
            ->all();

        $expectedIds = collect([
            $ownDraft->id,
            $ownReview->id,
        ])
            ->sort()
            ->values()
            ->all();

        $this->assertSame(
            $expectedIds,
            $visibleIds,
        );

        /*
         * Además de comparar los identificadores, se confirma que ninguna fila
         * visible pertenezca accidentalmente a otro autor.
         */
        $this->assertTrue(
            collect($page->items())
                ->every(
                    fn (Article $article): bool =>
                        $article->author_id === $journalist->id,
                ),
        );
    }

    /**
     * Comprueba la regla exacta de visibilidad aplicada a un editor.
     */
    public function test_editor_sees_own_articles_and_only_allowed_team_statuses(): void
    {
        $editor = User::factory()->create([
            'role' => 'editor',
        ]);

        $journalist = User::factory()->create([
            'role' => 'journalist',
        ]);

        /*
         * El editor puede ver todas sus noticias, incluso borradores y noticias
         * propias que requieren cambios.
         */
        $ownDraft = $this->createArticle(
            $editor,
            'draft',
        );

        $ownNeedsChanges = $this->createArticle(
            $editor,
            'needs_changes',
        );

        /*
         * De otros autores solamente puede ver noticias que ya están bajo
         * control editorial o que pasaron por el flujo editorial.
         */
        $teamReview = $this->createArticle(
            $journalist,
            'review',
        );

        $teamScheduled = $this->createArticle(
            $journalist,
            'scheduled',
        );

        $teamPublished = $this->createArticle(
            $journalist,
            'published',
        );

        $teamArchived = $this->createArticle(
            $journalist,
            'archived',
        );

        /*
         * Los borradores ajenos y las noticias ajenas que requieren cambios
         * deben permanecer ocultos para el editor.
         */
        $teamDraft = $this->createArticle(
            $journalist,
            'draft',
        );

        $teamNeedsChanges = $this->createArticle(
            $journalist,
            'needs_changes',
        );

        $page = $this->service()->paginate($editor, [
            'per_page' => 100,
        ]);

        $visibleIds = collect($page->items())
            ->pluck('id')
            ->sort()
            ->values()
            ->all();

        $expectedIds = collect([
            $ownDraft->id,
            $ownNeedsChanges->id,
            $teamReview->id,
            $teamScheduled->id,
            $teamPublished->id,
            $teamArchived->id,
        ])
            ->sort()
            ->values()
            ->all();

        $this->assertSame(
            $expectedIds,
            $visibleIds,
        );

        $this->assertNotContains(
            $teamDraft->id,
            $visibleIds,
        );

        $this->assertNotContains(
            $teamNeedsChanges->id,
            $visibleIds,
        );
    }

    /**
     * Comprueba el filtro derivado de portada.
     *
     * Destacadas y urgentes solo incluyen noticias publicadas o programadas,
     * porque son las que ocupan ahora o luego un lugar real en la portada.
     */
    public function test_paginate_filters_homepage_treatment(): void
    {
        $editor = User::factory()->create([
            'role' => 'editor',
        ]);

        $featuredPublished = $this->createArticle($editor, 'published', [
            'is_featured' => true,
        ]);

        $featuredScheduled = $this->createArticle($editor, 'scheduled', [
            'is_featured' => true,
        ]);

        $featuredDraft = $this->createArticle($editor, 'draft', [
            'is_featured' => true,
        ]);

        $breakingPublished = $this->createArticle($editor, 'published', [
            'is_breaking' => true,
        ]);

        $normalPublished = $this->createArticle($editor, 'published');

        $featuredIds = collect(
            $this->service()->paginate($editor, [
                'homepage' => 'featured',
                'per_page' => 100,
            ])->items(),
        )->pluck('id')->sort()->values()->all();

        $this->assertSame(
            collect([
                $featuredPublished->id,
                $featuredScheduled->id,
            ])->sort()->values()->all(),
            $featuredIds,
        );

        $this->assertNotContains($featuredDraft->id, $featuredIds);

        $breakingIds = collect(
            $this->service()->paginate($editor, [
                'homepage' => 'breaking',
                'per_page' => 100,
            ])->items(),
        )->pluck('id')->values()->all();

        $this->assertSame([
            $breakingPublished->id,
        ], $breakingIds);

        $normalIds = collect(
            $this->service()->paginate($editor, [
                'homepage' => 'normal',
                'per_page' => 100,
            ])->items(),
        )->pluck('id')->values()->all();

        $this->assertContains($normalPublished->id, $normalIds);
        $this->assertNotContains($featuredPublished->id, $normalIds);
        $this->assertNotContains($breakingPublished->id, $normalIds);
    }

    /**
     * Comprueba la combinación de búsqueda, estado, autor, categoría y fechas.
     */
    public function test_paginate_applies_search_and_structured_filters(): void
    {
        $editor = User::factory()->create([
            'role' => 'editor',
        ]);

        $journalist = User::factory()->create([
            'role' => 'journalist',
        ]);

        $otherJournalist = User::factory()->create([
            'role' => 'journalist',
        ]);

        $politics = $this->createCategory(
            'Política',
            'politica',
        );

        $economy = $this->createCategory(
            'Economía',
            'economia',
        );

        /*
         * Esta es la única noticia que cumple simultáneamente con búsqueda,
         * estado, categoría, autor y rango de fechas.
         */
        $target = $this->createArticle(
            $journalist,
            'published',
            [
                'category_id' => $politics->id,
                'title' => 'Congreso debate la reforma digital',
                'search_text' => 'congreso debate reforma digital politica',
                'updated_at' => '2026-08-03 12:00:00',
            ],
        );

        // No coincide con el estado published.
        $this->createArticle($journalist, 'review', [
            'category_id' => $politics->id,
            'search_text' => 'reforma digital politica',
            'updated_at' => '2026-08-03 12:00:00',
        ]);

        // No coincide con la categoría Política.
        $this->createArticle($journalist, 'published', [
            'category_id' => $economy->id,
            'search_text' => 'reforma digital economia',
            'updated_at' => '2026-08-03 12:00:00',
        ]);

        // No coincide con el autor seleccionado.
        $this->createArticle($otherJournalist, 'published', [
            'category_id' => $politics->id,
            'search_text' => 'reforma digital politica',
            'updated_at' => '2026-08-03 12:00:00',
        ]);

        // No coincide con el término buscado.
        $this->createArticle($journalist, 'published', [
            'category_id' => $politics->id,
            'search_text' => 'agenda internacional',
            'updated_at' => '2026-08-03 12:00:00',
        ]);

        // Queda fuera del rango de actualización solicitado.
        $this->createArticle($journalist, 'published', [
            'category_id' => $politics->id,
            'search_text' => 'reforma digital politica',
            'updated_at' => '2026-07-20 12:00:00',
        ]);

        $page = $this->service()->paginate($editor, [
            'search' => 'reforma',
            'status' => 'published',
            'category_id' => $politics->id,
            'author_id' => $journalist->id,
            'date_from' => '2026-08-01',
            'date_to' => '2026-08-05',
            'per_page' => 100,
        ]);

        $this->assertSame(
            [$target->id],
            collect($page->items())
                ->pluck('id')
                ->all(),
        );
    }

    /**
     * Comprueba que el buscador encuentre noticias mediante comienzos de palabras.
     *
     * Ejemplos:
     *
     * ciudad debe encontrar ciudades.
     * noti debe encontrar noticia.
     */
    public function test_paginate_finds_articles_using_partial_word_prefixes(): void
    {
        $editor = User::factory()->create([
            'role' => 'editor',
        ]);

        $cityArticle = $this->createArticle(
            $editor,
            'published',
            [
                'title' => 'Ciudades inteligentes entran en la agenda nacional',
                'search_text' =>
                    'ciudades inteligentes entran en la agenda nacional',
            ],
        );

        $reviewArticle = $this->createArticle(
            $editor,
            'review',
            [
                'title' => 'Noticia a revision111',
                'search_text' => 'noticia a revision111',
            ],
        );

        /*
        * ciudad debe encontrar una palabra que comienza con ese término:
        * ciudades.
        */
        $cityPage = $this->service()->paginate(
            $editor,
            [
                'search' => 'ciudad',
                'per_page' => 100,
            ],
        );

        $this->assertSame(
            [
                $cityArticle->id,
            ],
            collect($cityPage->items())
                ->pluck('id')
                ->all(),
        );

        /*
        * noti debe encontrar una palabra que comienza con ese término:
        * noticia.
        */
        $newsPage = $this->service()->paginate(
            $editor,
            [
                'search' => 'noti',
                'per_page' => 100,
            ],
        );

        $this->assertSame(
            [
                $reviewArticle->id,
            ],
            collect($newsPage->items())
                ->pluck('id')
                ->all(),
        );
    }

    /**
     * Comprueba que tag_id filtre noticias sin cargar sus etiquetas completas.
     */
    public function test_paginate_filters_articles_by_tag(): void
    {
        $editor = User::factory()->create([
            'role' => 'editor',
        ]);

        $technology = $this->createTag(
            'Tecnología',
            'tecnologia',
        );

        $economy = $this->createTag(
            'Economía',
            'economia',
        );

        $technologyArticle = $this->createArticle(
            $editor,
            'published',
        );

        $economyArticle = $this->createArticle(
            $editor,
            'published',
        );

        /*
         * Las etiquetas se relacionan mediante article_tag. El servicio usa
         * whereHas para filtrar, pero no las agrega al resultado del listado.
         */
        $technologyArticle->tags()->attach($technology->id);
        $economyArticle->tags()->attach($economy->id);

        $page = $this->service()->paginate($editor, [
            'tag_id' => $technology->id,
            'per_page' => 100,
        ]);

        $visibleIds = collect($page->items())
            ->pluck('id')
            ->all();

        $this->assertSame(
            [$technologyArticle->id],
            $visibleIds,
        );

        /*
         * El filtro debe funcionar sin cargar la relación tags en cada noticia.
         */
        $this->assertFalse(
            collect($page->items())
                ->first()
                ->relationLoaded('tags'),
        );
    }

    /**
     * Comprueba que los botones de estado respeten los filtros activos.
     *
     * El estado seleccionado se ignora deliberadamente para poder calcular en
     * una sola consulta las cantidades correspondientes a todos los botones.
     */
    public function test_status_filter_counts_respect_filters_and_ignore_status(): void
    {
        $editor = User::factory()->create([
            'role' => 'editor',
        ]);

        $journalist = User::factory()->create([
            'role' => 'journalist',
        ]);

        $this->createArticle($editor, 'published', [
            'search_text' => 'reforma digital',
        ]);

        $this->createArticle($editor, 'review', [
            'search_text' => 'reforma digital',
        ]);

        $this->createArticle($journalist, 'published', [
            'search_text' => 'reforma digital',
        ]);

        /*
         * Esta noticia coincide con la búsqueda, pero no es visible para el
         * editor porque needs_changes pertenece a otro autor.
         */
        $this->createArticle($journalist, 'needs_changes', [
            'search_text' => 'reforma digital',
        ]);

        /*
         * Esta noticia es visible, pero no coincide con la búsqueda activa.
         */
        $this->createArticle($editor, 'draft', [
            'search_text' => 'agenda deportiva',
        ]);

        $counts = $this->service()
            ->getArticleCountsForStatusFilters(
                $editor,
                [
                    'search' => 'reforma',
                    'status' => 'published',
                ],
            );

        $this->assertSame(
            3,
            $counts['total'],
        );

        $this->assertSame(
            [
                'draft' => 0,
                'review' => 1,
                'needs_changes' => 0,
                'scheduled' => 0,
                'published' => 2,
                'archived' => 0,
            ],
            $counts['statuses'],
        );
    }

    /**
     * Comprueba los contadores de noticias en revisión y que requieren cambios.
     */
    public function test_review_and_needs_changes_counts_respect_visibility(): void
    {
        $editor = User::factory()->create([
            'role' => 'editor',
        ]);

        $journalist = User::factory()->create([
            'role' => 'journalist',
        ]);

        $this->createArticle(
            $editor,
            'review',
        );

        $this->createArticle(
            $editor,
            'needs_changes',
        );

        $this->createArticle(
            $journalist,
            'review',
        );

        /*
         * needs_changes ajeno permanece oculto y no debe aumentar el contador.
         */
        $this->createArticle(
            $journalist,
            'needs_changes',
        );

        /*
         * published es visible, pero no pertenece a ninguno de los dos estados
         * que este método necesita contar.
         */
        $this->createArticle(
            $journalist,
            'published',
        );

        $counts = $this->service()
            ->getReviewAndNeedsChangesCounts($editor);

        $this->assertSame(
            [
                'review' => 2,
                'needs_changes' => 1,
            ],
            $counts,
        );
    }

    /**
     * Comprueba el ordenamiento alfabético por categoría.
     *
     * Las noticias sin categoría deben permanecer al final tanto en dirección
     * ascendente como descendente.
     */
    public function test_paginate_orders_articles_by_category_name(): void
    {
        $editor = User::factory()->create([
            'role' => 'editor',
        ]);

        $culture = $this->createCategory(
            'Cultura',
            'cultura-ordenamiento',
        );

        $economy = $this->createCategory(
            'Economía',
            'economia-ordenamiento',
        );

        $cultureArticle = $this->createArticle(
            $editor,
            'published',
            [
                'category_id' => $culture->id,
                'title' => 'Noticia perteneciente a Cultura',
            ],
        );

        $economyArticle = $this->createArticle(
            $editor,
            'published',
            [
                'category_id' => $economy->id,
                'title' => 'Noticia perteneciente a Economía',
            ],
        );

        /*
        * createArticle asigna una categoría predeterminada cuando no se proporciona
        * category_id. Se limpia directamente para crear el escenario sin categoría.
        */
        $uncategorizedArticle = $this->createArticle(
            $editor,
            'published',
            [
                'title' => 'Noticia sin categoría',
            ],
        );

        DB::table('articles')
            ->where(
                'id',
                $uncategorizedArticle->id,
            )
            ->update([
                'category_id' => null,
            ]);

        $ascendingPage = $this->service()->paginate(
            $editor,
            [
                'sort' => 'category',
                'direction' => 'asc',
                'per_page' => 100,
            ],
        );

        $this->assertSame(
            [
                $cultureArticle->id,
                $economyArticle->id,
                $uncategorizedArticle->id,
            ],
            collect($ascendingPage->items())
                ->pluck('id')
                ->all(),
        );

        $descendingPage = $this->service()->paginate(
            $editor,
            [
                'sort' => 'category',
                'direction' => 'desc',
                'per_page' => 100,
            ],
        );

        $this->assertSame(
            [
                $economyArticle->id,
                $cultureArticle->id,
                $uncategorizedArticle->id,
            ],
            collect($descendingPage->items())
                ->pluck('id')
                ->all(),
        );
    }

    /**
     * Comprueba el ordenamiento alfabético por el nombre del autor.
     */
    public function test_paginate_orders_articles_by_author_name(): void
    {
        $editor = User::factory()->create([
            'name' => 'Editora responsable',
            'role' => 'editor',
        ]);

        $ana = User::factory()->create([
            'name' => 'Ana Torres',
            'role' => 'journalist',
        ]);

        $bruno = User::factory()->create([
            'name' => 'Bruno Salvatierra',
            'role' => 'journalist',
        ]);

        $brunoArticle = $this->createArticle(
            $bruno,
            'published',
            [
                'title' => 'Noticia escrita por Bruno',
            ],
        );

        $anaArticle = $this->createArticle(
            $ana,
            'published',
            [
                'title' => 'Noticia escrita por Ana',
            ],
        );

        $ascendingPage = $this->service()->paginate(
            $editor,
            [
                'sort' => 'author',
                'direction' => 'asc',
                'per_page' => 100,
            ],
        );

        $this->assertSame(
            [
                $anaArticle->id,
                $brunoArticle->id,
            ],
            collect($ascendingPage->items())
                ->pluck('id')
                ->all(),
        );

        $descendingPage = $this->service()->paginate(
            $editor,
            [
                'sort' => 'author',
                'direction' => 'desc',
                'per_page' => 100,
            ],
        );

        $this->assertSame(
            [
                $brunoArticle->id,
                $anaArticle->id,
            ],
            collect($descendingPage->items())
                ->pluck('id')
                ->all(),
        );
    }

   /**
     * Comprueba que la paginación numerada avance sin repetir noticias.
     */
    public function test_numbered_pagination_returns_the_second_page_without_duplicates(): void
    {
        $editor = User::factory()->create([
            'role' => 'editor',
        ]);

        /*
        * Todas las noticias comparten updated_at.
        *
        * Esto permite verificar que articles.id funcione como desempate estable
        * cuando varias noticias tienen la misma fecha de actualización.
        */
        foreach (range(1, 5) as $position) {
            $this->createArticle($editor, 'published', [
                'updated_at' => '2026-08-05 09:00:00',
            ]);
        }

        /*
        * Primera página: dos noticias por página.
        */
        $firstPage = $this->service()->paginate($editor, [
            'sort' => 'updated_at',
            'direction' => 'desc',
            'per_page' => 2,
            'page' => 1,
        ]);

        $this->assertInstanceOf(
            LengthAwarePaginator::class,
            $firstPage,
        );

        $this->assertSame(
            1,
            $firstPage->currentPage(),
        );

        $this->assertSame(
            3,
            $firstPage->lastPage(),
        );

        $this->assertSame(
            5,
            $firstPage->total(),
        );

        $this->assertSame(
            2,
            $firstPage->perPage(),
        );

        $this->assertCount(
            2,
            $firstPage->items(),
        );

        $this->assertTrue(
            $firstPage->hasMorePages(),
        );

        $firstPageIds = collect($firstPage->items())
            ->pluck('id')
            ->all();

        /*
        * Segunda página de la misma consulta.
        */
        $secondPage = $this->service()->paginate($editor, [
            'sort' => 'updated_at',
            'direction' => 'desc',
            'per_page' => 2,
            'page' => 2,
        ]);

        $this->assertInstanceOf(
            LengthAwarePaginator::class,
            $secondPage,
        );

        $this->assertSame(
            2,
            $secondPage->currentPage(),
        );

        $this->assertSame(
            3,
            $secondPage->lastPage(),
        );

        $this->assertSame(
            5,
            $secondPage->total(),
        );

        $this->assertSame(
            2,
            $secondPage->perPage(),
        );

        $this->assertCount(
            2,
            $secondPage->items(),
        );

        $secondPageIds = collect($secondPage->items())
            ->pluck('id')
            ->all();

        /*
        * Ninguna noticia de la primera página debe repetirse en la segunda.
        */
        $this->assertSame(
            [],
            array_values(
                array_intersect(
                    $firstPageIds,
                    $secondPageIds,
                ),
            ),
        );
    }

    /**
     * Comprueba que el recurso del listado no exponga contenido pesado.
     */
    public function test_list_resource_returns_only_the_fields_used_by_the_table(): void
    {
        $editor = User::factory()->create([
            'name' => 'Editora de prueba',
            'role' => 'editor',
        ]);

        $category = $this->createCategory(
            'Política',
            'politica',
        );

        $article = $this->createArticle($editor, 'published', [
            'category_id' => $category->id,
            'title' => 'Noticia incluida en el listado editorial',
            'views' => 1250,
            'published_at' => '2026-08-05 08:00:00',
        ]);

        /*
         * Se obtiene la noticia mediante el servicio para comprobar también que
         * author y category hayan sido precargadas correctamente.
         */
        $listedArticle = collect(
            $this->service()
                ->paginate($editor, ['per_page' => 100])
                ->items(),
        )->firstWhere(
            'id',
            $article->id,
        );

        $data = (new AdminArticleListResource($listedArticle))
            ->resolve(request());

        $this->assertSame(
            [
                'id',
                'title',
                'slug',
                'status',
                'is_breaking',
                'is_featured',
                'cover_image',
                'views',
                'author',
                'category',
                'published_at',
                'scheduled_at',
                'updated_at',
            ],
            array_keys($data),
        );

        $this->assertSame(
            $editor->id,
            $data['author']['id'],
        );

        $this->assertSame(
            'Editora de prueba',
            $data['author']['name'],
        );

        $this->assertSame(
            $category->id,
            $data['category']['id'],
        );

        $this->assertSame(
            'Política',
            $data['category']['name'],
        );

        /*
         * Estos campos existen en Article, pero el listado no debe enviarlos al
         * navegador porque son pesados o no se muestran en la tabla.
         */
        foreach ([
            'body',
            'subtitle',
            'excerpt',
            'search_text',
            'tags',
            'reading_time',
            'created_at',
        ] as $excludedField) {
            $this->assertArrayNotHasKey(
                $excludedField,
                $data,
            );
        }
    }

    /**
     * Devuelve la instancia del servicio probada por esta clase.
     */
    private function service(): ArticleIndexQueryService
    {
        return app(ArticleIndexQueryService::class);
    }

    /**
     * Crea una categoría activa con nombre y slug únicos para una prueba.
     */
    private function createCategory(
        string $name,
        string $slug,
    ): Category {
        return Category::query()->create([
            'name' => $name,
            'slug' => $slug,
            'description' => 'Categoría creada por una prueba automatizada.',
            'accent_color' => '#D8323C',
            'sort_order' => 0,
            'is_active' => true,
        ]);
    }

    /**
     * Crea una etiqueta activa para comprobar el filtro tag_id.
     */
    private function createTag(
        string $name,
        string $slug,
    ): Tag {
        return Tag::query()->create([
            'name' => $name,
            'slug' => $slug,
            'description' => 'Etiqueta creada por una prueba automatizada.',
            'is_active' => true,
        ]);
    }

    /**
     * Crea una noticia válida y permite sobrescribir sus atributos.
     *
     * search_text se actualiza directamente porque es una columna técnica y no
     * forma parte de los campos asignables públicamente del modelo Article.
     *
     * @param array<string, mixed> $attributes
     */
    private function createArticle(
        User $author,
        string $status,
        array $attributes = [],
    ): Article {
        $this->articleSequence++;

        $categoryId = $attributes['category_id']
            ?? $this->defaultCategory()->id;

        $title = $attributes['title']
            ?? 'Noticia de prueba '.$this->articleSequence;

        $searchText = $attributes['search_text']
            ?? $title.' contenido editorial verificable';

        $updatedAt = $attributes['updated_at'] ?? null;

        /*
         * Estos valores se escriben después con DB::table porque no forman parte
         * de los campos asignables del modelo o necesitan una fecha controlada.
         */
        unset(
            $attributes['search_text'],
            $attributes['updated_at'],
        );

        $article = Article::query()->create(array_merge([
            'author_id' => $author->id,
            'category_id' => $categoryId,
            'title' => $title,
            'slug' => 'noticia-de-prueba-'.$this->articleSequence,
            'subtitle' => 'Bajada informativa utilizada en la prueba.',
            'excerpt' => 'Resumen editorial utilizado para comprobar el listado administrativo.',
            'body' => '<p>Contenido editorial suficiente para una noticia de prueba.</p>',
            'status' => $status,
            'views' => 0,
            'published_at' => $status === 'published'
                ? TramaClock::now()
                : null,
            'scheduled_at' => $status === 'scheduled'
                ? TramaClock::now()->addDay()
                : null,
        ], $attributes));

        /*
         * DB::table permite escribir search_text sin agregar esa columna técnica
         * a $fillable. También permite fijar updated_at para probar rangos y
         * desempates de cursor de manera determinista.
         */
        $technicalValues = [
            'search_text' => $searchText,
        ];

        if ($updatedAt !== null) {
            $technicalValues['updated_at'] = $updatedAt;
        }

        DB::table('articles')
            ->where(
                'id',
                $article->id,
            )
            ->update($technicalValues);

        return $article->refresh();
    }

    /**
     * Devuelve la categoría reutilizada por las noticias que no necesitan una
     * categoría específica para su escenario.
     */
    private function defaultCategory(): Category
    {
        return Category::query()->firstOrCreate(
            [
                'slug' => 'general',
            ],
            [
                'name' => 'General',
                'description' => 'Categoría general para pruebas.',
                'accent_color' => '#D6A23A',
                'sort_order' => 0,
                'is_active' => true,
            ],
        );
    }
}
