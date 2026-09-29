<?php

/* ============================================================================
 * REQUEST: ArticleIndexRequest.php
 * ============================================================================
 *
 * Valida y normaliza los parámetros utilizados para consultar el listado
 * editorial de noticias.
 *
 * Permite filtrar por texto, estado, portada, categoría, autor y rango de
 * fechas. También controla el ordenamiento, la dirección, la cantidad de
 * registros por página y el número de página solicitado.
 *
 * Los valores textuales se limpian antes de validarse. Además, opciones como
 * el estado, la columna de ordenamiento, la dirección y la cantidad de
 * registros por página quedan limitadas a valores permitidos.
 *
 * De esta manera, el controlador recibe parámetros consistentes y no procesa
 * directamente valores arbitrarios enviados mediante la URL.
 *
 * Ejemplo de parámetros aceptados:
 *
 *      [
 *          'search' => 'reforma digital',
 *          'status' => 'published',
 *          'homepage' => 'featured',
 *          'category_id' => 2,
 *          'author_id' => 4,      
 *          'date_from' => '2026-08-01',
 *          'date_to' => '2026-08-31',
 *          'sort' => 'updated_at',
 *          'direction' => 'desc',
 *          'per_page' => 25,
 *          'page' => 3,
 *      ]
 * ============================================================================ */

namespace App\Http\Requests\Editorial;

use App\Support\TramaClock;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ArticleIndexRequest extends FormRequest
{
    /**
     * El acceso al panel editorial ya está protegido mediante middleware.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Reglas aplicadas a los filtros del listado editorial.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /*
         * TRAMA utiliza la fecha editorial configurada para mantener estable el
         * contexto temporal del portfolio. Ningún filtro puede superar ese día.
         */
        $referenceDate = TramaClock::referenceDate()->toDateString();

        return [
            /*
             * MariaDB fue configurado para indexar palabras de tres caracteres
             * o más, por lo que no se aceptan búsquedas más cortas.
             */
            'search' => [
                'nullable',
                'string',
                'min:3',
                'max:120',
            ],

            /*
             * El estado queda limitado a los valores utilizados por el flujo
             * editorial. Esto impide recibir estados inexistentes desde la URL.
             */
            'status' => [
                'nullable',
                'string',
                Rule::in([
                    'draft',
                    'review',
                    'needs_changes',
                    'scheduled',
                    'published',
                    'archived',
                ]),
            ],

            /*
             * Filtra según el tratamiento editorial de portada.
             *
             * featured y breaking se limitan luego a noticias publicadas o
             * programadas, porque son las que ocupan ahora o luego la portada.
             */
            'homepage' => [
                'nullable',
                'string',
                Rule::in([
                    'featured',
                    'breaking',
                    'normal',
                ]),
            ],

            /*
             * Los identificadores deben corresponder a registros existentes.
             * De esta manera no se ejecutan filtros contra relaciones inválidas.
             */
            'category_id' => [
                'nullable',
                'integer',
                'exists:categories,id',
            ],

            /*
             * Indica que el listado debe mostrar únicamente noticias que todavía
             * no tienen una categoría seleccionada.
             */
            'without_category' => [
                'nullable',
                'boolean',
            ],

            /*
            * Nombre completo o fragmento del nombre utilizado para buscar autores.
            *
            * Este parámetro proviene de la caja de texto del listado editorial.
            */
            'author' => [
                'nullable',
                'string',
                'max:80',
            ],

            /*
             * Las fechas utilizan un formato fijo para que el servicio pueda
             * convertirlas en el inicio y final del día correspondiente.
             */
            'date_from' => [
                'nullable',
                'date_format:Y-m-d',
                'before_or_equal:'.$referenceDate,
            ],

            /*
             * Fecha final del rango aplicado sobre la última edición de las noticias.
             *
             * Debe utilizar el formato YYYY-MM-DD y no puede ser anterior a date_from.
             */
            'date_to' => [
                'nullable',
                'date_format:Y-m-d',

                /*
                 * La comparación se agrega solamente cuando existe Desde.
                 * Así también es válido filtrar únicamente hasta una fecha.
                 */
                ...($this->filled('date_from')
                    ? ['after_or_equal:date_from']
                    : []),

                'before_or_equal:'.$referenceDate,
            ],

            /*
             * Solo pueden utilizarse columnas previstas por el servicio.
             * Esto evita ordenar directamente mediante nombres arbitrarios.
             */
            'sort' => [
                'nullable',
                'string',
                Rule::in([
                    'updated_at',
                    'created_at',
                    'published_at',
                    'scheduled_at',
                    'title',
                    'status',
                    'homepage',
                    'category',
                    'author',
                    'views',
                ]),
            ],

            /*
             * La dirección del ordenamiento queda limitada a ascendente o
             * descendente.
             */
            'direction' => [
                'nullable',
                'string',
                Rule::in([
                    'asc',
                    'desc',
                ]),
            ],

            /*
             * La cantidad de filas por página se limita a opciones controladas
             * para evitar consultas excesivamente grandes.
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

            /*
            * La página debe ser un número entero positivo.
            *
            * Ejemplo:
            *
            * /admin/articles?page=3
            */
            'page' => [
                'nullable',
                'integer',
                'min:1',
            ],
        ];
    }

    /**
     * Limpia parámetros textuales antes de ejecutar la validación.
     */
    protected function prepareForValidation(): void
    {
        /*
         * El buscador envía el texto mediante el parámetro search.
         *
         * Ejemplo:
         *
         * /admin/articles?search=congreso
         *
         * Se eliminan los espacios al principio y al final. Si después de esa
         * limpieza el texto queda vacío, se convierte en null para que el
         * servicio no agregue ninguna condición de búsqueda.
         */
        $search = trim((string) $this->input('search'));

        /*
         * El estado y la dirección se convierten en minúsculas para aceptar
         * valores escritos con distintas combinaciones de mayúsculas.
         *
         * Ejemplo:
         *
         * PUBLISHED → published
         * DESC      → desc
         */
        $this->merge([
            'search' => $search !== ''
                ? $search
                : null,

            'status' => $this->normalizeLowercaseValue('status'),

            'homepage' => $this->normalizeLowercaseValue('homepage'),

            'direction' => $this->normalizeLowercaseValue('direction'),
        ]);
    }

    /**
     * Devuelve un parámetro en minúsculas o null cuando está vacío.
     */
    private function normalizeLowercaseValue(string $key): ?string
    {
        /*
         * Se obtiene el parámetro como texto y se eliminan los espacios que
         * pudiera contener al principio o al final.
         */
        $value = trim((string) $this->input($key));

        /*
         * Cuando existe contenido se normaliza en minúsculas. Si el valor está
         * vacío, se devuelve null para que la regla nullable lo acepte.
         */
        return $value !== ''
            ? strtolower($value)
            : null;
    }
}