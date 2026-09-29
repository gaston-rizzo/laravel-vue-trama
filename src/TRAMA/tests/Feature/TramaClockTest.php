<?php

/* ============================================================================
 * TEST: TramaClockTest.php
 * ============================================================================
 *
 * Comprueba la cronología ficticia utilizada por la demo de TRAMA.
 *
 * El reloj debe cumplir tres reglas centrales:
 *
 * 1. La primera sesión comienza en la fecha y hora configuradas.
 * 2. Mientras la sesión está activa avanza exactamente con el tiempo real.
 * 3. Después de una pausa larga reanuda desde el último instante editorial,
 *    sin sumar las horas que el proyecto estuvo cerrado y sin retroceder.
 * ============================================================================ */

namespace Tests\Feature;

use App\Support\TramaClock;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TramaClockTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        // Evita que el reloj real simulado de una prueba afecte a las siguientes.
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_first_session_combines_reference_date_and_time(): void
    {
        $this->freezeRealClockAt('2026-08-10 14:40:00');

        $editorialNow = TramaClock::activate();

        $this->assertSame(
            '2026-07-19 15:30:00',
            $editorialNow->format('Y-m-d H:i:s'),
        );
    }

    public function test_active_session_advances_with_real_elapsed_time(): void
    {
        $this->freezeRealClockAt('2026-08-10 14:40:00');
        TramaClock::activate();

        // Cinco minutos reales después deben ser cinco minutos editoriales después.
        $this->freezeRealClockAt('2026-08-10 14:45:00');

        $this->assertSame(
            '2026-07-19 15:35:00',
            TramaClock::activate()->format('Y-m-d H:i:s'),
        );
    }

    public function test_new_day_resumes_after_last_editorial_time_without_adding_the_night(): void
    {
        $this->freezeRealClockAt('2026-08-10 14:40:00');
        TramaClock::activate();

        // La primera sesión llega hasta las 15:35 editoriales.
        $this->freezeRealClockAt('2026-08-10 14:45:00');
        TramaClock::activate();

        /*
         * Al día siguiente pasaron muchas horas reales, pero la demo estuvo
         * pausada. Por eso no vuelve a 15:30 ni suma toda la noche: reanuda un
         * segundo después de 15:35:00.
         */
        $this->freezeRealClockAt('2026-08-11 10:32:00');

        $this->assertSame(
            '2026-07-19 15:35:01',
            TramaClock::activate()->format('Y-m-d H:i:s'),
        );

        // Cinco minutos reales dentro de la nueva sesión vuelven a sumarse normalmente.
        $this->freezeRealClockAt('2026-08-11 10:37:00');

        $this->assertSame(
            '2026-07-19 15:40:01',
            TramaClock::activate()->format('Y-m-d H:i:s'),
        );
    }

    public function test_request_end_prevents_a_slow_request_from_making_the_next_session_go_backwards(): void
    {
        $this->freezeRealClockAt('2026-08-10 14:40:00');
        TramaClock::activate();

        // Durante la misma request un registro puede guardarse algunos segundos después.
        $this->freezeRealClockAt('2026-08-10 14:40:04');
        $this->assertSame(
            '2026-07-19 15:30:04',
            TramaClock::now()->format('Y-m-d H:i:s'),
        );

        // El middleware registra el final real de esa misma request.
        $this->freezeRealClockAt('2026-08-10 14:40:05');
        TramaClock::finishWebRequest();

        $this->freezeRealClockAt('2026-08-11 10:32:00');

        $this->assertSame(
            '2026-07-19 15:30:06',
            TramaClock::activate()->format('Y-m-d H:i:s'),
        );
    }

    public function test_passive_read_does_not_restart_an_inactive_session(): void
    {
        $this->freezeRealClockAt('2026-08-10 14:40:00');
        TramaClock::activate();

        $this->freezeRealClockAt('2026-08-10 14:45:00');
        TramaClock::activate();

        /*
         * El scheduler puede consultar now() horas después. Esa lectura debe
         * conservar 15:35:00 y no abrir una sesión nueva por sí sola.
         */
        $this->freezeRealClockAt('2026-08-11 10:32:00');

        $this->assertSame(
            '2026-07-19 15:35:00',
            TramaClock::now()->format('Y-m-d H:i:s'),
        );
    }

    private function freezeRealClockAt(string $value): void
    {
        CarbonImmutable::setTestNow(
            CarbonImmutable::parse(
                $value,
                (string) config('app.timezone'),
            ),
        );
    }
}
