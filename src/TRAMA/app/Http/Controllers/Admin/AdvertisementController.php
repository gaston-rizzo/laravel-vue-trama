<?php

/* ============================================================================
 * CONTROLLER: AdvertisementController.php
 * ============================================================================
 *
 * Administra las publicidades cargadas para TRAMA.
 *
 * Muestra una grilla paginada con búsqueda, filtros, métricas y estados de
 * vigencia. También permite crear campañas y editar los datos que pueden
 * cambiar sin falsear su identidad histórica: nombre, imagen, destino,
 * finalización y estado habilitado. Marca y ubicación quedan fijas en edición.
 *
 * El reporte conserva el historial de impresiones y clics y calcula sus
 * resultados sobre el período seleccionado, mientras la grilla pagina y ordena
 * las campañas desde Laravel.
 * ============================================================================ */

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AdvertisementIndexRequest;
use App\Http\Requests\Admin\AdvertisementRequest;
use App\Models\Advertisement;
use App\Models\AdvertisementDailyMetric;
use App\Services\Images\SafeImageProcessor;
use App\Support\AdvertisementPlacement;
use App\Support\OperationFailureHandler;
use App\Support\TramaBridge;
use App\Support\TramaLog;
use App\Support\TramaClock;

use Carbon\CarbonImmutable;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

use Inertia\Response;
use Throwable;

class AdvertisementController extends Controller
{
    public function __construct(
        private readonly OperationFailureHandler $operationFailures,
        private readonly SafeImageProcessor $imageProcessor,
    ) {
    }

    /**
     * Muestra publicidades y métricas con búsqueda, filtros, período y paginación.
     */
    public function index(AdvertisementIndexRequest $request): Response
    {
        // Filtros validados del reporte; los valores vacíos no restringen campañas.
        $filters = $request->filters();
        /*
        * El panel de publicidades utiliza el mismo reloj editorial centralizado
        * que el resto de TRAMA.
        *
        * TramaClock conserva la fecha demo configurada (19/07/2026) y el horario
        * editorial persistente del proyecto, en lugar de utilizar la fecha/hora
        * real directamente mediante CarbonImmutable::now().
        */
        $editorialNow = TramaClock::now();
        /*
        * Para filtros, métricas y estados de campaña necesitamos únicamente el
        * día editorial, mientras que editorialNow conserva también la hora exacta
        * para precargar el formulario de Nueva publicidad.
        */
        $referenceDate = $editorialNow->startOfDay();

        [$metricDateFrom, $metricDateTo] = $this->metricPeriod($filters['period'], $referenceDate);

        $advertisementsPayload = [
            'data' => [],
            'pagination' => [
                'current_page' => 1,
                'last_page' => 1,
                'per_page' => $filters['per_page'],
                'total' => 0,
                'from' => null,
                'to' => null,
                'links' => [],
            ],
        ];

        $report = [
            'impressions_total' => 0,
            'clicks_total' => 0,
            'ctr' => 0.0,
            'active_ads_count' => 0,
        ];

        $chart = [
            'labels' => [],
            'impressions' => [],
            'clicks' => [],
        ];

        $filterOptions = [
            'placements' => collect(AdvertisementPlacement::values())
                ->map(fn (string $placement): array => [
                    'value' => $placement,
                    'label' => AdvertisementPlacement::label($placement),
                    'dimensions' => AdvertisementPlacement::dimensions($placement),
                ])
                ->values(),
            'brands' => [],
        ];

        $loadError = '';

        try {
            $filteredQuery = $this->filteredAdvertisementQuery($filters, $referenceDate);

            /*
             * Las sumas que acompañan cada fila respetan el período elegido en
             * el reporte. De esa forma tabla, tarjetas y gráfico hablan del mismo
             * intervalo sin tener que cargar todas las métricas en PHP.
             */
            // Calcula impresiones y clicks del período seleccionado
            // que se mostrarán en la tabla para cada banner.
            $tableQuery = (clone $filteredQuery)
                ->withSum([
                    'metricRecordsByDay as impressions_total' => fn (Builder $query): Builder => $this->applyMetricPeriod(
                        $query,
                        $metricDateFrom,
                        $metricDateTo,
                    ),
                ], 'impressions_count')
                ->withSum([
                    'metricRecordsByDay as clicks_total' => fn (Builder $query): Builder => $this->applyMetricPeriod(
                        $query,
                        $metricDateFrom,
                        $metricDateTo,
                    ),
                ], 'clicks_count');

            // El orden predeterminado agrupa por marca. Si el administrador elige
            // otra columna, applySorting aplica ese criterio en el servidor.
            // Un segundo orden estable evita saltos entre páginas con valores iguales.
            $this->applySorting(
                $tableQuery,
                $filters['sort'],
                $filters['direction'],
                $referenceDate,
            );

            $advertisements = $tableQuery
                ->paginate($filters['per_page'])
                ->withQueryString();

            $advertisements->getCollection()->transform(
                fn (Advertisement $advertisement): array => $this->serializeAdvertisement(
                    $advertisement,
                    $referenceDate,
                )
            );

            $advertisementsPayload = [
                'data' => $advertisements->getCollection()->values()->all(),
                'pagination' => [
                    'current_page' => $advertisements->currentPage(),
                    'last_page' => $advertisements->lastPage(),
                    'per_page' => $advertisements->perPage(),
                    'total' => $advertisements->total(),
                    'from' => $advertisements->firstItem(),
                    'to' => $advertisements->lastItem(),
                    'links' => $advertisements->linkCollection()->toArray(),
                ],
            ];

            // IDs filtrados que definen qué métricas entran en tarjetas y gráfico.
            $advertisementIds = (clone $filteredQuery)->pluck('id');

            $totalsQuery = AdvertisementDailyMetric::query()
                // Si el filtro no devuelve banners, no se suman métricas de campañas ajenas.
                ->whereIn('advertisement_id', $advertisementIds);

            $this->applyMetricPeriod($totalsQuery, $metricDateFrom, $metricDateTo);

            $totals = $totalsQuery
                ->selectRaw('COALESCE(SUM(impressions_count), 0) as impressions_total')
                ->selectRaw('COALESCE(SUM(clicks_count), 0) as clicks_total')
                ->first();

            $impressionsTotal = (int) ($totals?->impressions_total ?? 0);
            $clicksTotal = (int) ($totals?->clicks_total ?? 0);

            $dailyMetricsQuery = AdvertisementDailyMetric::query()
                // El gráfico respeta los mismos filtros que la tabla y las tarjetas.
                ->whereIn('advertisement_id', $advertisementIds);

            $this->applyMetricPeriod($dailyMetricsQuery, $metricDateFrom, $metricDateTo);

            $dailyMetrics = $dailyMetricsQuery
                ->selectRaw('date')
                ->selectRaw('SUM(impressions_count) as impressions_total')
                ->selectRaw('SUM(clicks_count) as clicks_total')
                ->groupBy('date')
                ->orderBy('date')
                ->get();

            $activeAdsQuery = clone $filteredQuery;
            $this->applyStatusFilter($activeAdsQuery, 'active', $referenceDate);

            $report = [
                'impressions_total' => $impressionsTotal,
                'clicks_total' => $clicksTotal,
                'ctr' => $this->clickThroughRate($impressionsTotal, $clicksTotal),
                'active_ads_count' => $activeAdsQuery->count(),
            ];

            $chart = [
                'labels' => $dailyMetrics->map(fn (AdvertisementDailyMetric $metric) => $metric->date->format('d/m'))->values(),
                'impressions' => $dailyMetrics->pluck('impressions_total')->map(fn ($value) => (int) $value)->values(),
                'clicks' => $dailyMetrics->pluck('clicks_total')->map(fn ($value) => (int) $value)->values(),
            ];

            $filterOptions['brands'] = Advertisement::query()
                ->select('brand')
                ->distinct()
                ->orderBy('brand')
                ->pluck('brand');
        } catch (Throwable $exception) {
            if ($this->isDatabaseUnavailable($exception)) {
                throw $exception;
            }

            TramaLog::error(
                'Falló la carga del panel administrador de publicidades.',
                [
                    'area' => 'admin_advertisements',
                    'operation' => 'load_index',
                    'user_id' => $request->user()?->id,
                    'q' => $filters['q'] ?: null,
                    'placement' => $filters['placement'] ?: null,
                    'brand' => $filters['brand'] ?: null,
                    'status' => $filters['status'] ?: null,
                    'period' => $filters['period'],
                    'per_page' => $filters['per_page'],
                    'exception_class' => $exception::class,
                    'exception_message' => $exception->getMessage(),
                    'exception_file' => $exception->getFile(),
                    'exception_line' => $exception->getLine(),
                ],
            );

            $loadError = 'No pudimos cargar las publicidades en este momento. Intentá nuevamente.';
        }

        return TramaBridge::render('Admin/Advertisements/Index', [
            'advertisements' => $advertisementsPayload,
            'report' => $report,
            'chart' => $chart,
            'filters' => $filters,
            'filterOptions' => $filterOptions,
            'referenceDate' => $referenceDate->toDateString(),

            /*
             * Momento editorial exacto para precargar el inicio de una campaña:
             * fecha fija de TRAMA + hora real actual del servidor.
             * Se envía sin zona horaria para que el DatePicker conserve esa hora.
             */
            'editorialNow' => $editorialNow->format('Y-m-d\TH:i'),
            'loadError' => $loadError,
        ]);
    }

    /**
     * Crea una publicidad completa y genera su banner WebP determinista.
     */
    public function store(AdvertisementRequest $request): RedirectResponse
    {
        try {
            $validated = $request->validated();
            $image = $request->file('image_file');

            if (! $image) {
                throw ValidationException::withMessages([
                    'image_file' => 'Seleccioná la imagen del banner.',
                ]);
            }

            $filename = $this->bannerFilename(
                $validated['brand'],
                $validated['name'],
                $validated['placement'],
            );

            $this->ensureBannerFilenameAvailable($filename);
            $imagePath = $this->storeBannerImage(
                $image->getPathname(),
                $filename,
                $validated['placement'],
                overwrite: false,
            );

            try {
                Advertisement::query()->create([
                    'name' => $validated['name'],
                    'brand' => $validated['brand'],
                    'placement' => $validated['placement'],
                    'image_path' => $imagePath,
                    'target_url' => trim($validated['target_url']),
                    'starts_at' => $validated['starts_at'],
                    'ends_at' => $validated['ends_at'],
                    'is_active' => (bool) $validated['is_active'],
                ]);
            } catch (Throwable $exception) {
                // Si la fila no pudo persistirse, no se deja el WebP huérfano.
                $this->deleteBannerFile($imagePath);
                throw $exception;
            }

            return back()->with('status', 'Publicidad creada.');
        } catch (Throwable $exception) {
            $this->operationFailures->fail(
                $exception,
                'admin_advertisements',
                'create_advertisement',
                'No pudimos crear la publicidad. Intentá nuevamente.',
                ['user_id' => $request->user()?->id],
            );
        }
    }

    /**
     * Actualiza una campaña conservando sus métricas históricas.
     *
     * Marca y ubicación no se reasignan porque identifican a quién perteneció
     * la campaña y en qué espacio acumuló sus impresiones y clicks. La fecha de
     * inicio también se conserva cuando la campaña ya comenzó.
     *
     * El archivo físico mantiene siempre la convención determinista:
     *
     *     marca-campaña-dimensiones.webp
     *
     * Por eso el nombre esperado se recalcula en TODA edición, incluso cuando el
     * administrador cambia solamente el nombre de la campaña y no sube una imagen
     * nueva. Si el nombre cambió, el WebP existente se renombra de forma segura y
     * image_path se actualiza en la misma operación.
     */
    public function update(AdvertisementRequest $request, Advertisement $advertisement): RedirectResponse
    {
        try {
            $validated = $request->validated();

            if ($request->isStatusOnlyUpdate()) {
                $advertisement->update([
                    'is_active' => (bool) $validated['is_active'],
                ]);

                return back()->with(
                    'status',
                    (bool) $validated['is_active']
                        ? 'Publicidad habilitada.'
                        : 'Publicidad pausada.'
                );
            }

            $referenceDate = CarbonImmutable::parse(
                (string) config('trama.reference_date', '2026-07-19')
            );

            $payload = [
                'name' => $validated['name'],

                // La marca queda ligada al historial de métricas de la campaña.
                'brand' => $advertisement->brand,

                // La ubicación queda ligada al formato y al historial del banner.
                'placement' => $advertisement->placement,

                'target_url' => trim($validated['target_url']),

                // Una campaña futura puede mover su inicio. Cuando ya comenzó,
                // conserva la fecha original aunque la petición haya sido alterada.
                'starts_at' => $this->campaignHasStarted($advertisement, $referenceDate)
                    ? $advertisement->starts_at
                    : $validated['starts_at'],

                'ends_at' => $validated['ends_at'],
                'is_active' => (bool) $validated['is_active'],
            ];

            /*
             * El nombre del archivo depende de marca + nombre de campaña +
             * dimensiones. Como marca y placement están bloqueados en edición,
             * el único dato que puede cambiar esta ruta es el nombre de campaña.
             *
             * Ejemplo:
             *
             *     acme-verano-1456x180.webp
             *
             * al cambiar la campaña a "Pepito":
             *
             *     acme-pepito-1456x180.webp
             */
            $desiredFilename = $this->bannerFilename(
                $advertisement->brand,
                $validated['name'],
                $advertisement->placement,
            );

            $previousImagePath = (string) $advertisement->image_path;
            $currentFilename = basename($previousImagePath);
            $image = $request->file('image_file');

            /*
             * Si no se cargó una imagen nueva, el archivo físico que figura en image_path
             * debe continuar existiendo.
             *
             * Esta comprobación protege al sistema frente a modificaciones manuales en la
             * carpeta de banners. Por ejemplo, si la base conserva:
             *
             *     acme-verano-1456x180.webp
             *
             * pero alguien renombró físicamente ese archivo desde Windows, no permitimos
             * guardar otros cambios como si la publicidad siguiera íntegra.
             *
             * El administrador puede reparar la campaña simplemente cargando una imagen
             * nueva válida desde este mismo formulario.
             */
            if (
                ! $image
                && ! $this->bannerFileExists($previousImagePath)
            ) {
                throw ValidationException::withMessages([
                    'image_file' => 'El archivo actual del banner no existe. Subí nuevamente la imagen para reparar la publicidad.',
                ]);
            }

            /*
             * Impide que otra publicidad o un archivo ajeno ya esté usando el
             * nombre que debería corresponder a esta campaña.
             *
             * Si el nombre deseado ya es el actual de esta misma publicidad,
             * ensureBannerFilenameAvailable() reconoce esa pertenencia y lo permite.
             */
            $this->ensureBannerFilenameAvailable(
                $desiredFilename,
                $advertisement
            );

            /*
             * Guarda qué transformación física ocurrió para poder deshacerla si
             * posteriormente falla el UPDATE de la base de datos.
             */
            $newImagePath = null;
            $existingImageWasRenamed = false;

            if ($image) {
                /*
                 * Si se cargó una nueva pieza, la procesamos directamente con el
                 * nombre determinista ACTUAL, basado en el nombre de campaña que
                 * acaba de validar el formulario.
                 *
                 * Cuando el filename no cambió se permite sobrescribir la pieza
                 * actual. Cuando cambió, el archivo viejo permanece intacto hasta
                 * que la base confirme la actualización.
                 */
                $newImagePath = $this->storeBannerImage(
                    $image->getPathname(),
                    $desiredFilename,
                    $advertisement->placement,
                    overwrite: $currentFilename === $desiredFilename,
                );

                $payload['image_path'] = $newImagePath;
            } elseif ($currentFilename !== $desiredFilename) {
                /*
                 * No se subió una imagen nueva, pero cambió el nombre de campaña.
                 *
                 * En ese caso NO reprocesamos el WebP ni obligamos al administrador
                 * a volver a cargarlo: simplemente renombramos de forma segura el
                 * archivo físico existente.
                 */
                $this->renameBannerFile(
                    $currentFilename,
                    $desiredFilename
                );

                $existingImageWasRenamed = true;
                $payload['image_path'] = $desiredFilename;
            }

            try {
                /*
                 * Recién después de tener preparada la imagen o el rename físico
                 * actualizamos la fila.
                 */
                $advertisement->update($payload);
            } catch (Throwable $exception) {
                /*
                 * Si se había creado una NUEVA imagen con otro nombre y la base
                 * falla, eliminamos ese archivo nuevo para no dejar un huérfano.
                 *
                 * El archivo anterior todavía existe porque sólo se elimina tras
                 * un UPDATE exitoso.
                 */
                if (
                    $newImagePath !== null
                    && $newImagePath !== $previousImagePath
                ) {
                    $this->deleteBannerFile($newImagePath);
                }

                /*
                 * Si no hubo imagen nueva sino un rename del archivo existente,
                 * intentamos devolverlo a su nombre original antes de propagar el
                 * error de base de datos.
                 */
                if ($existingImageWasRenamed) {
                    $this->restoreBannerFilename(
                        $desiredFilename,
                        $currentFilename
                    );
                }

                throw $exception;
            }

            /*
             * Cuando se subió una imagen nueva con un filename distinto, la fila
             * ya apunta al nuevo WebP. Ahora sí puede eliminarse el archivo viejo.
             *
             * En el caso de un simple rename no hacemos delete: File::move() ya
             * trasladó físicamente el mismo archivo al nuevo nombre.
             */
            if (
                $newImagePath !== null
                && $previousImagePath !== $newImagePath
            ) {
                $this->deleteBannerFile($previousImagePath);
            }

            return back()->with('status', 'Publicidad actualizada.');
        } catch (Throwable $exception) {
            $this->operationFailures->fail(
                $exception,
                'admin_advertisements',
                'update_advertisement',
                'No pudimos actualizar la publicidad. Intentá nuevamente.',
                [
                    'user_id' => $request->user()?->id,
                    'advertisement_id' => $advertisement->id,
                ],
            );
        }
    }

    /**
     * Elimina definitivamente una publicidad y su archivo físico administrado.
     *
     * El orden es intencional:
     *
     *     1. Conservamos el nombre actual del WebP.
     *     2. Eliminamos la fila de advertisements.
     *     3. Las métricas asociadas se eliminan por la FK ON DELETE CASCADE.
     *     4. Recién después quitamos el archivo físico del banner.
     *
     * De esta forma nunca eliminamos primero la imagen y dejamos una publicidad
     * todavía existente en la base apuntando a un archivo que ya no está.
     */
    public function destroy(
        Request $request,
        Advertisement $advertisement,
    ): RedirectResponse {
        $referenceDate = CarbonImmutable::parse(
            (string) config('trama.reference_date', '2026-07-19')
        );

        $status = $this->campaignStatus(
            $advertisement,
            $referenceDate
        );

        if (in_array($status['value'], ['active', 'scheduled'], true)) {
            throw ValidationException::withMessages([
                'operation' => 'Primero pausá la campaña. Solo las campañas pausadas o finalizadas pueden eliminarse.',
            ]);
        }

        /*
         * Guardamos el nombre antes de delete() para poder localizar el WebP aunque
         * el registro ya haya sido eliminado correctamente de la base.
         */
        $imagePath = (string) $advertisement->image_path;

        try {
            /*
             * advertisement_daily_metrics depende de advertisements mediante una FK
             * con ON DELETE CASCADE, por lo que sus métricas se eliminan junto con la
             * campaña y no hace falta borrarlas manualmente desde este controlador.
             */
            $advertisement->delete();

            /*
             * La baja en base ya fue confirmada. Eliminamos ahora el WebP administrado
             * usando el helper existente, que restringe la operación a un basename
             * válido dentro de la carpeta configurada de banners.
             */
            $this->deleteBannerFile($imagePath);

            return back()->with(
                'status',
                'Publicidad eliminada.',
            );
        } catch (Throwable $exception) {
            /*
             * Mantiene el mismo tratamiento centralizado de errores que store() y
             * update(), incluyendo contexto suficiente para diagnosticar la operación.
             */
            $this->operationFailures->fail(
                $exception,
                'admin_advertisements',
                'delete_advertisement',
                'No pudimos eliminar la publicidad. Intentá nuevamente.',
                [
                    'user_id' => $request->user()?->id,
                    'advertisement_id' => $advertisement->id,
                    'image_path' => $imagePath ?: null,
                ],
            );
        }
    }

    /**
     * Calcula el CTR de una publicidad o grupo de publicidades.
     *
     * CTR significa porcentaje de clicks sobre impresiones. Si no hay
     * impresiones, devuelve cero para evitar divisiones inválidas.
     */
    private function clickThroughRate(int $impressions, int $clicks): float
    {
        if ($impressions === 0) {
            return 0.0;
        }

        return round(($clicks / $impressions) * 100, 2);
    }

    /**
     * Consulta base para búsqueda y filtros de campañas.
     *
     * @param array<string, mixed> $filters
     */
    private function filteredAdvertisementQuery(array $filters, CarbonImmutable $referenceDate): Builder
    {
        $query = Advertisement::query()
            // Permite ver solo header, sidebar superior o sidebar inferior.
            ->when($filters['placement'], fn (Builder $query, string $placement): Builder => $query->where('placement', $placement))
            // Permite revisar el rendimiento de una sola marca.
            ->when($filters['brand'], fn (Builder $query, string $brand): Builder => $query->where('brand', $brand))
            ->when($filters['q'], function (Builder $query, string $search): void {
                $query->where(function (Builder $inner) use ($search): void {
                    $inner->where('name', 'like', "%{$search}%")
                        ->orWhere('brand', 'like', "%{$search}%");
                });
            });

        $this->applyStatusFilter($query, $filters['status'], $referenceDate);

        return $query;
    }

    /**
     * Aplica los estados derivados de vigencia sin crear una columna redundante.
     */
    private function applyStatusFilter(Builder $query, string $status, CarbonImmutable $referenceDate): void
    {
        $start = $referenceDate->startOfDay();
        $end = $referenceDate->endOfDay();

        match ($status) {
            'active' => $query
                ->where('is_active', true)
                ->where('starts_at', '<=', $end)
                ->where('ends_at', '>=', $start),
            'scheduled' => $query
                ->where('is_active', true)
                ->where('starts_at', '>', $end),
            'finished' => $query
                ->where('is_active', true)
                ->where('ends_at', '<', $start),
            'paused' => $query->where('is_active', false),
            default => null,
        };
    }

    /**
     * Determina el estado visual de la campaña para la fecha editorial de TRAMA.
     */
    private function campaignStatus(Advertisement $advertisement, CarbonImmutable $referenceDate): array
    {
        if (! $advertisement->is_active) {
            return ['value' => 'paused', 'label' => 'PAUSADA'];
        }

        if ($advertisement->starts_at->isAfter($referenceDate->endOfDay())) {
            return ['value' => 'scheduled', 'label' => 'PROGRAMADA'];
        }

        if ($advertisement->ends_at->isBefore($referenceDate->startOfDay())) {
            return ['value' => 'finished', 'label' => 'FINALIZADA'];
        }

        return ['value' => 'active', 'label' => 'ACTIVA'];
    }

    /**
     * Transforma una campaña en la estructura liviana utilizada por la tabla.
     */
    private function serializeAdvertisement(Advertisement $advertisement, CarbonImmutable $referenceDate): array
    {
        $impressionsTotal = (int) ($advertisement->impressions_total ?? 0);
        $clicksTotal = (int) ($advertisement->clicks_total ?? 0);
        $status = $this->campaignStatus($advertisement, $referenceDate);

        return [
            'id' => $advertisement->id,
            'name' => $advertisement->name,
            'brand' => $advertisement->brand,
            'placement' => $advertisement->placement,
            'placement_label' => AdvertisementPlacement::label($advertisement->placement),
            'placement_dimensions' => AdvertisementPlacement::dimensions($advertisement->placement),
            'image_path' => $advertisement->image_path,
            'image_url' => $advertisement->imageUrl(),
           /*
            * No confiamos únicamente en image_path: comprobamos que el WebP asociado
            * siga existiendo físicamente. El frontend utiliza esta bandera para evitar
            * mostrar una imagen rota y advertir que la campaña necesita reparación.
            */
            'image_exists' => $this->bannerFileExists(
                (string) $advertisement->image_path
            ),
            'target_url' => $advertisement->target_url,
            'starts_at' => $advertisement->starts_at?->format('Y-m-d\TH:i'),
            'ends_at' => $advertisement->ends_at?->format('Y-m-d\TH:i'),
            'starts_at_label' => $advertisement->starts_at?->format('d/m/Y H:i'),
            'ends_at_label' => $advertisement->ends_at?->format('d/m/Y H:i'),
            'is_active' => (bool) $advertisement->is_active,
            'has_started' => $this->campaignHasStarted($advertisement, $referenceDate),
            'status' => $status['value'],
            'status_label' => $status['label'],
            'impressions_total' => $impressionsTotal,
            'clicks_total' => $clicksTotal,
            'ctr' => $this->clickThroughRate($impressionsTotal, $clicksTotal),
        ];
    }

    /**
     * Ordena la grilla según las cabeceras habilitadas en el panel.
     *
     * El orden se ejecuta siempre en Laravel para que afecte al conjunto completo
     * de resultados y no solamente a las filas de la página ya enviada a Vue.
     */
    private function applySorting(
        Builder $query,
        string $sort,
        string $direction,
        CarbonImmutable $referenceDate,
    ): void {
        if ($sort === 'banner') {
            $query
                ->orderBy('brand', $direction)
                ->orderBy('name', $direction)
                ->orderBy('id');

            return;
        }

        if ($sort === 'placement') {
            /*
             * Conserva un orden editorial natural de los espacios:
             * Header, Sidebar superior y Sidebar inferior.
             */
            $query
                ->orderByRaw(
                    "CASE placement
                        WHEN ? THEN 1
                        WHEN ? THEN 2
                        WHEN ? THEN 3
                        ELSE 4
                    END {$direction}",
                    [
                        AdvertisementPlacement::HEADER,
                        AdvertisementPlacement::ARTICLE_SIDEBAR_TOP,
                        AdvertisementPlacement::ARTICLE_SIDEBAR_BOTTOM,
                    ],
                )
                ->orderBy('id');

            return;
        }

        if ($sort === 'status') {
            /*
             * El estado no vive en una columna propia: se deriva de is_active y
             * de la vigencia. El CASE replica esa misma lógica para ordenarlo.
             *
             * En ascendente queda:
             * ACTIVA, FINALIZADA, PAUSADA, PROGRAMADA.
             */
            $query
                ->orderByRaw(
                    "CASE
                        WHEN is_active = 0 THEN 3
                        WHEN ends_at < ? THEN 2
                        WHEN starts_at > ? THEN 4
                        ELSE 1
                    END {$direction}",
                    [
                        $referenceDate->startOfDay()->toDateTimeString(),
                        $referenceDate->endOfDay()->toDateTimeString(),
                    ],
                )
                ->orderBy('id');

            return;
        }

        // Vigencia se ordena por el inicio de la campaña.
        $query
            ->orderBy('starts_at', $direction)
            ->orderBy('id');
    }

    /**
     * Indica si una campaña ya alcanzó su fecha de inicio para el reloj de TRAMA.
     */
    private function campaignHasStarted(
        Advertisement $advertisement,
        CarbonImmutable $referenceDate,
    ): bool {
        if ($advertisement->starts_at === null) {
            return false;
        }

        return $advertisement->starts_at->lessThanOrEqualTo(
            $referenceDate->endOfDay()
        );
    }

    /**
     * Convierte el selector de período en fechas para el reporte de métricas.
     *
     * @return array{0: CarbonImmutable|null, 1: CarbonImmutable|null}
     */
    private function metricPeriod(string $period, CarbonImmutable $referenceDate): array
    {
        return match ($period) {
            '7' => [$referenceDate->subDays(6)->startOfDay(), $referenceDate->endOfDay()],
            '30' => [$referenceDate->subDays(29)->startOfDay(), $referenceDate->endOfDay()],
            default => [null, null],
        };
    }

    /**
     * Limita una consulta de métricas al período elegido, cuando corresponde.
     */
    private function applyMetricPeriod(Builder $query, ?CarbonImmutable $from, ?CarbonImmutable $to): Builder
    {
        return $query
            ->when($from, fn (Builder $query, CarbonImmutable $date): Builder => $query->whereDate('date', '>=', $date->toDateString()))
            ->when($to, fn (Builder $query, CarbonImmutable $date): Builder => $query->whereDate('date', '<=', $date->toDateString()));
    }

    /**
     * Construye el nombre único y determinista marca-campaña-dimensiones.webp.
     */
    private function bannerFilename(string $brand, string $name, string $placement): string
    {
        $dimensions = AdvertisementPlacement::dimensions($placement);

        if ($dimensions === null) {
            throw ValidationException::withMessages([
                'placement' => 'La ubicación seleccionada no es válida.',
            ]);
        }

        $brandSlug = Str::slug($brand) ?: 'marca';
        $campaignSlug = Str::slug($name) ?: 'campana';

        return sprintf(
            '%s-%s-%dx%d.webp',
            $brandSlug,
            $campaignSlug,
            $dimensions['width'],
            $dimensions['height'],
        );
    }

    /**
     * Impide duplicar el mismo nombre de archivo en otra publicidad.
     *
     * El sistema nunca resuelve la colisión agregando -2, -3 o valores aleatorios.
     */
    private function ensureBannerFilenameAvailable(
        string $filename,
        ?Advertisement $current = null,
    ): void {
        $query = Advertisement::query()->where('image_path', $filename);

        if ($current !== null) {
            $query->where('id', '!=', $current->id);
        }

        $physicalPath = $this->bannerDirectory().DIRECTORY_SEPARATOR.$filename;
        $fileBelongsToCurrent = $current !== null
            && basename((string) $current->image_path) === $filename;

        if ($query->exists() || (File::exists($physicalPath) && ! $fileBelongsToCurrent)) {
            throw ValidationException::withMessages([
                'image_file' => 'Ya existe una publicidad con esa marca, campaña y formato.',
            ]);
        }
    }

    /**
     * Renombra un banner existente cuando cambia el nombre de la campaña.
     *
     * No reprocesa la imagen: solamente mueve el WebP dentro de la misma carpeta
     * de banners. La operación se realiza antes de actualizar image_path para que
     * la base nunca quede apuntando a un nombre que todavía no existe físicamente.
     */
    private function renameBannerFile(
        string $currentFilename,
        string $newFilename,
    ): void {
        if ($currentFilename === $newFilename) {
            return;
        }

        /*
         * image_path debe contener únicamente un nombre de archivo. Esta defensa
         * evita que un dato histórico inesperado termine transformándose en una
         * operación sobre otra carpeta.
         */
        if (
            basename($currentFilename) !== $currentFilename
            || basename($newFilename) !== $newFilename
        ) {
            throw ValidationException::withMessages([
                'name' => 'No se pudo actualizar el nombre del archivo del banner.',
            ]);
        }

        $directory = $this->bannerDirectory();
        $sourcePath = $directory.DIRECTORY_SEPARATOR.$currentFilename;
        $destinationPath = $directory.DIRECTORY_SEPARATOR.$newFilename;

        /*
         * Si el archivo que figura actualmente en la base ya no existe, no
         * actualizamos image_path a ciegas porque dejaríamos un banner roto.
         */
        if (! File::exists($sourcePath)) {
            throw ValidationException::withMessages([
                'name' => 'No se encontró el archivo actual del banner para renombrarlo.',
            ]);
        }

        /*
         * ensureBannerFilenameAvailable() ya hizo esta comprobación, pero se
         * repite inmediatamente antes del move para cubrir una posible colisión
         * ocurrida entre ambas operaciones.
         */
        if (File::exists($destinationPath)) {
            throw ValidationException::withMessages([
                'name' => 'Ya existe un banner con el nombre de archivo resultante.',
            ]);
        }

        if (! File::move($sourcePath, $destinationPath)) {
            throw ValidationException::withMessages([
                'name' => 'No se pudo renombrar el archivo del banner.',
            ]);
        }
    }

    /**
     * Revierte un rename físico si el UPDATE de la base de datos falla después.
     *
     * Este método es de recuperación: nunca debe ocultar la excepción original
     * que provocó el rollback. Si no puede devolver el archivo a su nombre previo,
     * registra el problema para poder repararlo sin reemplazar el error principal.
     */
    private function restoreBannerFilename(
        string $renamedFilename,
        string $originalFilename,
    ): void {
        if (
            basename($renamedFilename) !== $renamedFilename
            || basename($originalFilename) !== $originalFilename
        ) {
            TramaLog::error(
                'No se pudo revertir el nombre de un banner por una ruta inválida.',
                [
                    'renamed_filename' => $renamedFilename,
                    'original_filename' => $originalFilename,
                ],
            );

            return;
        }

        $directory = $this->bannerDirectory();
        $renamedPath = $directory.DIRECTORY_SEPARATOR.$renamedFilename;
        $originalPath = $directory.DIRECTORY_SEPARATOR.$originalFilename;

        /*
         * Si el archivo renombrado ya no existe no hay nada que podamos mover.
         */
        if (! File::exists($renamedPath)) {
            TramaLog::error(
                'No se encontró el banner renombrado al intentar revertirlo.',
                [
                    'renamed_filename' => $renamedFilename,
                    'original_filename' => $originalFilename,
                ],
            );

            return;
        }

        /*
         * Nunca sobrescribimos silenciosamente un archivo que haya reaparecido
         * con el nombre original.
         */
        if (File::exists($originalPath)) {
            TramaLog::error(
                'No se revirtió el banner porque el nombre original volvió a existir.',
                [
                    'renamed_filename' => $renamedFilename,
                    'original_filename' => $originalFilename,
                ],
            );

            return;
        }

        if (! File::move($renamedPath, $originalPath)) {
            TramaLog::error(
                'Falló la reversión del nombre físico de un banner.',
                [
                    'renamed_filename' => $renamedFilename,
                    'original_filename' => $originalFilename,
                ],
            );
        }
    }

    /**
     * Pasa el banner por validación, filtro sexual/gore y conversión a WebP.
     */
    private function storeBannerImage(
        string $sourcePath,
        string $filename,
        string $placement,
        bool $overwrite,
    ): string {
        $dimensions = AdvertisementPlacement::dimensions($placement);

        if ($dimensions === null) {
            throw ValidationException::withMessages([
                'placement' => 'La ubicación seleccionada no es válida.',
            ]);
        }

        $destination = $this->bannerDirectory().DIRECTORY_SEPARATOR.$filename;

        try {
            $this->imageProcessor->process(
                sourcePath: $sourcePath,
                destinationPath: $destination,
                width: $dimensions['width'],
                height: $dimensions['height'],
                quality: 82,
                exactSourceDimensions: true,
                overwrite: $overwrite,
            );
        } catch (\RuntimeException $exception) {
            throw ValidationException::withMessages([
                'image_file' => $exception->getMessage(),
            ]);
        }

        return $filename;
    }

    /** Devuelve y crea, si hace falta, la carpeta física de banners. */
    private function bannerDirectory(): string
    {
        $directory = (string) config('trama.advertisements.banner_path');
        File::ensureDirectoryExists($directory);

        return $directory;
    }

    /**
     * Comprueba que el archivo registrado en image_path exista realmente dentro
     * de la carpeta administrada de banners.
     *
     * No intenta buscar archivos parecidos ni adivinar renombres manuales.
     * La base de datos y el archivo físico deben coincidir exactamente.
     *
     * También rechazamos cualquier valor que no sea un basename puro para evitar
     * que un image_path histórico inesperado termine comprobando otra carpeta.
     */
    private function bannerFileExists(?string $filename): bool
    {
        if (
            ! $filename
            || basename($filename) !== $filename
        ) {
            return false;
        }

        $path = $this->bannerDirectory()
            .DIRECTORY_SEPARATOR
            .$filename;

        return File::exists($path);
    }

    /** Elimina un banner exclusivamente dentro de la carpeta configurada. */
    private function deleteBannerFile(?string $filename): void
    {
        if (! $filename || basename($filename) !== $filename) {
            return;
        }

        $path = $this->bannerDirectory().DIRECTORY_SEPARATOR.$filename;

        if (File::exists($path)) {
            File::delete($path);
        }
    }

    /**
     * Las caídas de conexión completas siguen delegándose al 503 global.
     */
    private function isDatabaseUnavailable(Throwable $exception): bool
    {
        return $exception instanceof QueryException
            && str_contains($exception->getMessage(), 'SQLSTATE[HY000] [2002]');
    }
}
