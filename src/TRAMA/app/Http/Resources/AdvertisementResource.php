<?php

/* ============================================================================
 * RESOURCE: AdvertisementResource.php
 * ============================================================================
 *
 * Prepara una publicidad para enviarla a Vue.
 *
 * Oculta columnas internas y entrega solamente los datos que necesita el
 * frontend: identificador, marca, ubicación, imagen pública, destino configurado
 * y URL interna de medición. Así las pantallas no tienen que conocer cómo se
 * guarda el archivo en la base ni cómo se arma la URL pública del banner.
 * ============================================================================ */

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdvertisementResource extends JsonResource
{
    /**
     * Arma el arreglo que Vue usará para pintar un banner.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            // Identificador interno del banner.
            'id' => $this->id,

            // Nombre administrativo de la pieza.
            'name' => $this->name,

            // Marca anunciante.
            'brand' => $this->brand,

            // Ubicación donde Vue debe renderizarlo.
            'placement' => $this->placement,

            // URL pública ya construida desde el modelo.
            'image_url' => $this->imageUrl(),

            // Destino que el navegador abre directamente al hacer click.
            'target_url' => $this->target_url,

            // Endpoint interno llamado en segundo plano para registrar el click.
            'click_url' => route('advertisements.click', $this->id),

            // Endpoint interno llamado cuando el banner se ve en pantalla.
            'impression_url' => route('advertisements.impression', $this->id),
        ];
    }
}
