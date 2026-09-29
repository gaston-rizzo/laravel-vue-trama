<?php

/* ============================================================================
 * REQUEST: CommentIndexRequest.php
 * ============================================================================
 *
 * Valida y normaliza los parámetros utilizados por la grilla de moderación
 * de comentarios del panel editorial.
 *
 * La pantalla permite filtrar los comentarios según su estado de moderación,
 * tipo de participación, autor, noticia, rango de fechas y contenido del
 * comentario. También controla el ordenamiento, la dirección, la cantidad de
 * filas por página y la página solicitada.
 *
 * La validación evita que nombres arbitrarios de columnas, cantidades enormes
 * por página o valores de filtros inexistentes lleguen al servicio de consulta.
 *
 * Ejemplo de parámetros aceptados:
 *
 *      [
 *          'queue' => 'attention',
 *          'search' => 'insulto',
 *          'type' => 'reply',
 *          'author' => 'test',
 *          'article' => 'agenda climática',
 *          'date_from' => '2026-07-01',
 *          'date_to' => '2026-07-05',
 *          'sort' => 'reports',
 *          'direction' => 'desc',
 *          'per_page' => 25,
 *          'page' => 2,
 *      ]
 * ============================================================================ */

namespace App\Http\Requests\Editorial;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CommentIndexRequest extends FormRequest
{
    /**
     * El acceso al módulo ya está protegido por EnsureCommentModerationAccess.
     *
     * El controlador vuelve a comprobar canModerateComments() antes de ejecutar
     * la consulta para conservar una segunda barrera de autorización.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Reglas aplicadas a los filtros de la grilla de moderación.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /*
             * queue indica qué grupo de comentarios debe mostrarse en la grilla
             * según su situación dentro de la moderación.
             *
             * "attention" reúne comentarios pendientes y comentarios que poseen
             * al menos un reporte abierto. Los demás valores permiten mostrar
             * pendientes, reportados, rechazados editoriales, rechazos
             * automáticos o aprobados por separado.
             *
             * Cuando no se envía este parámetro se muestran todos los comentarios.
             *
             * "attention" y "reported" no corresponden al campo status de comments:
             * son grupos calculados por CommentIndexQueryService.
             */
            'queue' => [
                'nullable',
                'string',
                Rule::in([
                    'attention',
                    'pending',
                    'reported',
                    'rejected',
                    'automatic_rejected',
                    'approved',
                ]),
            ],

            /*
             * Búsqueda dentro del contenido del comentario.
             * Se exigen tres caracteres para evitar consultas demasiado amplias.
             */
            'search' => [
                'nullable',
                'string',
                'min:3',
                'max:120',
            ],

            /*
             * Permite separar comentarios principales de respuestas.
             */
            'type' => [
                'nullable',
                'string',
                Rule::in([
                    'comment',
                    'reply',
                ]),
            ],

            /*
             * Filtro específico por nombre o correo del autor.
             */
            'author' => [
                'nullable',
                'string',
                'max:100',
            ],

            /*
             * Filtro específico por título de la noticia.
             */
            'article' => [
                'nullable',
                'string',
                'max:140',
            ],

            /*
             * Rango de creación del comentario.
             */
            'date_from' => [
                'nullable',
                'date_format:Y-m-d',
            ],

            'date_to' => [
                'nullable',
                'date_format:Y-m-d',

                /*
                 * La comparación se agrega solamente cuando existe Desde.
                 */
                ...($this->filled('date_from')
                    ? ['after_or_equal:date_from']
                    : []),
            ],

            /*
             * Únicamente se admiten las columnas que CommentIndexQueryService
             * sabe ordenar de manera segura.
             */
            'sort' => [
                'nullable',
                'string',
                Rule::in([
                    'created_at',
                    'status',
                    'author',
                    'article',
                    'type',
                    'reports',
                ]),
            ],

            'direction' => [
                'nullable',
                'string',
                Rule::in([
                    'asc',
                    'desc',
                ]),
            ],

            /*
             * Limita la cantidad de comentarios mostrados por página a los
             * tamaños permitidos por la grilla: 25, 50 o 100 filas.
             */
            'per_page' => [
                'nullable',
                'integer',
                Rule::in([
                    25,
                    50,
                    100,
                ]),
            ],

            'page' => [
                'nullable',
                'integer',
                'min:1',
            ],
        ];
    }

    /**
     * Normaliza los valores textuales antes de ejecutar la validación.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'queue' => $this->normalizeLowercaseValue('queue'),
            'type' => $this->normalizeLowercaseValue('type'),
            'direction' => $this->normalizeLowercaseValue('direction'),
            'search' => $this->normalizeTextValue('search'),
            'author' => $this->normalizeTextValue('author'),
            'article' => $this->normalizeTextValue('article'),
        ]);
    }

    /**
     * Devuelve un texto limpio o null cuando quedó vacío.
     */
    private function normalizeTextValue(string $key): ?string
    {
        $value = trim((string) $this->input($key));

        return $value !== ''
            ? $value
            : null;
    }

    /**
     * Devuelve un texto limpio en minúsculas o null cuando quedó vacío.
     */
    private function normalizeLowercaseValue(string $key): ?string
    {
        $value = $this->normalizeTextValue($key);

        return $value !== null
            ? strtolower($value)
            : null;
    }
}
