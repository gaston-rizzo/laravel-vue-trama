<?php

/* ============================================================================
 * SERVICE: ArticleImageSafetyPolicy.php
 * ============================================================================
 *
 * Aplica las reglas de seguridad definidas por TRAMA sobre el resultado
 * producido por ArticleImageSafetyClassifier.
 *
 * El clasificador calcula probabilidades. Esta clase utiliza esas
 * probabilidades para decidir si una portada debe aceptarse o bloquearse.
 * ============================================================================ */

namespace App\Services\Images;

class ArticleImageSafetyPolicy
{
    /**
     * Determina si una portada debe bloquearse.
     *
     * @param  array{
     *     primary: string,
     *     probabilities: array{
     *         NSFL: float,
     *         NSFW: float,
     *         SFW: float
     *     }
     * } $classification
     *
     * @return array{
     *     blocked: bool,
     *     blocked_by: array<int, string>
     * }
     */
    public function evaluate(array $classification): array
    {
        $probabilities = $classification['probabilities'];
        
        // Obtiene desde config/trama.php el porcentaje mínimo de contenido
        // sexual necesario para rechazar la portada.
        $nsfwThreshold = (float) config(
            'trama.articles.image_safety.nsfw_block_threshold'
        );

        // Obtiene desde config/trama.php el porcentaje mínimo de contenido 
        // gráfico extremo necesario para rechazar la portada.        
        $nsflThreshold = (float) config(
            'trama.articles.image_safety.nsfl_block_threshold'
        );

        $blockedBy = [];

        /*
         * Registra cada categoría que alcanza o supera el porcentaje mínimo
         * configurado para el bloqueo automático.
         */
        if ($probabilities['NSFW'] >= $nsfwThreshold) {
            $blockedBy[] = 'NSFW';
        }

        if ($probabilities['NSFL'] >= $nsflThreshold) {
            $blockedBy[] = 'NSFL';
        }

        /*
        * Devuelve la decisión final de la evaluación.
        *
        * Ejemplo de portada permitida:
        *
        * [
        *     'blocked' => false,
        *     'blocked_by' => [],
        * ]
        *
        * Ejemplo de portada rechazada por contenido sexual:
        *
        * [
        *     'blocked' => true,
        *     'blocked_by' => ['NSFW'],
        * ]
        *
        * blocked será true cuando al menos una categoría haya alcanzado el porcentaje
        * mínimo de bloqueo. blocked_by indica qué categorías causaron el rechazo.
        */
        return [
            'blocked' => $blockedBy !== [],
            'blocked_by' => $blockedBy,
        ];
    }
}