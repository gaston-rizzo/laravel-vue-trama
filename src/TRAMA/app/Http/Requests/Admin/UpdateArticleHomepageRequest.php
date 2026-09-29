<?php

/* ============================================================================
 * REQUEST: UpdateArticleHomepageRequest.php
 * ============================================================================
 *
 * Valida los cambios editoriales aplicados únicamente a la portada.
 *
 * Esta operación no modifica el contenido de la noticia ni su estado de
 * publicación. Solo permite que un editor active o desactive las marcas
 * Urgente y Destacada de una noticia publicada o programada.
 * ============================================================================ */

namespace App\Http\Requests\Admin;

use App\Models\Article;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateArticleHomepageRequest extends FormRequest
{
    /** Máximo de noticias destacadas que pueden reservar lugar en portada. */
    private const FRONT_PAGE_FEATURED_LIMIT = 7;

    /** Estados que pueden ocupar ahora o luego un lugar en la portada. */
    private const FRONT_PAGE_VISIBLE_STATUSES = ['published', 'scheduled'];

    /**
     * Autoriza a quienes gestionan la portada sobre noticias publicadas o
     * programadas, sin depender de quién haya escrito la noticia.
     *
     * La acción es global porque solamente modifica las marcas Urgente y
     * Destacada; no habilita la edición del contenido de otros autores.
     */
    public function authorize(): bool
    {
        $user = $this->user();
        $article = $this->route('article');

        return (bool) (
            $user?->canReviewArticles()
            && $article instanceof Article
            && in_array(
                $article->status,
                self::FRONT_PAGE_VISIBLE_STATUSES,
                true,
            )
        );
    }

    /**
     * Reglas exclusivas de las dos marcas editoriales de portada.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'is_breaking' => ['required', 'boolean'],
            'is_featured' => ['required', 'boolean'],
        ];
    }

    /**
     * Comprueba el límite de siete destacadas antes de ejecutar la operación.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (! $this->boolean('is_featured')) {
                return;
            }

            $article = $this->route('article');

            $featuredCount = Article::query()
                ->where('is_featured', true)
                ->whereIn('status', self::FRONT_PAGE_VISIBLE_STATUSES)
                ->when(
                    $article instanceof Article,
                    fn ($query) => $query->whereKeyNot($article->id),
                )
                ->count();

            if ($featuredCount >= self::FRONT_PAGE_FEATURED_LIMIT) {
                $validator->errors()->add(
                    'is_featured',
                    'La portada ya tiene 7 noticias destacadas. Quitá una antes de marcar otra.'
                );
            }
        });
    }
}
