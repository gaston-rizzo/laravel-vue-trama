<?php

/* ============================================================================
 * MIDDLEWARE: HandleInertiaRequests.php
 * ============================================================================
 *
 * Middleware principal de Inertia para TRAMA.
 *
 * Define la vista Blade raíz y comparte datos globales con todas las pantallas
 * Vue: usuario autenticado, categorías activas, banners del header y mensajes
 * flash. En Laravel, un mensaje flash es un texto guardado en sesión por una
 * sola petición, por ejemplo "Cuenta creada" o "Cambios guardados"; después de
 * mostrarse en la siguiente pantalla, desaparece automáticamente.
 * ============================================================================ */

namespace App\Http\Middleware;

use App\Support\TramaClock;

use App\Http\Resources\AdvertisementResource;
use App\Models\Category;
use App\Services\AdvertisementSelectorService;
use App\Support\AdvertisementPlacement;
use Closure;
use Illuminate\Http\Request;
use Inertia\Middleware;
use Tighten\Ziggy\Ziggy;

class HandleInertiaRequests extends Middleware
{
    /**
     * Vista Blade que carga la aplicación Vue/Inertia.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Limita SSR a la portada pública.
     *
     * INERTIA_SSR_ENABLED funciona como interruptor maestro del entorno, pero
     * TRAMA solo envía al servidor Node la ruta nombrada "home". Todas las
     * demás pantallas continúan usando Inertia/Vue normal en el navegador.
     */
    public function handle(Request $request, Closure $next)
    {
        $ssrEnabled = (bool) config('trama.ssr.enabled', true);
        $ssrRoute = (string) config('trama.ssr.route', 'home');

        config([
            'inertia.ssr.enabled' => $ssrEnabled && $request->routeIs($ssrRoute),
        ]);

        return parent::handle($request, $next);
    }

    /**
     * Devuelve la versión actual de assets utilizada por Inertia.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Comparte propiedades globales disponibles en todas las pantallas.
     *
     * Las categorías, mensajes y banners se resuelven de forma diferida para
     * evitar consultas cuando una respuesta no necesita serializar esas props.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'auth' => [
                'user' => $request->user() ? [
                    'id' => $request->user()->id,
                    'name' => $request->user()->name,
                    'email' => $request->user()->email,
                    'role' => $request->user()->role,
                    'avatar' => $request->user()->avatar,
                    'avatar_url' => $request->user()->avatarUrl(),
                    'can_access_editorial' => $request->user()->canAccessEditorial(),
                ] : null,
            ],
            'flash' => [
                'status' => fn () => $request->session()->get('status'),
                'account_blocked' => fn () => $request->session()->get('account_blocked'),
                /*
                 * Identifica el comentario que acaba de entrar al pipeline automatico.
                 * Vue lo usa para consultar ese resultado exacto y no depender de
                 * estados anteriores de la misma noticia.
                 */
                'automatic_comment_id' => fn () => $request->session()->get('automatic_comment_id'),
            ],
            'site' => fn () => $this->siteInformation(),
            'navCategories' => fn () => Category::query()
                // El menú público excluye categorías activas que todavía no tienen noticias publicadas.
                ->publiclyVisible()
                // Primero orden configurado; después nombre para desempatar.
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(['name', 'slug', 'accent_color']),
            'advertisements' => [
                'header' => fn () => $this->advertisementsFor(AdvertisementPlacement::HEADER),
            ],
            'ziggy' => fn () => [
                // Ziggy entrega a Vue la lista de rutas nombradas de Laravel.
                // En navegador también existe @routes; el SSR de la portada corre
                // en Node y necesita esta información dentro de las props Inertia.
                ...(new Ziggy)->toArray(),
                // La URL actual permite que route().current() y rutas relativas
                // se calculen igual en servidor que en navegador.
                'location' => $request->url(),
            ],
        ];
    }

    private function siteInformation(): array
    {
        /*
         * La fecha y la hora compartidas nacen del mismo TramaClock. El frontend
         * puede usarlas para textos relativos sin recurrir a Date.now(), que
         * pertenece al día real del navegador.
         */
        $editorialNow = TramaClock::now()->locale('es');

        return [
            'reference_date' => $editorialNow->toDateString(),
            'editorial_now' => $editorialNow->toISOString(),
            'edition_date_label' => mb_strtoupper(
                $editorialNow->isoFormat('dddd D [de] MMMM [de] YYYY'),
                'UTF-8'
            ),
            'location' => 'BUENOS AIRES',
        ];
    }

    /**
     * Devuelve los banners activos para una ubicación compartida del layout.
     *
     * El header recibe la lista completa de publicidades vigentes. La rotación
     * entre banners queda en Vue para que no dependa de una selección nueva del
     * backend cuando el usuario navega entre secciones.
     *
     * @return array<int, array<string, mixed>>
     */
    private function advertisementsFor(string $placement): array
    {
        $advertisements = app(AdvertisementSelectorService::class)->getActiveAdvertisementsForPlacement($placement);

        return AdvertisementResource::collection($advertisements)->resolve();
    }
}
