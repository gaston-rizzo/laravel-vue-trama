<?php

/* ============================================================================
 * SERVICE: AdvertisementSelectorService.php
 * ============================================================================
 *
 * Obtiene la publicidad que debe mostrarse en cada espacio del sitio.
 *
 * Recibe una ubicación concreta, como header o sidebar de una noticia, y busca
 * banners activos para el momento exacto que devuelve el reloj editorial de TRAMA.
 *
 * Para espacios que muestran una sola pieza, como la sidebar de una noticia,
 * devuelve un banner activo. Para el header puede devolver la lista completa de
 * banners activos, porque esa rotación la maneja Vue sin pedir una pantalla
 * nueva a Laravel.
 *
 * Importante: este servicio no suma métricas. Entregar banners al frontend no
 * significa que el usuario haya hecho click ni que cada banner de una lista haya
 * sido visto. El conteo de clicks se debe registrar solamente cuando el usuario
 * presiona una publicidad.
 * ============================================================================ */

namespace App\Services;

use App\Support\TramaClock;

use App\Models\Advertisement;

use App\Support\AdvertisementPlacement;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;

class AdvertisementSelectorService
{
    /**
     * Obtiene una publicidad activa para una ubicación del sitio.
     *
     * La ubicación puede ser el banner del header o alguno de los espacios
     * laterales de una noticia. Si la ubicación no existe, devuelve null para
     * que Vue no intente mostrar un anuncio inválido.
     *
     * Seleccionar el banner no modifica advertisement_daily_metrics.
     */
    public function getActiveAdvertisementForPlacement(string $placement): ?Advertisement
    {
        // Antes de consultar la base se comprueba que la ubicación exista en
        // AdvertisementPlacement. Así evitamos buscar banners con textos mal
        // escritos o posiciones que el sitio no tiene definidas.
        if (! in_array($placement, AdvertisementPlacement::values(), true)) {
            return null;
        }

        // TRAMA usa el instante editorial completo para decidir vigencia.
        // Ejemplo: una campaña que empieza a las 18:00 sigue programada si
        // TramaClock se encuentra en 19/07/2026 15:30.
        $editorialNow = TramaClock::now();

        /*
         * Primero se obtienen las campañas que cumplen las reglas de negocio:
         * ubicación, estado activo y vigencia editorial.
         *
         * Después se descartan las que ya no tengan su archivo físico. Esto es
         * importante porque image_path puede seguir existiendo en la base aunque
         * alguien haya eliminado o renombrado manualmente el WebP.
         *
         * El orden aleatorio se aplica antes del filtro físico. De esta manera, si
         * el primer banner perdió su archivo, se continúa buscando entre los demás
         * banners válidos de la misma ubicación en lugar de devolver null.
         */
        return Advertisement::query()
            // Busca solamente banners cargados para la ubicación solicitada.
            ->where('placement', $placement)
            // Toma únicamente banners marcados como activos en la base de datos.
            ->where('is_active', true)
            // La campaña debe haber comenzado para la fecha Y hora editorial actuales.
            ->where('starts_at', '<=', $editorialNow)
            // La campaña debe continuar vigente en ese mismo instante editorial.
            ->where('ends_at', '>=', $editorialNow)
            // Cambia el orden al recargar antes de descartar archivos inexistentes.
            ->inRandomOrder()
            ->get()
            // Si el primer banner no existe físicamente, continúa con los demás.
            ->first(
                fn (Advertisement $advertisement): bool =>
                    $this->bannerFileExists($advertisement)
            );
    }

    /**
     * Obtiene todas las publicidades activas de una ubicación.
     *
     * Este método se usa para el header: Laravel entrega la lista completa de
     * banners vigentes y Vue rota entre ellos cada algunos segundos. Así el
     * header no depende de una selección nueva del backend cada vez que el
     * usuario navega a otra sección.
     *
     * Devolver la lista completa no suma impresiones ni clicks, porque esos datos
     * deben depender de una acción real del usuario o de una medición específica.
     *
     * @return Collection<int, Advertisement>
     */
    public function getActiveAdvertisementsForPlacement(string $placement): Collection
    {
        // Si la ubicación no pertenece al sistema de banners, se devuelve una
        // colección vacía para que Vue no reciba datos inválidos.
        if (! in_array($placement, AdvertisementPlacement::values(), true)) {
            return collect();
        }

        // Instante editorial completo usado para decidir qué campañas están vigentes.
        $editorialNow = TramaClock::now();

        /*
         * El header recibe únicamente campañas cuyo archivo físico todavía existe.
         * Si un WebP desapareció manualmente, la campaña sigue visible como dañada
         * en Administración pero deja de participar de la rotación pública hasta
         * que se cargue nuevamente una imagen válida.
         */
        return Advertisement::query()
            // Filtra los banners cargados para el espacio pedido, por ejemplo header.
            ->where('placement', $placement)
            // Excluye campañas apagadas manualmente desde la base o el panel.
            ->where('is_active', true)
            // Incluye campañas que ya empezaron para la fecha y hora editorial actuales.
            ->where('starts_at', '<=', $editorialNow)
            // Incluye campañas que todavía no terminaron en ese mismo instante.
            ->where('ends_at', '>=', $editorialNow)
            // Orden estable para que Vue conserve la rotación aunque cambie la pantalla.
            ->orderBy('id')
            ->get()
            // Los banners sin archivo físico no participan de la rotación pública.
            ->filter(
                fn (Advertisement $advertisement): bool =>
                    $this->bannerFileExists($advertisement)
            )
            // filter() conserva las claves originales; Vue necesita un array limpio.
            ->values();
    }

    /**
     * Obtiene publicidades activas para varias ubicaciones en una sola llamada.
     *
     * Devuelve una colección asociativa donde la clave es la ubicación pedida y
     * el valor es el banner elegido o null. Esto permite que un controller pida
     * sidebar superior e inferior sin repetir la misma estructura de respuesta.
     *
     * @param list<string> $placements
     * @return Collection<string, Advertisement|null>
     */
    public function getActiveAdvertisementsForPlacements(array $placements): Collection
    {
        return collect($placements)
            // unique evita repetir consultas si una pantalla pide la misma ubicación dos veces.
            ->unique()
            // mapWithKeys permite acceder luego por nombre de ubicación: header, sidebar superior, sidebar inferior.
            ->mapWithKeys(fn (string $placement) => [
                $placement => $this->getActiveAdvertisementForPlacement($placement),
            ]);
    }

    /**
     * Comprueba que el archivo físico asociado a una publicidad continúe
     * existiendo dentro de la carpeta oficial de banners de TRAMA.
     *
     * No busca nombres parecidos ni intenta resolver renombres manuales:
     * image_path debe coincidir exactamente con el archivo físico.
     */
    private function bannerFileExists(Advertisement $advertisement): bool
    {
        // image_path debe contener únicamente el nombre físico del banner.
        $filename = (string) $advertisement->image_path;

        /*
         * Un nombre vacío o una ruta con subdirectorios se considera inválido.
         * basename() debe devolver exactamente el mismo valor para garantizar
         * que la comprobación quede limitada a la carpeta oficial de banners.
         */
        if (
            $filename === ''
            || basename($filename) !== $filename
        ) {
            return false;
        }

        // Obtiene la carpeta física configurada para almacenar los banners.
        $directory = (string) config(
            'trama.advertisements.banner_path'
        );

        // Sin una carpeta configurada no existe una ubicación válida para buscar.
        if ($directory === '') {
            return false;
        }

        // La coincidencia debe ser exacta con el nombre registrado en image_path.
        return File::exists(
            $directory
            .DIRECTORY_SEPARATOR
            .$filename
        );
    }

}
