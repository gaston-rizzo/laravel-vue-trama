<?php

/* ============================================================================
 * REQUEST: AdvertisementRequest.php
 * ============================================================================
 *
 * Valida el alta y la edición de publicidades desde el panel administrador.
 *
 * DESTINOS EXTERNOS
 * ----------------------------------------------------------------------------
 *
 * Las publicidades externas de TRAMA se almacenan siempre como URL absoluta
 * HTTPS.
 *
 * Para facilitar la carga, si administración escribe un dominio sin protocolo,
 * se agrega automáticamente "https://" antes de validar y guardar.
 *
 * Ejemplos:
 *
 *     empresa.com
 *         -> https://empresa.com
 *
 *     www.empresa.com/oferta
 *         -> https://www.empresa.com/oferta
 *
 *     https://empresa.com
 *         -> se conserva igual
 *
 *     http://empresa.com
 *         -> se rechaza
 *
 * TRAMA no agrega "www." automáticamente porque "www" es un subdominio y no
 * puede asumirse que exista para todos los anunciantes.
 *
 * Además de validar la sintaxis, se comprueba que:
 *
 *     - la URL utilice HTTPS;
 *     - exista un host;
 *     - el host tenga forma correcta de dominio;
 *     - no sea una IP;
 *     - la extensión final tenga una estructura válida;
 *     - el dominio realmente resuelva por DNS.
 *
 * Así se evita aceptar valores como:
 *
 *     prueba
 *     empresa.com34324234234
 *     dominio-inventado-que-no-resuelve.com
 *
 *
 * DESTINOS INTERNOS DE DEMOSTRACIÓN
 * ----------------------------------------------------------------------------
 *
 * Las campañas ficticias del portfolio pueden apuntar a rutas internas seguras:
 *
 *     /demo/anunciantes/banco-nexo
 *
 * Estas rutas no necesitan protocolo porque pertenecen al propio portal.
 *
 *
 * EDICIÓN DE CAMPAÑAS
 * ----------------------------------------------------------------------------
 *
 * Marca y ubicación quedan ligadas a la publicidad histórica.
 *
 * Si la campaña ya comenzó, tampoco puede modificarse retrospectivamente su
 * fecha de inicio.
 * ============================================================================ */

namespace App\Http\Requests\Admin;

use App\Models\Advertisement;
use App\Support\AdvertisementPlacement;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdvertisementRequest extends FormRequest
{
    /**
     * Prefijo permitido para las páginas internas de anunciantes ficticios.
     */
    private const INTERNAL_DEMO_PREFIX = '/demo/anunciantes/';

    /**
     * Autoriza el uso del Form Request.
     *
     * El acceso al módulo ya está protegido por las rutas y middleware del panel
     * administrador.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normaliza los datos antes de ejecutar rules().
     *
     * Dos tareas importantes ocurren acá:
     *
     * 1. Se normaliza target_url ANTES de validar y antes de que el controlador
     *    llame a validated().
     *
     *    Si el administrador escribió:
     *
     *        empresa.com
     *
     *    el valor validado y finalmente almacenado será:
     *
     *        https://empresa.com
     *
     * 2. En edición se restauran los campos históricos que no pueden modificarse.
     */
    protected function prepareForValidation(): void
    {
        if ($this->isStatusOnlyUpdate()) {
            return;
        }

        /*
         * --------------------------------------------------------------------
         * NORMALIZACIÓN DEL DESTINO
         * --------------------------------------------------------------------
         */
        $targetUrl = $this->normalizeTargetUrl(
            $this->input('target_url')
        );

        /*
         * Desde este momento:
         *
         *     rules()
         *     validated()
         *     AdvertisementController
         *
         * reciben la URL ya normalizada.
         */
        $this->merge([
            'target_url' => $targetUrl,
        ]);

        /*
         * --------------------------------------------------------------------
         * PROTECCIÓN DE CAMPOS HISTÓRICOS
         * --------------------------------------------------------------------
         */
        $advertisement = $this->route(
            'advertisement'
        );

        /*
         * En creación todavía no existe publicidad histórica que proteger.
         *
         * IMPORTANTE:
         *
         * target_url ya fue normalizada antes de este return.
         */
        if (! $advertisement instanceof Advertisement) {
            return;
        }

        /*
         * Marca y ubicación permanecen asociadas históricamente a la campaña.
         */
        $locked = [
            'brand' => $advertisement->brand,
            'placement' => $advertisement->placement,
        ];

        /*
         * Una campaña iniciada conserva su fecha original.
         */
        if ($this->campaignHasStarted($advertisement)) {
            $locked['starts_at'] = $advertisement
                ->starts_at
                ?->format('Y-m-d\TH:i');
        }

        $this->merge($locked);
    }

    /**
     * Reglas utilizadas tanto al crear como al editar una publicidad.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        if ($this->isStatusOnlyUpdate()) {
            return [
                'is_active' => [
                    'required',
                    'boolean',
                ],
            ];
        }

        /*
         * Ubicación del banner seleccionada.
         */
        $placement = (string) $this->input(
            'placement',
            ''
        );

        /*
         * Dimensiones exactas correspondientes al placement.
         */
        $imageDimensions = AdvertisementPlacement::dimensions(
            $placement
        );

        /*
         * Determina si estamos creando o editando.
         */
        $advertisement = $this->route(
            'advertisement'
        );

        $isCreating = ! ($advertisement instanceof Advertisement);

        return [
            /*
             * Nombre interno de la campaña.
             */
            'name' => [
                'required',
                'string',
                'max:120',
            ],

            /*
             * Marca anunciante.
             */
            'brand' => [
                'required',
                'string',
                'max:80',
            ],

            /*
             * Solamente se aceptan placements registrados por TRAMA.
             */
            'placement' => [
                'required',
                Rule::in(
                    AdvertisementPlacement::values()
                ),
            ],

            'target_url' => [
                'required',
                'string',
                'max:2048',

                /*
                 * REGLA PERSONALIZADA PARA EL DESTINO DEL BANNER
                 * ----------------------------------------------------------------
                 *
                 * Laravel ejecuta automáticamente esta función anónima durante la
                 * validación del campo target_url.
                 *
                 * \Closure se escribe con barra inicial para utilizar de forma
                 * explícita la clase global nativa de PHP y evitar que PHP intente
                 * resolver:
                 *
                 *     App\Http\Requests\Admin\Closure
                 *
                 * Esto evita el error 500 que tuvimos anteriormente.
                 */
                function (
                    string $attribute,
                    mixed $value,
                    \Closure $fail
                ): void {
                    $target = trim(
                        (string) $value
                    );

                    /*
                     * ------------------------------------------------------------
                     * DESTINO INTERNO DE DEMOSTRACIÓN
                     * ------------------------------------------------------------
                     *
                     * Solamente permitimos exactamente:
                     *
                     *     /demo/anunciantes/slug-del-anunciante
                     *
                     * No permitimos:
                     *
                     *     ..
                     *     query strings
                     *     fragmentos
                     *     barras adicionales
                     *     caracteres especiales arbitrarios
                     */
                    if ($this->isInternalDemoTarget($target)) {
                        if (
                            preg_match(
                                '~^/demo/anunciantes/[a-z0-9]+(?:-[a-z0-9]+)*$~',
                                $target
                            ) !== 1
                        ) {
                            $fail(
                                'La página interna de destino no es válida.'
                            );
                        }

                        return;
                    }

                    /*
                     * ------------------------------------------------------------
                     * HTTPS OBLIGATORIO PARA DESTINOS EXTERNOS
                     * ------------------------------------------------------------
                     *
                     * Si administración escribió:
                     *
                     *     empresa.com
                     *
                     * prepareForValidation() ya lo convirtió en:
                     *
                     *     https://empresa.com
                     *
                     * En cambio:
                     *
                     *     http://empresa.com
                     *
                     * permanece HTTP y se rechaza acá.
                     */
                    if (
                        ! str_starts_with(
                            strtolower($target),
                            'https://'
                        )
                    ) {
                        $fail(
                            'La dirección externa debe utilizar HTTPS.'
                        );

                        return;
                    }

                    /*
                     * Comprueba la sintaxis completa de la URL.
                     *
                     * Rechaza valores como:
                     *
                     *     https://
                     *     https://hola mundo
                     *     https://???
                     */
                    if (
                        filter_var(
                            $target,
                            FILTER_VALIDATE_URL
                        ) === false
                    ) {
                        $fail(
                            'Ingresá una dirección web válida.'
                        );

                        return;
                    }

                    /*
                     * No permitimos credenciales embebidas en la URL.
                     *
                     * Ejemplo rechazado:
                     *
                     *     https://usuario:clave@empresa.com
                     *
                     * Un banner publicitario no necesita usuario ni contraseña
                     * dentro de su dirección de destino.
                     */
                    if (
                        parse_url(
                            $target,
                            PHP_URL_USER
                        ) !== null
                        || parse_url(
                            $target,
                            PHP_URL_PASS
                        ) !== null
                    ) {
                        $fail(
                            'Ingresá una dirección web válida.'
                        );

                        return;
                    }

                    /*
                     * Extraemos el host.
                     *
                     * Para:
                     *
                     *     https://www.empresa.com/oferta?id=5
                     *
                     * obtenemos:
                     *
                     *     www.empresa.com
                     */
                    $host = parse_url(
                        $target,
                        PHP_URL_HOST
                    );

                    /*
                     * Una URL externa necesita un host.
                     */
                    if (
                        ! is_string($host)
                        || trim($host) === ''
                    ) {
                        $fail(
                            'Ingresá una dirección web válida.'
                        );

                        return;
                    }

                    /*
                     * Normalizamos el hostname únicamente para validar.
                     */
                    $host = strtolower(
                        rtrim(
                            trim($host),
                            '.'
                        )
                    );

                    /*
                     * FILTER_VALIDATE_URL puede considerar válidos hostnames de
                     * una sola palabra.
                     *
                     * Por ejemplo:
                     *
                     *     https://prueba
                     *
                     * Para TRAMA eso no representa un dominio web externo válido.
                     */
                    if (! str_contains($host, '.')) {
                        $fail(
                            'Ingresá una dirección web válida.'
                        );

                        return;
                    }

                    /*
                     * Los destinos externos deben utilizar un dominio y no una IP.
                     *
                     * Rechazamos:
                     *
                     *     https://127.0.0.1
                     *     https://192.168.1.20
                     *     https://8.8.8.8
                     */
                    if (
                        filter_var(
                            $host,
                            FILTER_VALIDATE_IP
                        ) !== false
                    ) {
                        $fail(
                            'Ingresá una dirección web válida.'
                        );

                        return;
                    }

                    /*
                     * Valida la estructura general del hostname:
                     *
                     *     caracteres permitidos
                     *     guiones
                     *     puntos
                     *     etiquetas
                     */
                    if (
                        filter_var(
                            $host,
                            FILTER_VALIDATE_DOMAIN,
                            FILTER_FLAG_HOSTNAME
                        ) === false
                    ) {
                        $fail(
                            'Ingresá una dirección web válida.'
                        );

                        return;
                    }

                    /*
                     * ------------------------------------------------------------
                     * VALIDACIÓN DE LA EXTENSIÓN FINAL
                     * ------------------------------------------------------------
                     *
                     * FILTER_VALIDATE_DOMAIN valida la estructura general, pero
                     * puede aceptar una última etiqueta sintácticamente válida
                     * aunque sea absurda para nuestro formulario.
                     *
                     * Ejemplo:
                     *
                     *     empresa.com34324234234
                     *
                     * Por eso extraemos la última parte:
                     *
                     *     empresa.com
                     *             -> com
                     *
                     *     empresa.com.ar
                     *                -> ar
                     */
                    $hostParts = explode(
                        '.',
                        $host
                    );

                    $tld = strtolower(
                        (string) end($hostParts)
                    );

                    /*
                     * Admitimos extensiones ASCII habituales:
                     *
                     *     .com
                     *     .ar
                     *     .org
                     *     .net
                     *     .technology
                     *
                     * y también representación punycode de TLD internacional.
                     *
                     * Rechazamos:
                     *
                     *     .com34324234234
                     *     .123
                     *     .c0m
                     */
                    $standardTld = preg_match(
                        '/^[a-z]{2,63}$/',
                        $tld
                    ) === 1;

                    $punycodeTld = preg_match(
                        '/^xn--[a-z0-9-]{1,59}$/',
                        $tld
                    ) === 1;

                    if (! $standardTld && ! $punycodeTld) {
                        $fail(
                            'Ingresá una dirección web válida.'
                        );

                        return;
                    }

                    /*
                     * ------------------------------------------------------------
                     * COMPROBACIÓN DNS
                     * ------------------------------------------------------------
                     *
                     * Hasta acá sabemos que el texto tiene estructura válida.
                     *
                     * Ahora comprobamos además que el dominio realmente resuelva
                     * en DNS.
                     *
                     * Esto evita guardar dominios inventados como:
                     *
                     *     dominio-inexistente-asdasdasd.com
                     *
                     * si no poseen registros públicos.
                     *
                     * IMPORTANTE:
                     *
                     * No hacemos una petición HTTP al anunciante.
                     *
                     * No descargamos la página.
                     *
                     * No seguimos redirects.
                     *
                     * Solamente verificamos DNS.
                     */
                    if (! $this->domainResolves($host)) {
                        $fail(
                            'No pudimos verificar el dominio de destino.'
                        );

                        return;
                    }
                },
            ],

            /*
             * Fecha inicial de la campaña.
             */
            'starts_at' => [
                'required',
                'date',
            ],

            /*
             * La fecha final debe ser posterior al inicio.
             */
            'ends_at' => [
                'required',
                'date',
                'after:starts_at',
            ],

            /*
             * Estado administrativo.
             */
            'is_active' => [
                'required',
                'boolean',
            ],

            'image_file' => [
                /*
                 * En creación la imagen es obligatoria.
                 *
                 * En edición puede omitirse para conservar la existente.
                 */
                $isCreating
                    ? 'required'
                    : 'nullable',

                'image',

                /*
                 * Formatos permitidos.
                 */
                'mimes:jpg,jpeg,png,webp',

                /*
                 * Máximo 4096 KB = 4 MB.
                 */
                'max:4096',

                /*
                 * REGLA PERSONALIZADA PARA LAS DIMENSIONES
                 * ----------------------------------------------------------------
                 *
                 * use ($imageDimensions) permite acceder desde la Closure al
                 * tamaño correspondiente al placement.
                 */
                function (
                    string $attribute,
                    mixed $value,
                    \Closure $fail
                ) use ($imageDimensions): void {
                    /*
                     * En edición puede no existir una imagen nueva.
                     */
                    if (
                        $value === null
                        || $imageDimensions === null
                        || ! method_exists(
                            $value,
                            'getPathname'
                        )
                    ) {
                        return;
                    }

                    /*
                     * Lee las dimensiones reales de la imagen temporal.
                     */
                    $size = @getimagesize(
                        $value->getPathname()
                    );

                    if (! is_array($size)) {
                        $fail(
                            'No se pudo leer la imagen seleccionada.'
                        );

                        return;
                    }

                    [$width, $height] = $size;

                    /*
                     * El banner debe coincidir exactamente con las dimensiones
                     * correspondientes a su ubicación.
                     */
                    if (
                        $width !== $imageDimensions['width']
                        || $height !== $imageDimensions['height']
                    ) {
                        $placementLabel = AdvertisementPlacement::label(
                            (string) $this->input(
                                'placement',
                                ''
                            )
                        );

                        $fail(
                            "La imagen para {$placementLabel} debe medir exactamente "
                            ."{$imageDimensions['width']} × "
                            ."{$imageDimensions['height']} px."
                        );
                    }
                },
            ],
        ];
    }

    /**
     * Mensajes personalizados de las reglas estándar.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' =>
                'Ingresá un nombre para identificar la campaña.',

            'name.max' =>
                'El nombre no puede superar los 120 caracteres.',

            'brand.required' =>
                'Ingresá la marca anunciante.',

            'brand.max' =>
                'La marca no puede superar los 80 caracteres.',

            'placement.required' =>
                'Elegí dónde se mostrará el banner.',

            'placement.in' =>
                'La ubicación seleccionada no es válida.',

            'target_url.required' =>
                'Ingresá la dirección de destino.',

            'target_url.max' =>
                'La dirección de destino no puede superar los 2048 caracteres.',

            'starts_at.required' =>
                'Indicá cuándo empieza la campaña.',

            'starts_at.date' =>
                'La fecha de inicio no es válida.',

            'ends_at.required' =>
                'Indicá cuándo termina la campaña.',

            'ends_at.date' =>
                'La fecha de finalización no es válida.',

            'ends_at.after' =>
                'La fecha de finalización debe ser posterior al inicio.',

            'is_active.required' =>
                'Indicá si la publicidad está activa.',

            'is_active.boolean' =>
                'El estado activo no es válido.',

            'image_file.required' =>
                'Seleccioná la imagen del banner.',

            'image_file.image' =>
                'El archivo seleccionado debe ser una imagen válida.',

            'image_file.mimes' =>
                'La imagen debe estar en JPG, JPEG, PNG o WEBP.',

            'image_file.max' =>
                'La imagen no puede superar los 4 MB.',
        ];
    }

    /**
     * Normaliza el destino recibido desde el formulario.
     *
     * Reglas:
     *
     *     /demo/anunciantes/...
     *         -> se conserva como ruta interna.
     *
     *     empresa.com
     *         -> https://empresa.com
     *
     *     www.empresa.com
     *         -> https://www.empresa.com
     *
     *     empresa.com/oferta
     *         -> https://empresa.com/oferta
     *
     *     https://empresa.com
     *         -> se conserva como HTTPS.
     *
     *     http://empresa.com
     *         -> se conserva como HTTP para que rules() lo rechace.
     *
     * Nunca se agrega "www." automáticamente.
     */
    private function normalizeTargetUrl(mixed $value): string
    {
        /*
         * Convertimos a texto y eliminamos espacios exteriores.
         */
        $target = trim(
            (string) $value
        );

        /*
         * required se ocupará del campo vacío.
         */
        if ($target === '') {
            return '';
        }

        /*
         * Una ruta demo interna se conserva exactamente.
         */
        if ($this->isInternalDemoTarget($target)) {
            return $target;
        }

        /*
         * Detecta cualquier esquema escrito explícitamente.
         *
         * Ejemplos:
         *
         *     https:
         *     http:
         *     ftp:
         *     javascript:
         *     mailto:
         */
        $hasExplicitScheme = preg_match(
            '/^[a-z][a-z0-9+.-]*:/i',
            $target
        ) === 1;

        if ($hasExplicitScheme) {
            /*
             * Si ya es HTTPS normalizamos únicamente la escritura del protocolo
             * para que quede siempre en minúsculas.
             */
            if (
                preg_match(
                    '/^https:\/\//i',
                    $target
                ) === 1
            ) {
                return 'https://'.substr(
                    $target,
                    8
                );
            }

            /*
             * HTTP, FTP, javascript, etc. permanecen intactos.
             *
             * rules() será quien los rechace.
             */
            return $target;
        }

        /*
         * Sin protocolo explícito, TRAMA asume HTTPS.
         *
         * Ejemplo:
         *
         *     empresa.com
         *
         * pasa a:
         *
         *     https://empresa.com
         *
         * No agregamos "www.".
         */
        return 'https://'.ltrim(
            $target,
            '/'
        );
    }

    /**
     * Indica si el destino intenta utilizar una página interna de demostración.
     */
    private function isInternalDemoTarget(string $target): bool
    {
        return str_starts_with(
            $target,
            self::INTERNAL_DEMO_PREFIX
        );
    }

    public function isStatusOnlyUpdate(): bool
    {
        $advertisement = $this->route(
            'advertisement'
        );

        if (! $advertisement instanceof Advertisement) {
            return false;
        }

        $keys = array_keys(
            $this->except([
                '_method',
                '_token',
            ])
        );

        sort($keys);

        return $keys === [
            'is_active',
        ];
    }

    /**
     * Comprueba que el dominio tenga resolución DNS útil para un sitio web.
     *
     * Se consultan:
     *
     *     A
     *     AAAA
     *     CNAME
     *
     * No se realiza una petición HTTP.
     */
    private function domainResolves(string $host): bool
    {
        /*
         * checkdnsrr() puede emitir warnings si el resolver local tiene un
         * problema puntual.
         *
         * El operador @ evita que esos warnings rompan el formulario.
         */
        return @checkdnsrr(
            $host,
            'A'
        )
            || @checkdnsrr(
                $host,
                'AAAA'
            )
            || @checkdnsrr(
                $host,
                'CNAME'
            );
    }

    /**
     * Indica si la campaña ya alcanzó su fecha de inicio según el reloj editorial
     * utilizado por TRAMA.
     */
    private function campaignHasStarted(
        Advertisement $advertisement
    ): bool {
        /*
         * Sin fecha inicial no puede considerarse iniciada.
         */
        if ($advertisement->starts_at === null) {
            return false;
        }

        /*
         * Fecha editorial de referencia de TRAMA.
         */
        $referenceDate = CarbonImmutable::parse(
            (string) config(
                'trama.reference_date',
                '2026-07-19'
            )
        );

        /*
         * Si starts_at ya quedó dentro o antes del día editorial actual,
         * consideramos que la campaña comenzó.
         */
        return $advertisement->starts_at->lessThanOrEqualTo(
            $referenceDate->endOfDay()
        );
    }
}
