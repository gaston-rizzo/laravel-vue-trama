<?php

/* ============================================================================
 * REQUEST: AutosaveArticleRequest.php
 * ============================================================================
 *
 * Valida cambios parciales enviados por el guardado automático.
 *
 * Solo admite campos de contenido. Estados, publicación y banderas de portada
 * permanecen bajo el guardado explícito y el flujo editorial normal.
 * ============================================================================ */

namespace App\Http\Requests\Admin;

use App\Models\Article;
use App\Services\HtmlSanitizer;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

class AutosaveArticleRequest extends FormRequest
{
    /**
     * Autoriza el guardado automático según
     * la autoría, el rol y el estado de la noticia.
     */
    public function authorize(): bool
    {
        // Obtiene la noticia asociada al parámetro {article} de la ruta.
        $article = $this->route('article');
        // Obtiene el usuario autenticado que intenta realizar el autoguardado.
        $user = $this->user();

        // Requiere una noticia existente y un usuario autorizado
        // para trabajar dentro del módulo de noticias.
        if (
            ! $article instanceof Article
            || ! $user?->canAccessArticles()
        ) {
            return false;
        }

        // El autoguardado funciona únicamente en estados
        // que todavía admiten modificaciones parciales.
        if (
            ! in_array(
                $article->status,
                Article::JOURNALIST_EDITABLE_STATUSES,
                true
            )
        ) {
            return false;
        }

        // El autor puede autoguardar su propia noticia.
        if ($article->author_id === $user->id) {
            return true;
        }

        // El editor puede autoguardar una noticia ajena
        // solo después de que haya dejado de ser borrador.
        return $user->canReviewArticles()
            && $article->status !== 'draft';
    }

    /**
     * Sanitiza el cuerpo antes de aplicar las reglas.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            // Campos de una línea: se compactan espacios y saltos.
            'title' => $this->normalizeLine($this->input('title')),
            'subtitle' => $this->normalizeLine($this->input('subtitle')),
            'excerpt' => $this->normalizeLine($this->input('excerpt')),
            // El cuerpo HTML se limpia antes de medir caracteres.
            'body' => app(HtmlSanitizer::class)->sanitize((string) $this->input('body')),
        ]);
    }

    /**
     * Reglas de contenido parcial para el autosave.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [

            // El autoguardado solo se ejecuta cuando los campos ya tienen
            // una cantidad mínima de contenido útil.
            'title' => [
                'required',
                'string',
                'min:20',
                'max:90',
            ],

            'subtitle' => [
                'required',
                'string',
                'min:50',
                'max:140',
            ],

            'excerpt' => [
                'required',
                'string',
                'min:50',
                'max:200',
            ],

            'body' => [
                'required',
                'string',

                // El HTML ya fue sanitizado en prepareForValidation().
                // Acá se cuentan únicamente los caracteres que verá el lector.
                function (string $attribute, mixed $value, Closure $fail): void {
                    $length = app(HtmlSanitizer::class)
                        ->textLength((string) $value);

                    // El autoguardado acepta el cuerpo únicamente dentro de estos límites.
                    if ($length < 300 || $length > 10000) {
                        $fail(
                            'El cuerpo debe tener entre 300 y 10000 caracteres visibles.'
                        );
                    }
                },
            ],
            
            // category_id debe seguir apuntando a una categoría existente.
            'category_id' => ['required', 'exists:categories,id'],
        ];
    }

    /**
     * Compacta espacios de campos que deben ocupar una sola línea.
     */
    private function normalizeLine(mixed $value): ?string
    {
        if (! is_string($value)) {
            // null deja que la regla required muestre el mensaje correspondiente.
            return null;
        }

        // Convierte saltos y espacios repetidos en un único espacio.
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');

        // Una línea vacía se trata como campo ausente.
        return $value === '' ? null : $value;
    }
}
