<?php

/* ============================================================================
 * CONTROLLER: AdvertiserDemoController.php
 * ============================================================================
 *
 * Muestra las páginas de demostración de los anunciantes ficticios de TRAMA.
 *
 * Estas páginas se renderizan directamente desde Laravel mediante una vista
 * Blade independiente. No pasan por Inertia, Trama.vue ni el layout público,
 * porque representan sitios externos ficticios abiertos desde los banners.
 *
 * De esta forma el navegador recibe directamente el contenido del anunciante y
 * no muestra previamente el fondo de TRAMA mientras Vue termina de iniciar.
 * ============================================================================
 */

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use Illuminate\View\View;

class AdvertiserDemoController extends Controller
{
    /**
     * Devuelve la página correspondiente al anunciante solicitado.
     *
     * Cada slug disponible representa una marca ficticia utilizada por los
     * banners de demostración del proyecto.
     */
    public function __invoke(string $advertiser): View
    {
        // Información disponible para las marcas ficticias utilizadas por TRAMA.
        $brands = [
            'banco-nexo' => [
                'name' => 'Banco NEXO',
                'eyebrow' => 'Cuenta digital',
                'headline' => 'Tu plata, más clara.',
                'description' => 'Una experiencia financiera digital pensada para administrar fondos, dólar MEP y rendimiento diario desde un solo lugar.',
                'benefits' => [
                    'Cuenta sin mantenimiento',
                    'Fondos y dólar MEP',
                    'Rendimiento diario',
                ],
                'image' => '/images/banners/nexo-nexo-header-1456x180.webp',
                'cta' => 'Abrí tu cuenta',
            ],

            'nocturne' => [
                'name' => 'Nocturne',
                'eyebrow' => 'Eau de parfum',
                'headline' => 'Una fragancia que permanece.',
                'description' => 'Una colección nocturna de carácter profundo, diseñada como una pieza editorial de perfumería contemporánea.',
                'benefits' => [
                    'Nueva colección',
                    'Perfil amaderado',
                    'Edición de demostración',
                ],
                'image' => '/images/banners/nocturne-nocturne-header-1456x180.webp',
                'cta' => 'Conocer la colección',
            ],

            'lumen-air' => [
                'name' => 'Lumen Air',
                'eyebrow' => 'Notebook ultraliviana',
                'headline' => 'Potencia para trabajar. Ligereza para llevar.',
                'description' => 'Una notebook conceptual para profesionales que combina autonomía, bajo peso y pantalla OLED.',
                'benefits' => [
                    '14 horas de batería',
                    '1,1 kg',
                    'Pantalla OLED',
                ],
                'image' => '/images/banners/lumen-air-lumen-air-header-1456x180.webp',
                'cta' => 'Conocer Lumen Air',
            ],

            'universidad-horizonte' => [
                'name' => 'Universidad Horizonte',
                'eyebrow' => 'Educación online',
                'headline' => 'Estudiá a tu ritmo.',
                'description' => 'Carreras y programas online con una propuesta académica flexible para formación profesional continua.',
                'benefits' => [
                    'Diplomatura en Comunicación Digital',
                    'Modalidad online',
                    'Inscripciones abiertas',
                ],
                'image' => '/images/banners/horizonte-horizonte-header-1456x180.webp',
                'cta' => 'Conocer el programa',
            ],

            'cafe-senda' => [
                'name' => 'Café Senda',
                'eyebrow' => 'Palermo · Buenos Aires',
                'headline' => 'Café de especialidad. Libros para quedarse.',
                'description' => 'Un espacio ficticio de barrio pensado alrededor del café de especialidad, la lectura y una pausa sin apuro.',
                'benefits' => [
                    'Café de especialidad',
                    'Selección de libros',
                    'Rincón de lectura',
                ],
                'image' => '/images/banners/senda-senda-header-1456x180.webp',
                'cta' => 'Conocer Senda',
            ],
        ];

        // Si el slug no pertenece a uno de los anunciantes conocidos, Laravel
        // responde con la página 404 normal del proyecto.
        abort_unless(isset($brands[$advertiser]), 404);

        // Se utiliza una vista Blade independiente para evitar pasar por Inertia
        // y Vue antes de mostrar la página del anunciante.
        return view('advertiser-demo', [
            'advertiser' => $brands[$advertiser],
        ]);
    }
}