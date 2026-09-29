<?php

/* ============================================================================
 * REQUEST: ArticleRequest.php
 * ============================================================================
 *
 * Valida la creación y actualización de noticias en TRAMA.
 *
 * Limpia textos, sanitiza el HTML del editor enriquecido y limita estados
 * según el rol. Los periodistas solo guardan borradores o envían a revisión;
 * los editores controlan revisión, publicación, programación y archivo.
 * ============================================================================ */

namespace App\Http\Requests\Admin;

use App\Support\TramaClock;

use App\Models\Article;
use App\Services\HtmlSanitizer;

use Carbon\CarbonImmutable;

use Closure;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Illuminate\Validation\Rules\Exists;

class ArticleRequest extends FormRequest
{
    /**
     * Estados que un periodista puede seleccionar desde el formulario.
     *
     * @var list<string>
     */
    private const JOURNALIST_TARGET_STATUSES = ['draft', 'review'];

    /**
     * Máximo de noticias destacadas que pueden ocupar portada.
     *
     * La portada usa 1 noticia principal y 6 bloques destacados; por eso no
     * tiene sentido permitir más destacadas visibles al mismo tiempo.
     */
    private const FRONT_PAGE_FEATURED_LIMIT = 7;

    /**
     * Estados que pueden aparecer ahora o luego en la portada pública.
     *
     * published ya se ve en el sitio. scheduled todavía no se ve, pero cuando
     * llegue su fecha pasará a publicada, así que también reserva lugar.
     *
     * @var list<string>
     */
    private const FRONT_PAGE_VISIBLE_STATUSES = ['published', 'scheduled'];

    /**
     * Autoriza la creación o edición de noticias según
     * el rol, la autoría y el estado editorial.
     */
    public function authorize(): bool
    {
        $user = $this->user();

        // En edición, Laravel obtiene la noticia desde la ruta.
        // En creación, este valor no contiene un modelo Article.
        $article = $this->route('article');

        // Solo periodistas y editores activos pueden
        // utilizar formularios de noticias.
        if (! $user?->canAccessArticles()) {
            return false;
        }

        // Periodistas y editores pueden crear noticias propias.
        if (! $article instanceof Article) {
            return true;
        }

        if (in_array($article->status, ['published', 'archived'], true)) {
            // Publicada y archivada no aceptan edición de contenido desde PUT.
            // Archivar una publicada se hace por la acción específica del panel.
            return false;
        }

        // El editor puede modificar cualquier noticia propia.
        //
        // El periodista solo puede modificar una noticia propia
        // mientras esté en un estado habilitado para edición.
        if ($article->author_id === $user->id) {
            return $user->canReviewArticles()
                || in_array(
                    $article->status,
                    Article::JOURNALIST_EDITABLE_STATUSES,
                    true
                );
        }

        // El editor puede modificar noticias ajenas únicamente
        // después de que hayan salido del estado borrador.
        return $user->canReviewArticles()
            && $article->status !== 'draft';
    }

    /**
     * Prepara los campos del formulario antes de validar y guardar.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            // Campos de una línea: se compactan espacios y saltos.
            'title' => $this->normalizeSingleLineText($this->input('title')),
            'subtitle' => $this->normalizeSingleLineText($this->input('subtitle')),
            'excerpt' => $this->normalizeSingleLineText($this->input('excerpt')),
            // El cuerpo viene del editor enriquecido y debe limpiarse antes de validar.
            'body' => app(HtmlSanitizer::class)->sanitize((string) $this->input('body')),
        ]);
    }

    /**
     * Reglas de contenido, portada y flujo editorial.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $requiresCompleteArticle = $this->requiresCompleteArticle();

        // En borrador solo se controlan los máximos.
        // Los mínimos se exigen cuando la noticia queda lista
        // para revisión, programación o publicación.
        $titleRules = $requiresCompleteArticle
            ? ['required', 'string', 'min:20', 'max:90']
            : ['nullable', 'string', 'max:90'];

        $subtitleRules = $requiresCompleteArticle
            ? ['required', 'string', 'min:50', 'max:140']
            : ['nullable', 'string', 'max:140'];

        $excerptRules = $requiresCompleteArticle
            ? ['required', 'string', 'min:50', 'max:200']
            : ['nullable', 'string', 'max:200'];

        return [
            // En borrador la categoría puede faltar.
            // En los estados completos pasa a ser obligatoria.
            'category_id' => $requiresCompleteArticle
                ? ['required', $this->categoryExistsRule()]
                : ['nullable', $this->categoryExistsRule()],

            'title' => $titleRules,
            'subtitle' => $subtitleRules,
            'excerpt' => $excerptRules,

            'body' => [
                'bail',
                $requiresCompleteArticle ? 'required' : 'nullable',
                'string',

                function (
                    string $attribute,
                    mixed $value,
                    Closure $fail
                ) use ($requiresCompleteArticle): void {
                    // Cuenta únicamente el texto visible del editor.
                    $length = app(HtmlSanitizer::class)
                        ->textLength((string) $value);

                    // El mínimo se exige solamente cuando
                    // la noticia debe estar completa.
                    if ($requiresCompleteArticle && $length < 300) {
                        $fail(
                            'El cuerpo de la noticia debe tener al menos 300 caracteres visibles.'
                        );
                    }

                    // El máximo se controla en todos los estados,
                    // incluso cuando se guarda como borrador.
                    if ($length > 10000) {
                        $fail(
                            'El cuerpo de la noticia no puede superar los 10000 caracteres visibles.'
                        );
                    }
                },
            ],

            // El archivo sigue siendo opcional dentro de estas reglas.
            // Más abajo se comprueba si la noticia ya tiene una portada.
            'cover_image_file' => [
                'nullable',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:5120',
            ],

            'remove_cover_image' => ['sometimes', 'boolean'],

            // Estado permitido según el rol del usuario.
            'status' => [
                'required',
                Rule::in($this->allowedStatuses()),
            ],

            'is_breaking' => ['sometimes', 'boolean'],
            'is_featured' => ['sometimes', 'boolean'],            

            'published_at' => ['nullable', 'date'],

            'scheduled_at' => [
                'nullable',
                'required_if:status,scheduled',
                'date',
                function (string $attribute, mixed $value, Closure $fail): void {
                    // La fecha programada solo se controla cuando el botón elegido
                    // intenta dejar la noticia en estado Programada.
                    if ((string) $this->input('status') !== 'scheduled' || blank($value)) {
                        return;
                    }

                    try {
                        // Convierte el valor enviado por datetime-local a una fecha
                        // comparable por Laravel.
                        $scheduledAt = CarbonImmutable::parse((string) $value);
                    } catch (\Throwable) {
                        // La regla "date" ya muestra el mensaje correspondiente.
                        return;
                    }

                    $minimum = $this->minimumScheduleDate();
                    $maximum = $this->maximumScheduleDate();

                    // No se permite programar antes del momento actual del portal.
                    if ($scheduledAt->lt($minimum)) {
                        $fail('La fecha de programación debe ser posterior al momento actual de TRAMA.');
                    }

                    // El calendario editorial acepta hasta 30 días hacia adelante.
                    if ($scheduledAt->gt($maximum)) {
                        $fail('La fecha de programación no puede superar los 30 días desde la fecha actual de TRAMA.');
                    }
                },
            ],

            // En borrador las etiquetas son opcionales.
            // Para revisión, programación o publicación se exige al menos una.
            'tag_ids' => $requiresCompleteArticle
                ? ['required', 'array', 'min:1', 'max:5']
                : ['sometimes', 'array', 'max:5'],

            'tag_ids.*' => [
                'integer',
                $this->tagExistsRule(),
            ],
        ];
    }

    /**
     * Agrega comprobaciones que dependen del estado completo
     * del formulario o de una noticia ya guardada.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            // No permite crear un borrador completamente vacío.
            $this->validateDraftHasContent($validator);

            // Exige portada cuando la noticia entra
            // al circuito editorial.
            $this->validateRequiredCoverImage($validator);

            // Conserva el límite de noticias destacadas.
            $this->validateFeaturedLimit($validator);
        });
    }

    /**
     * Indica cuándo deben aplicarse todas
     * las condiciones editoriales.
     */
    private function requiresCompleteArticle(): bool
    {
        return in_array(
            (string) $this->input('status'),
            ['review', 'scheduled', 'published'],
            true,
        );
    }

    /**
     * Evita crear un borrador completamente vacío.
     *
     * Alcanza con que exista contenido en el título,
     * la bajada, el resumen o el cuerpo.
     */
    private function validateDraftHasContent(
        Validator $validator
    ): void {
        // Esta regla solamente corresponde al estado borrador.
        if ($this->input('status') !== 'draft') {
            return;
        }

        // Comprueba los tres campos de texto simple
        // y el texto visible del editor enriquecido.
        $hasContent =
            filled($this->input('title'))
            || filled($this->input('subtitle'))
            || filled($this->input('excerpt'))
            || app(HtmlSanitizer::class)
                ->textLength((string) $this->input('body')) > 0;

        if (! $hasContent) {
            $validator->errors()->add(
                'draft_content',
                'Escribí al menos algún contenido antes de guardar el borrador.'
            );
        }
    }

    /**
     * Exige una portada cuando la noticia queda lista
     * para revisión, programación o publicación.
     */
    private function validateRequiredCoverImage(
        Validator $validator
    ): void {
        // En borrador la imagen sigue siendo opcional.
        if (! $this->requiresCompleteArticle()) {
            return;
        }

        $article = $this->route('article');

        // Comprueba si el usuario cargó una imagen nueva.
        $hasNewCover = $this->hasFile('cover_image_file');

        // Al editar también sirve la imagen anterior, pero únicamente si la
        // ruta guardada continúa apuntando a un archivo físico real.
        $keepsExistingCover =
            $article instanceof Article
            && ! $this->boolean('remove_cover_image')
            && $article->coverImageExists();

        if (! $hasNewCover && ! $keepsExistingCover) {
            /*
             * Si la base todavía conserva una ruta pero el archivo desapareció,
             * se informa el problema real en lugar de tratarlo como una noticia
             * que nunca tuvo portada. Una imagen nueva repara la noticia.
             */
            if (
                $article instanceof Article
                && $article->hasCoverImage()
                && ! $this->boolean('remove_cover_image')
                && ! $article->coverImageExists()
            ) {
                $validator->errors()->add(
                    'cover_image_file',
                    'El archivo actual de la portada no existe. Subí nuevamente la imagen para reparar la noticia.'
                );

                return;
            }

            $validator->errors()->add(
                'cover_image_file',
                'Seleccioná una imagen de portada antes de enviar la noticia a revisión.'
            );
        }
    }

    /**
     * Mensajes destinados al personal editorial.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [

            // Título.
            'title.required' => 'Ingresá el título de la noticia.',
            'title.min' => 'El título debe tener al menos 20 caracteres.',
            'title.max' => 'El título no puede superar los 90 caracteres.',

            // Categoría.
            'category_id.required' => 'Seleccioná una categoría.',

            // Bajada
            'subtitle.required' => 'Ingresá la bajada.',
            'subtitle.min' => 'La bajada debe tener al menos 50 caracteres.',
            'subtitle.max' => 'La bajada no puede superar los 140 caracteres.',

            // Resumen.
            'excerpt.required' => 'Ingresá el resumen de la noticia.',
            'excerpt.min' => 'El resumen debe tener al menos 50 caracteres.',
            'excerpt.max' => 'El resumen no puede superar los 200 caracteres.',

            // Cuerpo.
            'body.required' => 'Escribí el cuerpo de la noticia.',

            // Etiquetas.
            'tag_ids.required' => 'Seleccioná al menos una etiqueta.',
            'tag_ids.array' => 'Las etiquetas deben enviarse como una lista válida.',
            'tag_ids.min' => 'Seleccioná al menos una etiqueta.',
            'tag_ids.max' => 'Podés seleccionar como máximo 5 etiquetas por noticia.',
            'tag_ids.*.integer' => 'Una de las etiquetas seleccionadas no es válida.',
            'tag_ids.*.exists' => 'Una de las etiquetas seleccionadas no está disponible.',

            // Imagen de portada.
            'cover_image_file.image' => 'El archivo debe ser una imagen válida.',
            'cover_image_file.mimes' => 'La imagen debe ser JPG, JPEG, PNG o WEBP.',
            'cover_image_file.max' => 'La imagen no puede superar los 5 MB.',

            // Programación y estado.
            'scheduled_at.required_if' => 'Elegí la fecha y hora de publicación.',
            'scheduled_at.date' => 'La fecha de programación no es válida.',
            'status.in' => 'Ese estado no está disponible para tu rol.',
        ];
    }

    /**
     * Impide que el panel editorial tenga más de siete noticias destacadas visibles.
     */
    private function validateFeaturedLimit(Validator $validator): void
    {
        // Solo el editor puede marcar noticias como destacadas.
        // Para cualquier otro rol no es necesario comprobar el límite de portada.
        if (! $this->user()?->canReviewArticles()) {
            return;
        }

        // Si el checkbox no está marcado, la noticia no reserva lugar en portada.
        if (! $this->boolean('is_featured')) {
            return;
        }

        // Solo se limita cuando la noticia queda publicada o programada.
        // Un borrador destacado no aparece todavía y se validará al publicarlo.
        if (! in_array((string) $this->input('status'), self::FRONT_PAGE_VISIBLE_STATUSES, true)) {
            return;
        }

        $article = $this->route('article');
        $featuredCount = Article::query()
            // Cuenta solo noticias destacadas para portada.
            ->where('is_featured', true)
            // Publicadas y programadas son las que pueden ocupar portada.
            ->whereIn('status', self::FRONT_PAGE_VISIBLE_STATUSES)
            // Al editar una noticia, no se cuenta a sí misma.
            ->when($article instanceof Article, fn ($query) => $query->whereKeyNot($article->id))
            ->count();

        if ($featuredCount >= self::FRONT_PAGE_FEATURED_LIMIT) {
            $validator->errors()->add(
                'is_featured',
                'La portada ya tiene 7 noticias destacadas. Quitá una antes de marcar otra.'
            );
        }
    }

    /**
     * Devuelve los estados editoriales que el usuario puede enviar
     * mediante el formulario de una noticia.
     *
     * Ejemplos:
     * - Periodista: draft, review.
     * - Editor: draft, scheduled, published y otros estados
     *   permitidos según la situación actual de la noticia.
     *
     * @return list<string>
     */
    private function allowedStatuses(): array
    {
        $article = $this->route('article');

        // Comprueba si el usuario puede tomar decisiones editoriales.
        //
        // Solo el editor puede seleccionar estados como programada,
        // publicada o archivada.
        if ($this->user()?->canReviewArticles()) {
            /*
            * Una noticia nueva creada por un editor solamente puede comenzar
            * como borrador, programada o publicada.
            */
            if (! $article instanceof Article) {
                return [
                    'draft',
                    'scheduled',
                    'published',
                ];
            }

            if ($article->status === 'scheduled') {
                return [
                    'scheduled',
                    'published',
                ];
            }

            if ($article->status === 'review') {
                return [
                    'scheduled',
                    'published',
                ];
            }

            if ($article->status === 'draft') {
                return [
                    'draft',
                    'scheduled',
                    'published',
                ];
            }

            if ($article->status === 'needs_changes') {
                return ['needs_changes'];
            }

            return [];
        }

        if (
            $article instanceof Article
            && $article->status === 'needs_changes'
        ) {
            /*
            * El periodista puede guardar avances manteniendo needs_changes
            * o reenviar la corrección completa cambiándola a review.
            *
            * El estado review activa todas las reglas obligatorias del request.
            */
            return [
                'needs_changes',
                'review',
            ];
        }

        return self::JOURNALIST_TARGET_STATUSES;
    }

    /**
     * Devuelve el momento mínimo permitido para programar una noticia.
     *
     * Se usa el reloj editorial completo de TRAMA, no la hora real. Por ejemplo,
     * si la sesión está en 19/07/2026 16:42, la validación parte de 16:42 aunque
     * en la PC sean las 10:15 del día siguiente.
     */
    private function minimumScheduleDate(): CarbonImmutable
    {
        return TramaClock::now()->setSecond(0);
    }

    /**
     * Devuelve la fecha máxima permitida para programar.
     *
     * El límite práctico de TRAMA es de 30 días hacia adelante para evitar que
     * queden noticias olvidadas en cola durante meses.
     */
    private function maximumScheduleDate(): CarbonImmutable
    {
        return TramaClock::referenceDate()
            ->addDays(30)
            ->endOfDay();
    }

    /**
     * Admite categorías activas y conserva la categoría histórica al editar.
     *
     * Una categoría desactivada no puede asignarse a una noticia nueva, pero
     * debe seguir disponible en una noticia que ya la utiliza para evitar que
     * un simple guardado obligue a modificar su clasificación histórica.
     */
    private function categoryExistsRule(): Exists
    {
        $article = $this->route('article');

        return Rule::exists('categories', 'id')->where(function ($query) use ($article): void {
            // Categorías activas siempre son válidas.
            $query->where('is_active', true);

            if (
                $article instanceof Article
                && $article->category_id !== null
            ) {
                // La categoría actual se acepta aunque esté inactiva.
                $query->orWhere('id', $article->category_id);
            }
        });
    }

    /**
     * Admite etiquetas activas y conserva las ya vinculadas al editar.
     *
     * Las etiquetas inactivas no pueden agregarse a otras noticias. Sin embargo,
     * una noticia que ya las utiliza puede conservarlas hasta que se decida
     * retirarlas expresamente desde el formulario.
     */
    private function tagExistsRule(): Exists
    {
        $article = $this->route('article');
        $currentTagIds = $article instanceof Article
            // Etiquetas ya vinculadas a esta noticia.
            ? $article->tags()->pluck('tags.id')->map(fn ($id): int => (int) $id)->all()
            : [];

        return Rule::exists('tags', 'id')->where(function ($query) use ($currentTagIds): void {
            $query->where(function ($allowed) use ($currentTagIds): void {
                $allowed->where(function ($active): void {
                    // Etiquetas activas y no fusionadas pueden asignarse libremente.
                    $active->where('is_active', true)->whereNull('merged_into_id');
                });

                if ($currentTagIds !== []) {
                    // Etiquetas históricas de la noticia pueden conservarse al editar.
                    $allowed->orWhereIn('id', $currentTagIds);
                }
            });
        });
    }

    /**
     * Compacta espacios y saltos de campos que se muestran en una sola línea.
     */
    private function normalizeSingleLineText(mixed $value): ?string
    {
        if (! is_string($value)) {
            // Si el campo no llegó como string, se deja null para que falle required.
            return null;
        }

        // Reemplaza cualquier secuencia de espacios/saltos por un solo espacio.
        $normalized = trim(preg_replace('/\s+/u', ' ', $value) ?? '');

        // Cadena vacía se normaliza a null para activar mensajes required.
        return $normalized === '' ? null : $normalized;
    }
}
