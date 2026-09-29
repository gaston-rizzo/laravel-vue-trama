<?php

/* ============================================================================
 * TEST: EditorialWorkflowTest.php
 * ============================================================================
 *
 * Comprueba los flujos públicos, editoriales y administrativos de TRAMA.
 *
 * Las pruebas cubren permisos, comentarios, portadas, slugs, devoluciones,
 * revisiones, etiquetas, usuarios, sanitización y publicación programada.
 * ============================================================================ */

namespace Tests\Feature;

use App\Jobs\ModerateComment;
use App\Http\Resources\CommentResource;
use App\Http\Resources\Editorial\AdminCommentDetailResource;
use App\Http\Resources\Editorial\AdminCommentListResource;
use App\Models\Advertisement;
use App\Models\Article;
use App\Models\ArticleRevision;
use App\Models\Category;
use App\Models\Comment;
use App\Models\Tag;
use App\Models\User;
use App\Services\Editorial\ArticleWorkflowService;
use App\Support\TramaClock;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\DB;

use Inertia\Testing\AssertableInertia as Assert;

use RuntimeException;
use Tests\TestCase;

class EditorialWorkflowTest extends TestCase
{
    use RefreshDatabase;


    /**
     * Los tests de comentarios verifican el estado inmediatamente posterior al
     * request. La moderación real corre en Queue, por eso se finge únicamente
     * ModerateComment para no ejecutar los modelos Node dentro de PHPUnit.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake([
            ModerateComment::class,
        ]);
    }

    public function test_guest_comment_is_redirected_to_login(): void
    {
        $article = $this->publishedArticle();

        $response = $this->from(route('articles.show', $article->slug))
            ->post(route('comments.store', $article), [
                'body' => 'Comentario con cuerpo suficiente.',
            ]);

        $response->assertRedirect(route('login', absolute: false));
        $this->assertDatabaseCount('comments', 0);
    }

    public function test_authenticated_registered_user_comment_is_saved_as_processing(): void
    {
        $reader = User::factory()->create(['name' => 'Sofia Herrera', 'email' => 'sofia@example.com']);
        $article = $this->publishedArticle();

        $response = $this->actingAs($reader)->post(route('comments.store', $article), [
            'body' => 'Comentario con cuerpo suficiente.',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('comments', [
            'article_id' => $article->id,
            'user_id' => $reader->id,
            'author_name' => 'Sofia Herrera',
            'author_email' => 'sofia@example.com',
            'status' => 'processing',
            'moderation_revision' => 1,
            'moderation_source' => 'automatic',
        ]);
    }

    public function test_editor_can_upload_cover_image_when_creating_article(): void
    {
        $editor = User::factory()->create(['role' => 'editor']);
        $category = Category::query()->create([
            'name' => 'Tecnologia',
            'slug' => 'tecnologia',
            'description' => 'Cobertura de innovacion.',
            'accent_color' => '#29C7AC',
            'is_active' => true,
        ]);

        $temporaryCover = tempnam(sys_get_temp_dir(), 'trama-cover-').'.jpg';
        File::copy(public_path('images/brand/noticia-default.webp'), $temporaryCover);

        $response = $this->actingAs($editor)->post(route('admin.articles.store'), [
            'category_id' => $category->id,
            'title' => 'Nueva cobertura de inteligencia artificial',
            'subtitle' => 'Equipos y empresas ajustan procesos.',
            'excerpt' => 'Una mirada al impacto de la IA en la productividad dentro de las redacciones.',
            'body' => str_repeat('La redaccion verifica datos antes de publicar. ', 8),
            'status' => 'published',
            'tag_ids' => [],
            'cover_image_file' => new UploadedFile($temporaryCover, 'portada.jpg', 'image/jpeg', null, true),
        ]);

        $response->assertRedirect();

        $article = Article::query()->where('title', 'Nueva cobertura de inteligencia artificial')->firstOrFail();
        $this->assertStringStartsWith('/images/uploads/articles/', $article->cover_image);
        $this->assertTrue(File::exists(public_path(ltrim($article->cover_image, '/'))));
        $this->assertDatabaseHas('media_assets', ['article_id' => $article->id, 'path' => $article->cover_image]);

        File::delete(public_path(ltrim($article->cover_image, '/')));
    }

    public function test_article_slug_is_generated_from_title(): void
    {
        $editor = User::factory()->create(['role' => 'editor']);
        $category = $this->category();

        $response = $this->actingAs($editor)->post(route('admin.articles.store'), [
            'category_id' => $category->id,
            'title' => 'Nuevo informe de mercados digitales',
            'slug' => 'enlace-elegido-a-mano',
            'subtitle' => 'La redaccion prepara una cobertura de prueba.',
            'excerpt' => 'Una bajada breve para validar la creacion automatica de la URL publica.',
            'body' => str_repeat('El equipo editorial revisa la informacion antes de publicar. ', 7),
            'status' => 'draft',
            'tag_ids' => [],
        ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('articles', [
            'title' => 'Nuevo informe de mercados digitales',
            'slug' => 'nuevo-informe-de-mercados-digitales',
        ]);
    }

    public function test_duplicate_article_titles_get_unique_slugs(): void
    {
        $editor = User::factory()->create(['role' => 'editor']);
        $category = $this->category();

        Article::query()->create([
            'author_id' => $editor->id,
            'category_id' => $category->id,
            'title' => 'Nuevo informe de mercados digitales',
            'slug' => 'nuevo-informe-de-mercados-digitales',
            'subtitle' => 'Nota anterior.',
            'excerpt' => 'Una bajada breve para la nota anterior.',
            'body' => str_repeat('La cobertura previa mantiene su URL original. ', 7),
            'status' => 'draft',
        ]);

        $response = $this->actingAs($editor)->post(route('admin.articles.store'), [
            'category_id' => $category->id,
            'title' => 'Nuevo informe de mercados digitales',
            'subtitle' => 'Nota nueva con el mismo titulo.',
            'excerpt' => 'Una bajada breve para validar slugs duplicados sin afectar la publicacion.',
            'body' => str_repeat('El editor guarda otra noticia con un titulo repetido. ', 7),
            'status' => 'draft',
            'tag_ids' => [],
        ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('articles', [
            'title' => 'Nuevo informe de mercados digitales',
            'slug' => 'nuevo-informe-de-mercados-digitales-2',
        ]);
    }

    public function test_article_slug_stays_stable_when_title_changes(): void
    {
        $editor = User::factory()->create(['role' => 'editor']);
        $article = $this->publishedArticle($editor, 'draft');

        $response = $this->actingAs($editor)->post(route('admin.articles.update', $article), [
            '_method' => 'put',
            'category_id' => $article->category_id,
            'title' => 'Titulo editado despues de guardar',
            'slug' => 'intento-de-cambiar-la-url',
            'subtitle' => $article->subtitle,
            'excerpt' => $article->excerpt,
            'body' => $article->body,
            'status' => 'draft',
            'tag_ids' => [],
        ]);

        $response->assertRedirect();
        $article->refresh();

        $this->assertSame('congreso-debate-una-nueva-agenda-publica', $article->slug);
    }

    public function test_article_text_fields_enforce_editorial_limits(): void
    {
        $editor = User::factory()->create(['role' => 'editor']);
        $category = $this->category();

        $response = $this->actingAs($editor)
            ->from(route('admin.articles.create'))
            ->post(route('admin.articles.store'), [
                'category_id' => $category->id,
                'title' => 'Corto',
                'subtitle' => 'Bajada corta',
                'excerpt' => 'Resumen corto',
                'body' => 'Cuerpo corto.',
                'status' => 'draft',
                'tag_ids' => [],
            ]);

        $response->assertRedirect(route('admin.articles.create'));
        $response->assertSessionHasErrors([
            'title',
            'subtitle',
            'excerpt',
            'body',
        ]);
    }

    public function test_article_text_fields_normalize_extra_line_breaks(): void
    {
        $editor = User::factory()->create(['role' => 'editor']);
        $category = $this->category();
        $firstParagraph = 'Primer parrafo con informacion verificada sobre el caso, fuentes consultadas y un marco de contexto suficiente para que la noticia mantenga claridad editorial.';
        $secondParagraph = 'Segundo parrafo con datos complementarios, antecedentes relevantes y una explicacion clara para que el lector pueda seguir la historia sin perder continuidad.';
        $thirdParagraph = 'Tercer parrafo con cierre provisorio, posibles proximos pasos y criterios de seguimiento para sostener una cobertura responsable dentro del portal.';

        $response = $this->actingAs($editor)->post(route('admin.articles.store'), [
            'category_id' => $category->id,
            'title' => "Titulo con\n salto editorial",
            'subtitle' => "Bajada con\r\n salto interno para validar limpieza editorial",
            'excerpt' => "Resumen con\n\n saltos vacios que debe quedar en una sola linea para portada y busqueda.",
            'body' => "  {$firstParagraph}\r\n\r\n\r\n\r\n  {$secondParagraph}\n\n\n{$thirdParagraph}  ",
            'status' => 'draft',
            'tag_ids' => [],
        ]);

        $response->assertRedirect();

        $article = Article::query()->where('slug', 'titulo-con-salto-editorial')->firstOrFail();

        $this->assertSame('Titulo con salto editorial', $article->title);
        $this->assertSame('Bajada con salto interno para validar limpieza editorial', $article->subtitle);
        $this->assertSame('Resumen con saltos vacios que debe quedar en una sola linea para portada y busqueda.', $article->excerpt);
        $this->assertSame(
            '<p>'.$firstParagraph.'</p><p>'.$secondParagraph.'</p><p>'.$thirdParagraph.'</p>',
            $article->body
        );
        $this->assertStringNotContainsString("\n\n\n", $article->body);
    }

    public function test_creating_article_builds_editorial_search_text(): void
    {
        $editor = User::factory()->create(['role' => 'editor']);
        $category = $this->category();

        $tag = Tag::query()->create([
            'name' => 'Ciberseguridad',
            'slug' => 'ciberseguridad',
            'is_active' => true,
        ]);

        $response = $this->actingAs($editor)->post(
            route('admin.articles.store'),
            [
                'category_id' => $category->id,
                'title' => 'Congreso analiza una nueva política de datos',
                'subtitle' => 'El proyecto incorpora controles para organismos públicos.',
                'excerpt' => 'La iniciativa propone nuevas reglas para proteger información sensible del Estado.',
                'body' => '<p>'.str_repeat(
                    'El debate incluye medidas de seguridad digital y protección de datos ciudadanos. ',
                    8
                ).'</p>',
                'status' => 'draft',
                'tag_ids' => [$tag->id],
            ]
        );

        $response->assertRedirect();

        $article = Article::query()
            ->where('title', 'Congreso analiza una nueva política de datos')
            ->firstOrFail();

        $this->assertNotNull($article->search_text);
        $this->assertStringContainsString(
            'Congreso analiza una nueva política de datos',
            $article->search_text
        );
        $this->assertStringContainsString(
            'seguridad digital',
            $article->search_text
        );
        $this->assertStringContainsString(
            'Politica',
            $article->search_text
        );
        $this->assertStringContainsString(
            'Ciberseguridad',
            $article->search_text
        );
        $this->assertStringNotContainsString(
            '<p>',
            $article->search_text
        );
    }

    public function test_updating_article_rebuilds_editorial_search_text(): void
    {
        $editor = User::factory()->create(['role' => 'editor']);
        $article = $this->publishedArticle($editor, 'draft');

        $oldTag = Tag::query()->create([
            'name' => 'Etiqueta anterior',
            'slug' => 'etiqueta-anterior',
            'is_active' => true,
        ]);

        $newTag = Tag::query()->create([
            'name' => 'Robótica',
            'slug' => 'robotica',
            'is_active' => true,
        ]);

        $article->tags()->attach($oldTag);

        $response = $this->actingAs($editor)->put(
            route('admin.articles.update', $article),
            [
                'category_id' => $article->category_id,
                'title' => 'Empresas incorporan robots en sus fábricas',
                'subtitle' => 'La automatización modifica los procesos industriales.',
                'excerpt' => 'Un informe analiza la incorporación de nuevas tecnologías en las plantas productivas.',
                'body' => '<p>'.str_repeat(
                    'Los sistemas automatizados aumentan la precisión y modifican las tareas industriales. ',
                    8
                ).'</p>',
                'status' => 'draft',
                'tag_ids' => [$newTag->id],
            ]
        );

        $response->assertRedirect();

        $article->refresh();

        $this->assertStringContainsString(
            'Empresas incorporan robots en sus fábricas',
            $article->search_text
        );

        $this->assertStringContainsString(
            'sistemas automatizados',
            $article->search_text
        );

        $this->assertStringContainsString(
            'Robótica',
            $article->search_text
        );

        $this->assertStringNotContainsString(
            'Etiqueta anterior',
            $article->search_text
        );

        $this->assertStringNotContainsString(
            '<p>',
            $article->search_text
        );
    }

    public function test_article_search_matches_title_and_tags(): void
    {
        $article = $this->publishedArticle();
        $tag = Tag::query()->create([
            'name' => 'Inteligencia Artificial',
            'slug' => 'inteligencia-artificial',
        ]);

        $article->tags()->attach($tag);

        $titleResults = Article::query()
            // Solo noticias publicadas forman parte de los resultados públicos.
            ->where('status', 'published')
            ->whereNotNull('published_at')
            ->where('published_at', '<=', TramaClock::now())
            // Busca el texto en el título para comprobar la búsqueda básica.
            ->where('title', 'like', '%Congreso%')
            ->pluck('id');

        $tagResults = Article::query()
            // Solo noticias publicadas forman parte de los resultados públicos.
            ->where('status', 'published')
            ->whereNotNull('published_at')
            ->where('published_at', '<=', TramaClock::now())
            // Busca el texto dentro de etiquetas relacionadas.
            ->whereHas('tags', fn (Builder $tagQuery) => $tagQuery->where('name', 'like', '%Artificial%'))
            ->pluck('id');

        $this->assertTrue($titleResults->contains($article->id));
        $this->assertTrue($tagResults->contains($article->id));
    }

    public function test_journalist_cannot_publish_directly(): void
    {
        $journalist = User::factory()->create(['role' => 'journalist']);
        $category = $this->category();

        $response = $this->actingAs($journalist)
            ->from(route('admin.articles.create'))
            ->post(route('admin.articles.store'), [
                'category_id' => $category->id,
                'title' => 'Investigacion lista para publicar',
                'subtitle' => 'El periodista intenta publicar sin aprobacion.',
                'excerpt' => 'Una bajada de prueba para validar permisos dentro del flujo editorial.',
                'body' => str_repeat('La noticia queda protegida por flujo editorial. ', 8),
                'status' => 'published',
                'tag_ids' => [],
            ]);

        $response->assertSessionHasErrors(['status']);
        $this->assertDatabaseMissing('articles', ['title' => 'Investigacion lista para publicar']);
    }

    public function test_journalist_can_send_article_to_review(): void
    {
        $journalist = User::factory()->create(['role' => 'journalist']);
        $category = $this->category();

        $response = $this->actingAs($journalist)->post(route('admin.articles.store'), [
            'category_id' => $category->id,
            'title' => 'Informe enviado a revision',
            'subtitle' => 'La nota queda esperando aprobacion.',
            'excerpt' => 'Una bajada de prueba para validar el flujo profesional de revision.',
            'body' => str_repeat('La noticia se envia al editor antes de publicar. ', 8),
            'status' => 'review',
            'tag_ids' => [],
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('articles', [
            'title' => 'Informe enviado a revision',
            'author_id' => $journalist->id,
            'status' => 'review',
            'published_at' => null,
        ]);
    }

    public function test_editor_can_publish_article_in_review(): void
    {
        $journalist = User::factory()->create(['role' => 'journalist']);
        $editor = User::factory()->create(['role' => 'editor']);
        $article = $this->publishedArticle($journalist, 'review');

        $response = $this->actingAs($editor)->post(route('admin.articles.update', $article), [
            '_method' => 'put',
            'category_id' => $article->category_id,
            'title' => $article->title,
            'subtitle' => $article->subtitle,
            'excerpt' => $article->excerpt,
            'body' => $article->body,
            'status' => 'published',
            'tag_ids' => [],
        ]);

        $response->assertRedirect();
        $article->refresh();

        $this->assertSame('published', $article->status);
        $this->assertNotNull($article->published_at);
    }

    public function test_editor_archives_article_without_leaving_it_public(): void
    {
        $editor = User::factory()->create(['role' => 'editor']);
        $article = $this->publishedArticle($editor);

        $response = $this->actingAs($editor)->delete(route('admin.articles.destroy', $article));

        $response->assertRedirect(route('admin.articles.index', absolute: false));
        $article->refresh();

        $this->assertSame('archived', $article->status);
        $this->assertNull($article->published_at);
        $this->assertNull($article->deleted_at);
        $this->assertFalse(Article::query()
            // Una noticia archivada no debe cumplir las condiciones públicas.
            ->where('status', 'published')
            ->whereNotNull('published_at')
            ->where('published_at', '<=', TramaClock::now())
            ->whereKey($article)
            ->exists());
    }

    public function test_author_can_permanently_delete_own_draft(): void
    {
        $journalist = User::factory()->create([
            'role' => 'journalist',
        ]);

        $article = $this->publishedArticle(
            $journalist,
            'draft'
        );

        $tag = Tag::query()->create([
            'name' => 'Borrador eliminable',
            'slug' => 'borrador-eliminable',
            'is_active' => true,
        ]);

        $article->tags()->attach($tag);

        $article->revisions()->create([
            'user_id' => $journalist->id,
            'action' => 'created',
            'status_from' => null,
            'status_to' => 'draft',
            'snapshot' => $article->revisionSnapshot(),
        ]);

        $coverPath = '/images/articles/borrador-a-eliminar.webp';

        $coverFile =
            (string) config('trama.articles.cover_path')
            .DIRECTORY_SEPARATOR
            .basename($coverPath);

        File::ensureDirectoryExists(dirname($coverFile));
        File::put($coverFile, 'portada de prueba');

        $article->update([
            'cover_image' => $coverPath,
        ]);

        $article->mediaAssets()->create([
            'uploaded_by' => $journalist->id,
            'disk' => 'public',
            'usage' => 'cover',
            'path' => $coverPath,
        ]);

        try {
            $response = $this
                ->actingAs($journalist)
                ->delete(
                    route(
                        'admin.articles.delete-permanently',
                        $article
                    )
                );

            $response->assertRedirect(
                route('admin.articles.index', absolute: false)
            );

            $this->assertDatabaseMissing('articles', [
                'id' => $article->id,
            ]);

            $this->assertDatabaseMissing('article_revisions', [
                'article_id' => $article->id,
            ]);

            $this->assertDatabaseMissing('media_assets', [
                'article_id' => $article->id,
            ]);

            $this->assertDatabaseMissing('article_tag', [
                'article_id' => $article->id,
            ]);

            $this->assertFalse(File::exists($coverFile));
        } finally {
            // Evita dejar el archivo si una afirmación anterior falla.
            File::delete($coverFile);
        }
    }

    public function test_author_can_permanently_discard_returned_article(): void
    {
        $journalist = User::factory()->create([
            'role' => 'journalist',
        ]);

        $editor = User::factory()->create([
            'role' => 'editor',
        ]);

        $article = $this->publishedArticle(
            $journalist,
            'needs_changes'
        );

        $article->reviewFeedback()->create([
            'returned_by' => $editor->id,
            'message' => 'Revisar las fuentes antes de reenviar.',
        ]);

        $article->revisions()->create([
            'user_id' => $editor->id,
            'action' => 'changes_requested',
            'status_from' => 'review',
            'status_to' => 'needs_changes',
            'snapshot' => $article->revisionSnapshot(),
        ]);

        $response = $this
            ->actingAs($journalist)
            ->delete(
                route(
                    'admin.articles.delete-permanently',
                    $article
                )
            );

        $response->assertRedirect(
            route('admin.articles.index', absolute: false)
        );

        $this->assertDatabaseMissing('articles', [
            'id' => $article->id,
        ]);

        $this->assertDatabaseMissing(
            'article_review_feedback',
            [
                'article_id' => $article->id,
            ]
        );

        $this->assertDatabaseMissing('article_revisions', [
            'article_id' => $article->id,
        ]);
    }

    public function test_user_cannot_delete_another_author_draft(): void
    {
        $owner = User::factory()->create([
            'role' => 'journalist',
        ]);

        $anotherJournalist = User::factory()->create([
            'role' => 'journalist',
        ]);

        $article = $this->publishedArticle(
            $owner,
            'draft'
        );

        $this
            ->actingAs($anotherJournalist)
            ->delete(
                route(
                    'admin.articles.delete-permanently',
                    $article
                )
            )
            ->assertForbidden();

        $this->assertDatabaseHas('articles', [
            'id' => $article->id,
        ]);
    }

    public function test_article_under_review_cannot_be_permanently_deleted(): void
    {
        $journalist = User::factory()->create([
            'role' => 'journalist',
        ]);

        $article = $this->publishedArticle(
            $journalist,
            'review'
        );

        $this
            ->actingAs($journalist)
            ->delete(
                route(
                    'admin.articles.delete-permanently',
                    $article
                )
            )
            ->assertStatus(409);

        $this->assertDatabaseHas('articles', [
            'id' => $article->id,
            'status' => 'review',
        ]);
    }

    public function test_journalist_cannot_edit_another_author_article(): void
    {
        $owner = User::factory()->create(['role' => 'journalist']);
        $anotherJournalist = User::factory()->create(['role' => 'journalist']);
        $article = $this->publishedArticle($owner, 'review');

        $response = $this->actingAs($anotherJournalist)->get(route('admin.articles.edit', $article));

        $response->assertForbidden();
    }


    public function test_admin_can_create_an_editor_account(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->post(route('admin.users.store'), [
            'name' => 'Nueva Editora',
            'email_local' => 'nueva.editora',
            'role' => 'editor',
            'job_title' => 'Editora de Política',
            'bio' => 'Responsable de revisar la agenda política.',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'is_active' => true,
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('users', [
            'email' => 'nueva.editora@trama.test',
            'role' => 'editor',
            'is_active' => true,
        ]);

        // El alta pertenece a la fecha editorial configurada para la demo.
        $created = User::query()->where('email', 'nueva.editora@trama.test')->firstOrFail();
        $this->assertSame((string) config('trama.reference_date'), $created->created_at?->toDateString());
    }

    public function test_employee_profile_limits_are_validated_on_create(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->post(route('admin.users.store'), [
            'name' => str_repeat('N', 61),
            'email_local' => str_repeat('a', 40),
            'role' => 'journalist',
            'job_title' => str_repeat('C', 61),
            'bio' => str_repeat('B', 301),
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'is_active' => true,
        ]);

        $response->assertSessionHasErrors([
            'name',
            'email_local',
            'job_title',
            'bio',
        ]);
    }

    public function test_employee_required_fields_are_validated_on_create(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->post(route('admin.users.store'), [
            'name' => '',
            'email_local' => '',
            'role' => '',
            'job_title' => '',
            'bio' => '',
            'password' => '',
            'password_confirmation' => '',
        ]);

        $response->assertSessionHasErrors([
            'name',
            'email_local',
            'role',
            'job_title',
            'bio',
            'password',
        ]);
    }

    public function test_employee_biography_is_normalized_to_one_paragraph(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->post(route('admin.users.store'), [
            'name' => 'Ana Martínez',
            'email_local' => 'ana.martinez',
            'role' => 'journalist',
            'job_title' => 'Periodista de política',
            'bio' => "Primer párrafo.\n\n\n\nSegundo párrafo.\n\n\nTercer párrafo.",
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'is_active' => true,
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('users', [
            'email' => 'ana.martinez@trama.test',
            'bio' => 'Primer párrafo. Segundo párrafo. Tercer párrafo.',
        ]);
    }

    public function test_admin_cannot_edit_registered_user_name_or_email(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $reader = User::factory()->create([
            'role' => 'reader',
            'name' => 'Nombre Original',
            'email' => 'lector.original@example.com',
        ]);

        $response = $this->actingAs($admin)->patch(
            route('admin.users.update', $reader),
            [
                'name' => 'Nombre Alterado',
                'email' => 'correo.alterado@example.com',
                'role' => 'reader',
                'job_title' => 'No corresponde',
                'bio' => 'No corresponde',
            ]
        );

        $response->assertForbidden();
        $this->assertDatabaseHas('users', [
            'id' => $reader->id,
            'name' => 'Nombre Original',
            'email' => 'lector.original@example.com',
            'role' => 'reader',
        ]);
    }

    public function test_reader_block_requires_reason_and_internal_note(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $reader = User::factory()->create(['role' => 'reader', 'is_active' => true]);

        $this->actingAs($admin)
            ->patch(route('admin.users.toggle-active', $reader), [])
            ->assertSessionHasErrors(['blocked_reason', 'blocked_note']);

        $this->assertTrue($reader->fresh()->is_active);
    }

    public function test_employee_block_does_not_store_reader_moderation_metadata(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $employee = User::factory()->create([
            'role' => 'journalist',
            'is_active' => true,
            'blocked_reason' => null,
            'blocked_note' => null,
        ]);

        $this->actingAs($admin)
            ->patch(route('admin.users.toggle-active', $employee), [
                'blocked_reason' => 'spam',
                'blocked_note' => 'Este texto no corresponde a empleados internos.',
            ])
            ->assertRedirect();

        $employee->refresh();
        $this->assertFalse($employee->is_active);
        $this->assertNull($employee->blocked_reason);
        $this->assertNull($employee->blocked_note);
        $this->assertSame(
            (string) config('trama.reference_date'),
            $employee->disabled_at?->toDateString(),
        );
    }

    public function test_inactive_account_cannot_log_in(): void
    {
        $user = User::factory()->create([
            'email' => 'bloqueada@trama.test',
            'password' => 'password',
            'is_active' => false,
            'disabled_at' => TramaClock::now(),
        ]);

        $response = $this->post(route('login'), [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertSessionHasErrors(['email']);
        $this->assertGuest();
    }

    public function test_editor_can_return_article_with_required_feedback(): void
    {
        $journalist = User::factory()->create(['role' => 'journalist']);
        $editor = User::factory()->create(['role' => 'editor']);
        $article = $this->publishedArticle($journalist, 'review');

        $response = $this->actingAs($editor)->patch(
            route('admin.articles.request-changes', $article),
            ['message' => 'Revisar la introducción y agregar una fuente verificable.']
        );

        $response->assertRedirect();
        $this->assertDatabaseHas('articles', [
            'id' => $article->id,
            'status' => 'needs_changes',
        ]);
        $this->assertDatabaseHas('article_review_feedback', [
            'article_id' => $article->id,
            'returned_by' => $editor->id,
            'message' => 'Revisar la introducción y agregar una fuente verificable.',
            'resolved_at' => null,
        ]);
        $this->assertDatabaseHas('article_revisions', [
            'article_id' => $article->id,
            'action' => 'changes_requested',
            'status_from' => 'review',
            'status_to' => 'needs_changes',
        ]);
    }

    public function test_unexpected_return_article_error_is_friendly_and_does_not_change_status(): void
    {
        $journalist = User::factory()->create(['role' => 'journalist']);
        $editor = User::factory()->create(['role' => 'editor']);
        $article = $this->publishedArticle($journalist, 'review');

        $this->mock(ArticleWorkflowService::class, function ($mock): void {
            $mock->shouldReceive('requestChanges')
                ->once()
                ->andThrow(new RuntimeException('Fallo técnico interno de prueba.'));
        });

        $response = $this->actingAs($editor)->patch(
            route('admin.articles.request-changes', $article),
            ['message' => 'Revisar la introducción y agregar una fuente verificable.']
        );

        $response->assertSessionHasErrors([
            'operation' => 'No pudimos devolver la noticia para corrección. No se realizaron cambios. Intentá nuevamente.',
        ]);

        $this->assertDatabaseHas('articles', [
            'id' => $article->id,
            'status' => 'review',
        ]);

        $this->assertDatabaseMissing('article_review_feedback', [
            'article_id' => $article->id,
        ]);
    }

    public function test_journalist_can_resubmit_a_corrected_article(): void
    {
        $journalist = User::factory()->create(['role' => 'journalist']);
        $editor = User::factory()->create(['role' => 'editor']);
        $article = $this->publishedArticle($journalist, 'review');

        $this->actingAs($editor)->patch(
            route('admin.articles.request-changes', $article),
            ['message' => 'Corregir el segundo párrafo antes de publicar.']
        )->assertRedirect();

        $response = $this->actingAs($journalist)->patch(
            route('admin.articles.resubmit', $article)
        );

        $response->assertRedirect();
        $this->assertDatabaseHas('articles', [
            'id' => $article->id,
            'status' => 'review',
        ]);
        $this->assertDatabaseMissing('article_review_feedback', [
            'article_id' => $article->id,
            'resolved_at' => null,
        ]);
        $this->assertDatabaseHas('article_revisions', [
            'article_id' => $article->id,
            'action' => 'resubmitted',
            'status_from' => 'needs_changes',
            'status_to' => 'review',
        ]);
    }

    public function test_journalist_can_consult_but_not_edit_article_under_review(): void
    {
        $journalist = User::factory()->create(['role' => 'journalist']);
        $article = $this->publishedArticle($journalist, 'review');

        $this->actingAs($journalist)
            ->get(route('admin.articles.edit', $article))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('screen', 'Admin/Articles/Form')
                ->where('screenProps.readOnly', true));

        $this->actingAs($journalist)
            ->put(route('admin.articles.update', $article), [
                'category_id' => $article->category_id,
                'title' => 'Intento de modificar una noticia cerrada',
                'subtitle' => $article->subtitle,
                'excerpt' => $article->excerpt,
                'body' => $article->body,
                'status' => 'review',
                'tag_ids' => [],
            ])
            ->assertForbidden();
    }

    public function test_editor_restores_revision_as_a_new_draft(): void
    {
        $editor = User::factory()->create(['role' => 'editor']);
        $article = $this->publishedArticle($editor, 'published');
        $revision = ArticleRevision::query()->create([
            'article_id' => $article->id,
            'user_id' => $editor->id,
            'action' => 'updated',
            'status_from' => 'draft',
            'status_to' => 'draft',
            'snapshot' => [
                'title' => 'Versión histórica restaurable',
                'subtitle' => 'Subtítulo de la versión histórica.',
                'excerpt' => 'Resumen de la versión histórica con contenido suficiente para identificarla.',
                'body' => '<p>'.str_repeat('Contenido histórico seguro. ', 20).'</p>',
                'category_id' => $article->category_id,
                'cover_image' => null,
                'cover_alt' => null,
                'is_breaking' => false,
                'is_featured' => false,                
                'tag_ids' => [],
            ],
        ]);

        $response = $this->actingAs($editor)->post(
            route('admin.articles.revisions.restore', [$article, $revision])
        );

        $response->assertRedirect(route('admin.articles.edit', $article, absolute: false));
        $article->refresh();

        $this->assertNotNull($article->search_text);

        $this->assertStringContainsString(
            'Versión histórica restaurable',
            $article->search_text
        );

        $this->assertStringContainsString(
            'Contenido histórico seguro',
            $article->search_text
        );

        $this->assertStringNotContainsString(
            'Congreso debate una nueva agenda publica',
            $article->search_text
        );

        $this->assertStringNotContainsString(
            '<p>',
            $article->search_text
        );

        $this->assertSame('Versión histórica restaurable', $article->title);
        $this->assertSame('draft', $article->status);
        $this->assertNull($article->published_at);
        $this->assertDatabaseHas('article_revisions', [
            'article_id' => $article->id,
            'action' => 'restored_as_draft',
            'restored_from_revision_id' => $revision->id,
        ]);
    }

    public function test_rebuild_search_index_command_does_not_change_updated_at(): void
    {
        $article = $this->publishedArticle();
        $originalUpdatedAt = TramaClock::initial()->subDays(2)->startOfSecond();

        /*
        * Simula una noticia cuyo índice quedó desactualizado.
        */
        DB::table('articles')
            ->where('id', $article->id)
            ->update([
                'search_text' => 'indice desactualizado',
                'updated_at' => $originalUpdatedAt,
            ]);

        $this->artisan('articles:rebuild-search-index', [
            '--chunk' => 1,
        ])->assertSuccessful();

        $article->refresh();

        $this->assertStringContainsString(
            'Congreso debate una nueva agenda publica',
            $article->search_text
        );

        $this->assertStringNotContainsString(
            'indice desactualizado',
            $article->search_text
        );

        $this->assertSame(
            $originalUpdatedAt->format('Y-m-d H:i:s'),
            $article->updated_at->format('Y-m-d H:i:s')
        );
    }

    public function test_admin_can_merge_duplicate_tags_without_duplicate_pivots(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $articleOne = $this->publishedArticle($admin, 'draft');
        $articleTwo = Article::query()->create([
            'author_id' => $admin->id,
            'category_id' => $articleOne->category_id,
            'title' => 'Segunda noticia para probar etiquetas',
            'slug' => 'segunda-noticia-para-probar-etiquetas',
            'subtitle' => 'Una segunda noticia utilizada por la prueba.',
            'excerpt' => 'Resumen de la segunda noticia usado para comprobar una fusión segura.',
            'body' => '<p>'.str_repeat('Contenido editorial de prueba. ', 20).'</p>',
            'status' => 'draft',
        ]);
        $source = Tag::query()->create(['name' => 'IA', 'slug' => 'ia', 'is_active' => true]);
        $target = Tag::query()->create(['name' => 'Inteligencia artificial', 'slug' => 'inteligencia-artificial', 'is_active' => true]);
        $historicalAlias = Tag::query()->create([
            'name' => 'Machine learning',
            'slug' => 'machine-learning',
            'is_active' => false,
            'merged_into_id' => $source->id,
        ]);
        $articleOne->tags()->attach([$source->id, $target->id]);
        $articleTwo->tags()->attach($source->id);

        $response = $this->actingAs($admin)->post(
            route('admin.tags.merge', $source),
            ['target_tag_id' => $target->id]
        );

        $response->assertRedirect();
        $this->assertDatabaseMissing('article_tag', ['tag_id' => $source->id]);
        $this->assertDatabaseHas('article_tag', ['article_id' => $articleOne->id, 'tag_id' => $target->id]);
        $this->assertDatabaseHas('article_tag', ['article_id' => $articleTwo->id, 'tag_id' => $target->id]);
        $this->assertSame(2, Tag::query()->findOrFail($target->id)->articles()->count());
        $this->assertDatabaseHas('tags', [
            'id' => $source->id,
            'is_active' => false,
            'merged_into_id' => $target->id,
        ]);
        $this->assertDatabaseHas('tags', [
            'id' => $historicalAlias->id,
            'is_active' => false,
            'merged_into_id' => $target->id,
        ]);
    }

    public function test_admin_can_pause_advertisement_without_revalidating_campaign_fields(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $advertisement = Advertisement::query()->create([
            'name' => 'HORIZONTE Header',
            'brand' => 'HORIZONTE',
            'placement' => 'header',
            'image_path' => 'banner-que-no-existe.webp',
            'target_url' => '/demo/anunciantes/universidad-horizonte',
            'starts_at' => '2026-07-01 00:00:00',
            'ends_at' => '2026-07-31 23:59:00',
            'is_active' => true,
        ]);

        $response = $this
            ->actingAs($admin)
            ->from(route('admin.advertisements.index'))
            ->patch(route('admin.advertisements.update', $advertisement), [
                'is_active' => false,
            ]);

        $response->assertRedirect(route('admin.advertisements.index', absolute: false));

        $advertisement->refresh();

        $this->assertFalse($advertisement->is_active);
        $this->assertSame('HORIZONTE Header', $advertisement->name);
        $this->assertSame('/demo/anunciantes/universidad-horizonte', $advertisement->target_url);
    }

    public function test_admin_cannot_delete_active_or_scheduled_advertisements(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $activeAdvertisement = Advertisement::query()->create([
            'name' => 'Campaña activa',
            'brand' => 'NEXO',
            'placement' => 'header',
            'image_path' => 'activa.webp',
            'target_url' => '/demo/anunciantes/banco-nexo',
            'starts_at' => '2026-07-01 00:00:00',
            'ends_at' => '2026-07-31 23:59:00',
            'is_active' => true,
        ]);

        $scheduledAdvertisement = Advertisement::query()->create([
            'name' => 'Campaña programada',
            'brand' => 'LUMEN',
            'placement' => 'article_sidebar_top',
            'image_path' => 'programada.webp',
            'target_url' => '/demo/anunciantes/lumen-air',
            'starts_at' => '2026-07-21 00:00:00',
            'ends_at' => '2026-07-31 23:59:00',
            'is_active' => true,
        ]);

        $this
            ->actingAs($admin)
            ->from(route('admin.advertisements.index'))
            ->delete(route('admin.advertisements.destroy', $activeAdvertisement))
            ->assertSessionHasErrors(['operation']);

        $this
            ->actingAs($admin)
            ->from(route('admin.advertisements.index'))
            ->delete(route('admin.advertisements.destroy', $scheduledAdvertisement))
            ->assertSessionHasErrors(['operation']);

        $this->assertDatabaseHas('advertisements', ['id' => $activeAdvertisement->id]);
        $this->assertDatabaseHas('advertisements', ['id' => $scheduledAdvertisement->id]);
    }

    public function test_admin_can_delete_paused_advertisement(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $advertisement = Advertisement::query()->create([
            'name' => 'Campaña pausada',
            'brand' => 'HORIZONTE',
            'placement' => 'header',
            'image_path' => 'pausada.webp',
            'target_url' => '/demo/anunciantes/universidad-horizonte',
            'starts_at' => '2026-07-01 00:00:00',
            'ends_at' => '2026-07-31 23:59:00',
            'is_active' => false,
        ]);

        $this
            ->actingAs($admin)
            ->from(route('admin.advertisements.index'))
            ->delete(route('admin.advertisements.destroy', $advertisement))
            ->assertRedirect(route('admin.advertisements.index', absolute: false));

        $this->assertDatabaseMissing('advertisements', ['id' => $advertisement->id]);
    }

    public function test_employee_with_historical_content_cannot_be_deleted(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $journalist = User::factory()->create(['role' => 'journalist']);
        $this->publishedArticle($journalist, 'draft');

        $response = $this->actingAs($admin)->delete(route('admin.users.destroy', $journalist));

        $response->assertSessionHasErrors(['user']);
        $this->assertDatabaseHas('users', ['id' => $journalist->id]);
    }

    public function test_admin_can_delete_reader_with_comments_without_deleting_the_comments(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $editor = User::factory()->create(['role' => 'editor']);
        $reader = User::factory()->create([
            'role' => 'reader',
            'name' => 'Cecilia Duarte',
            'email' => 'cecilia.duarte@example.test',
        ]);
        $article = $this->publishedArticle($editor, 'published');
        $body = 'Comentario válido que debe seguir visible aunque se elimine la cuenta.';

        $comment = Comment::query()->create([
            'article_id' => $article->id,
            'user_id' => $reader->id,
            'author_name' => $reader->name,
            'author_email' => $reader->email,
            'body' => $body,
            'status' => 'approved',
        ]);

        $response = $this
            ->actingAs($admin)
            ->from(route('admin.users.readers'))
            ->delete(route('admin.users.destroy', $reader));

        $response->assertRedirect(route('admin.users.readers', absolute: false));

        $this->assertDatabaseMissing('users', ['id' => $reader->id]);
        $this->assertDatabaseHas('comments', [
            'id' => $comment->id,
            'user_id' => null,
            'author_name' => 'Usuario eliminado',
            'author_email' => null,
            'body' => $body,
        ]);

        $comment = $comment->fresh();

        $publicPayload = (new CommentResource($comment))->resolve(request());
        $listPayload = (new AdminCommentListResource($comment))->resolve(request());
        $detailPayload = (new AdminCommentDetailResource($comment))->resolve(request());

        $this->assertSame('Usuario eliminado', $publicPayload['author_name']);
        $this->assertSame('Usuario eliminado', $listPayload['author']['name']);
        $this->assertNull($listPayload['author']['email']);
        $this->assertSame('Usuario eliminado', $detailPayload['author']['name']);
        $this->assertNull($detailPayload['author']['email']);
    }

    public function test_existing_article_can_keep_inactive_taxonomy_until_it_is_removed_explicitly(): void
    {
        $editor = User::factory()->create(['role' => 'editor']);
        $article = $this->publishedArticle($editor, 'draft');
        $tag = Tag::query()->create([
            'name' => 'Cobertura histórica',
            'slug' => 'cobertura-historica',
            'is_active' => true,
        ]);
        $article->tags()->attach($tag);
        $article->category()->update(['is_active' => false]);
        $tag->update(['is_active' => false]);

        $this->actingAs($editor)
            ->put(route('admin.articles.update', $article), [
                'category_id' => $article->category_id,
                'title' => $article->title,
                'subtitle' => $article->subtitle,
                'excerpt' => $article->excerpt,
                'body' => $article->body,
                'status' => 'draft',
                'tag_ids' => [$tag->id],
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('article_tag', [
            'article_id' => $article->id,
            'tag_id' => $tag->id,
        ]);
    }

    public function test_editor_status_change_resolves_open_feedback_in_the_same_operation(): void
    {
        $journalist = User::factory()->create(['role' => 'journalist']);
        $editor = User::factory()->create(['role' => 'editor']);
        $article = $this->publishedArticle($journalist, 'review');

        $this->actingAs($editor)->patch(
            route('admin.articles.request-changes', $article),
            ['message' => 'Agregar la fuente que respalda el dato principal.']
        )->assertRedirect();

        $article->refresh();

        $this->actingAs($editor)
            ->put(route('admin.articles.update', $article), [
                'category_id' => $article->category_id,
                'title' => $article->title,
                'subtitle' => $article->subtitle,
                'excerpt' => $article->excerpt,
                'body' => $article->body,
                'status' => 'published',
                'tag_ids' => [],
            ])
            ->assertRedirect();

        $this->assertDatabaseMissing('article_review_feedback', [
            'article_id' => $article->id,
            'resolved_at' => null,
        ]);
        $this->assertDatabaseHas('article_revisions', [
            'article_id' => $article->id,
            'status_from' => 'needs_changes',
            'status_to' => 'published',
        ]);
    }

    public function test_scheduled_article_requires_a_future_publication_date(): void
    {
        $editor = User::factory()->create(['role' => 'editor']);
        $category = $this->category();

        $this->actingAs($editor)
            ->from(route('admin.articles.create'))
            ->post(route('admin.articles.store'), [
                'category_id' => $category->id,
                'title' => 'Noticia programada sin fecha válida',
                'subtitle' => 'La programación necesita una fecha futura obligatoria.',
                'excerpt' => 'Esta prueba impide guardar una noticia programada sin indicar cuándo debe publicarse.',
                'body' => '<p>'.str_repeat('Contenido editorial preparado para una publicación futura. ', 8).'</p>',
                'status' => 'scheduled',
                'tag_ids' => [],
            ])
            ->assertSessionHasErrors(['scheduled_at']);
    }

    public function test_scheduled_command_publishes_due_article_and_records_revision(): void
    {
        $editor = User::factory()->create(['role' => 'editor']);
        $article = $this->publishedArticle($editor, 'scheduled');
        $article->update([
            'published_at' => null,
            'scheduled_at' => TramaClock::now()->subMinute(),
        ]);

        $this->artisan('articles:publish-scheduled')->assertSuccessful();

        $article->refresh();
        $this->assertSame('published', $article->status);
        $this->assertNotNull($article->published_at);
        $this->assertNull($article->scheduled_at);
        $this->assertDatabaseHas('article_revisions', [
            'article_id' => $article->id,
            'action' => 'published_automatically',
            'status_from' => 'scheduled',
            'status_to' => 'published',
        ]);
    }

    public function test_published_article_cannot_be_autosaved(): void
    {
        $editor = User::factory()->create(['role' => 'editor']);
        $article = $this->publishedArticle($editor, 'published');

        $this->actingAs($editor)
            ->patchJson(route('admin.articles.autosave', $article), [
                'category_id' => $article->category_id,
                'title' => $article->title,
                'subtitle' => $article->subtitle,
                'excerpt' => $article->excerpt,
                'body' => '<p>'.str_repeat('Contenido que no debe sobrescribir una publicación cerrada. ', 8).'</p>',
            ])
            ->assertForbidden();
    }

    public function test_editor_can_update_homepage_flags_without_unpublishing_article(): void
    {
        $editor = User::factory()->create(['role' => 'editor']);
        $article = $this->publishedArticle($editor, 'published');
        $publishedAt = $article->published_at?->toISOString();

        $response = $this->actingAs($editor)
            ->from(route('admin.articles.index'))
            ->patch(route('admin.articles.homepage.update', $article), [
                'is_breaking' => true,
                'is_featured' => true,
            ]);

        $response->assertRedirect(route('admin.articles.index', absolute: false));

        $article->refresh();

        $this->assertSame('published', $article->status);
        $this->assertTrue((bool) $article->is_breaking);
        $this->assertTrue((bool) $article->is_featured);
        $this->assertSame($publishedAt, $article->published_at?->toISOString());

        $revision = $article->revisions()->latest('id')->first();

        $this->assertSame('homepage_updated', $revision?->action);
        $this->assertContains('is_breaking', $revision?->changed_fields ?? []);
        $this->assertContains('is_featured', $revision?->changed_fields ?? []);
    }

    public function test_journalist_cannot_manage_homepage_flags(): void
    {
        $journalist = User::factory()->create(['role' => 'journalist']);
        $article = $this->publishedArticle($journalist, 'published');

        $this->actingAs($journalist)
            ->patch(route('admin.articles.homepage.update', $article), [
                'is_breaking' => true,
                'is_featured' => true,
            ])
            ->assertForbidden();

        $article->refresh();

        $this->assertFalse((bool) $article->is_breaking);
        $this->assertFalse((bool) $article->is_featured);
    }

    public function test_homepage_management_rejects_eighth_featured_article(): void
    {
        $editor = User::factory()->create(['role' => 'editor']);
        $category = $this->category();

        for ($index = 1; $index <= 7; $index++) {
            Article::query()->create([
                'author_id' => $editor->id,
                'category_id' => $category->id,
                'title' => 'Noticia destacada '.$index,
                'slug' => 'noticia-destacada-'.$index,
                'subtitle' => 'Bajada suficiente para la noticia destacada '.$index.'.',
                'excerpt' => 'Resumen suficiente para comprobar el límite de destacadas '.$index.'.',
                'body' => str_repeat('Contenido editorial de prueba. ', 12),
                'status' => 'published',
                'published_at' => TramaClock::now(),
                'is_featured' => true,
            ]);
        }

        $target = Article::query()->create([
            'author_id' => $editor->id,
            'category_id' => $category->id,
            'title' => 'Octava noticia candidata a portada',
            'slug' => 'octava-noticia-candidata-a-portada',
            'subtitle' => 'Bajada suficiente para la octava noticia candidata.',
            'excerpt' => 'Resumen suficiente para comprobar que no puede ocupar un octavo lugar.',
            'body' => str_repeat('Contenido editorial de prueba. ', 12),
            'status' => 'published',
            'published_at' => TramaClock::now(),
            'is_featured' => false,
        ]);

        $response = $this->actingAs($editor)
            ->from(route('admin.articles.index'))
            ->patch(route('admin.articles.homepage.update', $target), [
                'is_breaking' => false,
                'is_featured' => true,
            ]);

        $response->assertRedirect(route('admin.articles.index', absolute: false));
        $response->assertSessionHasErrors('is_featured');

        $this->assertFalse((bool) $target->fresh()->is_featured);
    }

    public function test_rich_body_removes_executable_html(): void
    {
        $editor = User::factory()->create(['role' => 'editor']);
        $category = $this->category();
        $safeText = str_repeat('Información verificada para una nota segura. ', 12);

        $response = $this->actingAs($editor)->post(route('admin.articles.store'), [
            'category_id' => $category->id,
            'title' => 'Noticia con contenido enriquecido seguro',
            'subtitle' => 'La sanitización elimina atributos ejecutables.',
            'excerpt' => 'Una prueba del filtro de HTML aplicado antes de guardar el cuerpo de la noticia.',
            'body' => '<div><p onclick="alert(1)">'.$safeText.'<script>alert(2)</script><a href="javascript:alert(3)">enlace</a><img src="javascript:alert(4)"></p></div>',
            'status' => 'draft',
            'tag_ids' => [],
        ]);

        $response->assertRedirect();
        $body = Article::query()->where('title', 'Noticia con contenido enriquecido seguro')->value('body');
        $this->assertStringNotContainsString('onclick', $body);
        $this->assertStringNotContainsString('<script', $body);
        $this->assertStringNotContainsString('javascript:', $body);
        $this->assertStringContainsString('<p>', $body);
    }

    private function publishedArticle(?User $author = null, string $status = 'published'): Article
    {
        $author ??= User::factory()->create(['role' => 'journalist']);
        $category = $this->category();

        return Article::query()->create([
            'author_id' => $author->id,
            'category_id' => $category->id,
            'title' => 'Congreso debate una nueva agenda publica',
            'slug' => 'congreso-debate-una-nueva-agenda-publica',
            'subtitle' => 'El debate legislativo suma nuevas voces.',
            'excerpt' => 'Una sintesis de prueba para la nota con contexto suficiente.',
            'body' => str_repeat('La cobertura suma contexto y verificacion editorial. ', 7),
            'status' => $status,
            'published_at' => $status === 'published' ? TramaClock::now() : null,
        ]);
    }

    private function category(): Category
    {
        return Category::query()->firstOrCreate(
            ['slug' => 'politica'],
            [
                'name' => 'Politica',
                'description' => 'Cobertura legislativa.',
                'accent_color' => '#D8323C',
                'is_active' => true,
            ]
        );
    }
}
