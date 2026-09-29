<?php

/* ============================================================================
 * SUPPORT: TramaClock.php
 * ============================================================================
 *
 * Reloj editorial central de TRAMA.
 *
 * El proyecto de demostración conserva una fecha fija, pero ya no mezcla esa
 * fecha con la hora real del equipo que abre el portal. En su lugar utiliza un
 * reloj editorial desplazado, persistente y monotónico:
 *
 *      TRAMA_REFERENCE_DATE = 2026-07-19
 *      TRAMA_REFERENCE_TIME = 15:30:00
 *
 * La primera sesión comienza en 19/07/2026 15:30:00. Mientras existe actividad
 * web, el reloj avanza la misma cantidad de segundos que el reloj real. Si pasan
 * más de 30 minutos sin actividad, el reloj editorial se pausa. Al volver otro
 * día o después de una pausa larga, continúa un segundo después del último
 * instante editorial utilizado en lugar de regresar a las 15:30.
 *
 * Ejemplo:
 *
 *      Sesión 1:
 *          14:40 real  -> 15:30:00 TRAMA
 *          15:45 real  -> 16:35:00 TRAMA
 *
 *      Se cierra el proyecto y se vuelve al día siguiente a las 10:32.
 *
 *      Sesión 2:
 *          10:32 real  -> 16:35:01 TRAMA
 *          10:37 real  -> 16:40:01 TRAMA
 *
 * De esta manera nunca se crean acciones nuevas con una hora anterior a otras
 * acciones ya realizadas durante una sesión previa.
 * ============================================================================ */

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class TramaClock
{
    private const STATE_TABLE = 'trama_clock_state';

    private const STATE_ID = 1;

    /**
     * Devuelve la fecha y hora actuales del reloj editorial sin prolongar por sí sola la sesión.
     *
     * Las peticiones web llaman a activate() desde un middleware. Los procesos
     * pasivos, como el scheduler, pueden consultar now() sin mantener el reloj
     * despierto indefinidamente cuando nadie está usando TRAMA.
     */
    public static function now(): CarbonImmutable
    {
        // Obtiene la hora real actual en formato Unix para calcular cuánto
        // tiempo transcurrió desde la última actividad registrada por TRAMA.
        $realNowEpoch = self::realNowEpoch();

        // Define el punto inicial del reloj editorial.
        //
        // Ejemplo:
        // TRAMA_REFERENCE_DATE = 2026-07-19
        // hora inicial          = 15:30:00
        //
        // Resultado:
        // 2026-07-19 15:30:00
        $base = self::initial();

        // Si la tabla que conserva el estado del reloj todavía no existe
        // —por ejemplo, antes de ejecutar la migración— se utiliza directamente
        // la fecha y hora editorial inicial.
        if (! self::stateTableExists()) {
            return $base;
        }

        // Recupera el único registro que mantiene el avance persistente
        // del reloj editorial entre distintas peticiones y sesiones.
        $state = DB::table(self::STATE_TABLE)
            ->where('id', self::STATE_ID)
            ->first();

        // Si la tabla existe pero todavía no se creó el estado del reloj,
        // TRAMA continúa utilizando su fecha y hora inicial.
        if ($state === null) {
            return $base;
        }

        // Calcula la fecha y hora editorial que corresponde en este instante
        // tomando como referencia el último estado persistido y el tiempo real
        // transcurrido, sin modificar ni prolongar por sí sola la sesión.
        return self::currentFromState($state, $realNowEpoch, $base);
    }

    /**
     * Registra actividad web y devuelve la fecha y hora editorial correspondiente.
     *
     * Este método es el que abre o continúa una sesión de demostración. La fila
     * se bloquea durante la actualización para impedir que dos peticiones
     * simultáneas hagan retroceder o dupliquen el anclaje del reloj.
     */
    public static function activate(): CarbonImmutable
    {
        // Obtiene la hora real actual en formato Unix.
        // Se utiliza para calcular cuánto tiempo real transcurrió desde el último
        // punto de referencia guardado por el reloj editorial.
        $realNowEpoch = self::realNowEpoch();

        // Obtiene la fecha y hora inicial configurada para la demostración.
        //
        // Ejemplo:
        // 19/07/2026 15:30:00
        //
        // Este valor solamente actúa como punto de partida cuando todavía no existe
        // un estado persistido del reloj.
        $base = self::initial();

        // Si la migración del reloj todavía no fue ejecutada, se utiliza el horario
        // editorial inicial para que la aplicación pueda seguir funcionando.
        if (! self::stateTableExists()) {
            return $base;
        }

        // La lectura y actualización del reloj se realiza dentro de una transacción
        // para evitar inconsistencias cuando dos peticiones llegan al mismo tiempo.
        return DB::transaction(function () use ($realNowEpoch, $base): CarbonImmutable {
            // Recupera el único registro que conserva el estado persistente del reloj.
            //
            // lockForUpdate() bloquea temporalmente esta fila hasta finalizar la
            // transacción, evitando que otra petición modifique el mismo estado
            // mientras se está calculando el nuevo horario editorial.
            $state = DB::table(self::STATE_TABLE)
                ->where('id', self::STATE_ID)
                ->lockForUpdate()
                ->first();

            // Si todavía no existe el estado del reloj, esta es la primera activación.
            // Se guarda como ancla editorial la fecha y hora inicial configurada y
            // como ancla real el instante exacto en que comenzó esta sesión.
            if ($state === null) {
                DB::table(self::STATE_TABLE)->insert([
                    'id' => self::STATE_ID,
                    'editorial_anchor_at' => $base->toDateTimeString(),
                    'real_anchor_epoch' => $realNowEpoch,
                    'last_real_activity_epoch' => $realNowEpoch,
                ]);

                return $base;
            }

            // Obtiene la zona horaria utilizada por TRAMA para interpretar
            // correctamente las fechas almacenadas por el reloj editorial.
            $timezone = self::timezone();

            // Reconstruye la fecha y hora editorial desde la que comenzó
            // el tramo actual de actividad.
            //
            // Ejemplo:
            // editorial_anchor_at = 2026-07-19 16:35:17
            $editorialAnchor = CarbonImmutable::parse(
                (string) $state->editorial_anchor_at,
                $timezone,
            );

            // Instante real Unix asociado al ancla editorial anterior.
            //
            // La diferencia entre este valor y $realNowEpoch permite calcular
            // cuánto debe avanzar el reloj editorial durante una sesión activa.
            $realAnchorEpoch = (int) $state->real_anchor_epoch;

            // Último instante real en el que TRAMA registró actividad.
            //
            // Se utiliza para detectar períodos largos de inactividad y decidir
            // si corresponde continuar la sesión actual o iniciar una nueva
            // conservando la última hora editorial alcanzada.
            $lastRealActivityEpoch = (int) $state->last_real_activity_epoch;

            /*
             * Si la fecha/hora base de configuración cambió deliberadamente,
             * se descarta un estado perteneciente a otra referencia. También se
             * corrige cualquier estado anterior al nuevo mínimo configurado.
             */
            if (
                $editorialAnchor->toDateString() !== $base->toDateString()
                || $editorialAnchor->lt($base)
            ) {
                DB::table(self::STATE_TABLE)
                    ->where('id', self::STATE_ID)
                    ->update([
                        'editorial_anchor_at' => $base->toDateTimeString(),
                        'real_anchor_epoch' => $realNowEpoch,
                        'last_real_activity_epoch' => $realNowEpoch,
                    ]);

                return $base;
            }

            /*
             * El reloj real solo funciona como cronómetro. Si Windows, una VM o
             * la sincronización NTP hicieran retroceder accidentalmente la hora
             * del equipo, ese cambio no puede hacer retroceder a TRAMA.
             *
             * Ejemplo:
             *     última actividad real guardada: 14:52:10
             *     reloj del equipo corregido a:   14:51:40
             *
             * Para el cálculo se conserva 14:52:10 hasta que el reloj real vuelva
             * a superarlo.
             */
            $effectiveRealNowEpoch = max(
                $realNowEpoch,
                $lastRealActivityEpoch,
            );

            $inactivitySeconds = max(
                0,
                $effectiveRealNowEpoch - $lastRealActivityEpoch,
            );

            if ($inactivitySeconds > self::sessionTimeoutSeconds()) {
                /*
                 * Nueva sesión después de una pausa larga.
                 *
                 * Se calcula hasta dónde había llegado realmente la sesión
                 * anterior usando su última actividad conocida. El tiempo que
                 * la PC estuvo apagada o el portal quedó sin uso NO se suma.
                 *
                 * Ejemplo:
                 *     último instante anterior: 16:35:17
                 *     nueva sesión:              16:35:18
                 */
                $previousSessionElapsed = max(
                    0,
                    $lastRealActivityEpoch - $realAnchorEpoch,
                );

                $newEditorialAnchor = self::clamp(
                    $editorialAnchor
                        ->addSeconds($previousSessionElapsed)
                        ->addSecond(),
                    $base,
                );

                DB::table(self::STATE_TABLE)
                    ->where('id', self::STATE_ID)
                    ->update([
                        'editorial_anchor_at' => $newEditorialAnchor->toDateTimeString(),
                        'real_anchor_epoch' => $effectiveRealNowEpoch,
                        'last_real_activity_epoch' => $effectiveRealNowEpoch,
                    ]);

                return $newEditorialAnchor;
            }

            // Misma sesión: solamente se actualiza la última actividad real.
            // El anclaje se conserva para que los segundos transcurran de forma natural.
            DB::table(self::STATE_TABLE)
                ->where('id', self::STATE_ID)
                ->update([
                    'last_real_activity_epoch' => $effectiveRealNowEpoch,
                ]);

            return self::clamp(
                $editorialAnchor->addSeconds(
                    max(
                        0,
                        $effectiveRealNowEpoch - $realAnchorEpoch,
                    )
                ),
                $base,
            );
        }, attempts: 3);
    }

    /**
     * Registra el final de una petición web sin abrir una sesión nueva.
     *
     * activate() marca el comienzo de la request y este método marca su final.
     * Es importante porque una operación puede tardar algunos segundos entre
     * entrar al controller y terminar de guardar todos sus registros.
     *
     * Ejemplo:
     *     request comienza -> 15:30:00
     *     guarda una revisión 4 segundos después -> 15:30:04
     *     request termina -> 15:30:05
     *
     * La próxima sesión debe continuar después de 15:30:05 y nunca volver a
     * 15:30:01. Los procesos de fondo no llaman a este método, por lo que una
     * consulta pasiva del scheduler sigue sin mantener despierto el reloj.
     */
    public static function finishWebRequest(): void
    {
        if (! self::stateTableExists()) {
            return;
        }

        $realNowEpoch = self::realNowEpoch();

        DB::transaction(function () use ($realNowEpoch): void {
            $state = DB::table(self::STATE_TABLE)
                ->where('id', self::STATE_ID)
                ->lockForUpdate()
                ->first();

            if ($state === null) {
                return;
            }

            $lastRealActivityEpoch = (int) $state->last_real_activity_epoch;

            // max() evita retroceder si el reloj del sistema fue corregido
            // mientras la petición estaba en curso o hay requests concurrentes.
            DB::table(self::STATE_TABLE)
                ->where('id', self::STATE_ID)
                ->update([
                    'last_real_activity_epoch' => max(
                        $realNowEpoch,
                        $lastRealActivityEpoch,
                    ),
                ]);
        }, attempts: 3);
    }

    /**
     * Fecha y hora inicial configurada para la demostración.
     *
     * No consulta ni modifica el estado persistente. Resulta útil en seeders y
     * migraciones que necesitan datos deterministas alrededor de la fecha demo.
     */
    public static function initial(): CarbonImmutable
    {
        // Obtiene la fecha de referencia configurada para la demostración.
        //
        // Ejemplo:
        // 2026-07-19
        $date = (string) config('trama.reference_date', '2026-07-19');

        // Obtiene la hora inicial desde la que comienza el reloj editorial.
        //
        // Ejemplo:
        // 15:30:00
        $time = (string) config('trama.reference_time', '15:30:00');

        // Une la fecha y la hora configuradas, las interpreta con la zona horaria
        // de TRAMA y elimina cualquier fracción de segundo para obtener un punto
        // de partida exacto.
        //
        // Ejemplo:
        // 2026-07-19 + 15:30:00
        // → 2026-07-19 15:30:00
        return CarbonImmutable::parse(
            "{$date} {$time}",
            self::timezone(),
        )->startOfSecond();
    }

    /** Devuelve el día editorial configurado, sin depender del día real. */
    public static function referenceDate(): CarbonImmutable
    {
        return self::initial()->startOfDay();
    }

    /** Devuelve el día correspondiente al reloj editorial actual. */
    public static function today(): CarbonImmutable
    {
        return self::now()->startOfDay();
    }

    /**
     * Calcula la fecha y hora visible a partir del estado persistente del reloj.
     *
     * Cuando la última actividad superó el umbral de sesión, el reloj queda
     * detenido en la última hora editorial conocida. activate() será quien abra
     * la siguiente sesión cuando llegue una nueva petición web.
     */
    private static function currentFromState(
        object $state,
        int $realNowEpoch,
        CarbonImmutable $base,
    ): CarbonImmutable {
        // Obtiene la zona horaria configurada para interpretar correctamente
        // las fechas almacenadas por el reloj editorial.
        $timezone = self::timezone();

        // Reconstruye la fecha y hora editorial desde la que comenzó
        // el tramo actual de actividad.
        //
        // Ejemplo:
        // 2026-07-19 16:35:17
        $editorialAnchor = CarbonImmutable::parse(
            (string) $state->editorial_anchor_at,
            $timezone,
        );

        // Recupera la hora real, expresada como timestamp Unix, que estaba
        // asociada al comienzo del tramo editorial anterior.
        $realAnchorEpoch = (int) $state->real_anchor_epoch;

        // Recupera la última hora real en la que TRAMA registró actividad.
        // Este valor permite determinar si la sesión continúa activa o si
        // ya superó el período máximo de inactividad.
        $lastRealActivityEpoch = (int) $state->last_real_activity_epoch;

        // Si el estado guardado pertenece a otra fecha editorial o quedó
        // accidentalmente antes del horario inicial configurado, se descarta
        // y se vuelve al punto de partida definido para la demostración.
        if (
            $editorialAnchor->toDateString() !== $base->toDateString()
            || $editorialAnchor->lt($base)
        ) {
            return $base;
        }

        // Evita que una corrección hacia atrás del reloj del sistema haga
        // retroceder también el reloj editorial de TRAMA.
        //
        // Siempre se utiliza como mínimo la última hora real registrada.
        $effectiveRealNowEpoch = max(
            $realNowEpoch,
            $lastRealActivityEpoch,
        );

        // Calcula cuántos segundos reales pasaron desde la última actividad.
        //
        // El max() evita obtener valores negativos si el reloj del sistema
        // sufrió algún ajuste inesperado.
        $inactivitySeconds = max(
            0,
            $effectiveRealNowEpoch - $lastRealActivityEpoch,
        );

        // Si la inactividad supera el límite permitido, el reloj editorial
        // queda detenido exactamente en la última actividad conocida.
        //
        // Si la sesión sigue activa, se utiliza la hora real actual para
        // continuar avanzando normalmente.
        $effectiveRealTimeEpoch = $inactivitySeconds > self::sessionTimeoutSeconds()
            ? $lastRealActivityEpoch
            : $effectiveRealNowEpoch;

        // Calcula cuántos segundos deben sumarse desde el ancla editorial
        // para obtener la fecha y hora TRAMA correspondiente.
        $elapsedSeconds = max(
            0,
            $effectiveRealTimeEpoch - $realAnchorEpoch,
        );

        // Avanza el reloj editorial según el tiempo real transcurrido y aplica
        // los límites definidos por TRAMA para impedir valores anteriores al
        // inicio de la demo o posteriores al final permitido del mismo día.
        return self::clamp(
            $editorialAnchor->addSeconds($elapsedSeconds),
            $base,
        );
    }

    /**
     * Mantiene todos los timestamps dentro del mismo 19/07 configurado.
     *
     * Si una sesión extraordinariamente larga alcanzara la medianoche, TRAMA se
     * queda en 23:59:59 en lugar de saltar al día siguiente y romper la premisa
     * de fecha fija del portfolio.
     */
    private static function clamp(
        CarbonImmutable $value,
        CarbonImmutable $base,
    ): CarbonImmutable {
        // Impide que el reloj editorial devuelva una fecha y hora anterior
        // al punto inicial configurado para la demostración.
        //
        // Ejemplo:
        // base  = 2026-07-19 15:30:00
        // value = 2026-07-19 15:20:00
        //
        // Resultado:
        // 2026-07-19 15:30:00
        if ($value->lt($base)) {
            return $base;
        }

        // Obtiene el último segundo válido del día de referencia.
        //
        // De esta forma, aunque la actividad acumulada supere varias horas,
        // TRAMA permanece dentro del 19/07/2026 y nunca avanza al día siguiente.
        //
        // Ejemplo:
        // 2026-07-19 23:59:59
        $endOfReferenceDay = $base->endOfDay()->startOfSecond();

        // Si el valor calculado supera el final del día de referencia,
        // se limita a las 23:59:59.
        //
        // En caso contrario, se conserva la fecha y hora calculada eliminando
        // cualquier fracción de segundo.
        return $value->gt($endOfReferenceDay)
            ? $endOfReferenceDay
            : $value->startOfSecond();
    }

    /**
     * Devuelve el tiempo máximo de inactividad permitido para una sesión
     * del reloj editorial, expresado en segundos.
     *
     * El valor se configura en minutos y se convierte a segundos porque
     * TramaClock compara la actividad mediante timestamps Unix.
     *
     * Ejemplo:
     * 30 minutos → 1800 segundos.
     */
    private static function sessionTimeoutSeconds(): int
    {
        // Obtiene desde la configuración cuántos minutos de inactividad deben
        // transcurrir para considerar finalizada una sesión del reloj editorial.
        //
        // Ejemplo:
        // clock_session_timeout_minutes = 30
        // → después de 30 minutos sin actividad, el reloj queda pausado.
        $timeoutMinutes = max(
            1,
            (int) config('trama.clock_session_timeout_minutes', 30),
        );

        // Convierte los minutos configurados a segundos porque los tiempos reales
        // utilizados por TramaClock se comparan mediante timestamps Unix.
        return $timeoutMinutes * 60;
    }

    /**
     * Devuelve la hora real actual como timestamp Unix.
     *
     * Esta referencia se utiliza únicamente como cronómetro técnico para medir
     * el tiempo transcurrido entre actividades del reloj editorial. La fecha real
     * del sistema no se utiliza como fecha visible ni se mezcla con los timestamps
     * editoriales de TRAMA.
     */
    private static function realNowEpoch(): int
    {
        /*
         * Esta es la única referencia al tiempo real que necesita el reloj.
         * Se convierte enseguida a Unix epoch (segundos) y se usa solamente como
         * cronómetro técnico; no se persiste la fecha real 10/08/2026, 11/08/2026,
         * etc. dentro de los timestamps editoriales del portal.
         */
        return CarbonImmutable::now(self::timezone())
            ->startOfSecond()
            ->getTimestamp();
    }

    /**
     * Devuelve la zona horaria utilizada por el reloj editorial de TRAMA.
     *
     * Utiliza la zona horaria configurada por Laravel y, si no está disponible,
     * toma Buenos Aires como valor predeterminado.
     */
    private static function timezone(): string
    {
        return (string) config(
            'app.timezone',
            'America/Argentina/Buenos_Aires',
        );
    }

    /**
     * Comprueba si existe la tabla que conserva el estado persistente
     * del reloj editorial.
     *
     * Permite que TramaClock pueda seguir funcionando con su fecha y hora
     * inicial incluso antes de ejecutar la migración que crea dicha tabla.
     */
    private static function stateTableExists(): bool
    {
        try {
            return Schema::hasTable(self::STATE_TABLE);
        } catch (Throwable) {
            // Durante una instalación o una base todavía no disponible se usa
            // el momento inicial. La excepción real de conexión será manejada
            // por la operación que efectivamente necesite acceder a la base.
            return false;
        }
    }
}
