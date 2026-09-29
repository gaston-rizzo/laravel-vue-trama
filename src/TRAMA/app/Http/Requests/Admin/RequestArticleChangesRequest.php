<?php

/* ============================================================================
 * REQUEST: RequestArticleChangesRequest.php
 * ============================================================================
 *
 * Valida la observación usada para devolver una noticia.
 *
 * Exige una explicación concreta para que el periodista sepa qué debe corregir
 * y para que la transición quede documentada en el historial editorial.
 * ============================================================================ */

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class RequestArticleChangesRequest extends FormRequest
{
    /**
     * Autoriza la devolución de una noticia para que su autor
     * realice las correcciones indicadas.
     *
     * Solo los editores pueden solicitar correcciones.
     */
    public function authorize(): bool
    {
        // Obtiene el usuario autenticado que intenta devolver la noticia.
        $user = $this->user();

        // Rechaza la acción si no existe un usuario autenticado
        // o si el usuario no tiene permisos de revisión editorial.
        return $user !== null
            && $user->canReviewArticles();
    }

    /**
     * Normaliza los saltos de línea de la observación antes de validarla.
     *
     * Reglas:
     *
     * - 1 Enter: conserva el texto en el renglón siguiente.
     * - 2 Enter: permite una sola línea vacía entre párrafos.
     * - 3 o más Enter seguidos: se reducen automáticamente a 2 saltos.
     * - Se eliminan los espacios y saltos sobrantes al principio y al final.
     */
    protected function prepareForValidation(): void
    {
        // Convierte los distintos formatos de salto de línea en "\n".
        $message = str_replace(
            ["\r\n", "\r"],
            "\n",
            (string) $this->input('message', '')
        );

        // Elimina espacios o tabulaciones colocados alrededor
        // de los saltos de línea.
        $message = preg_replace(
            "/[ \t]*\n[ \t]*/",
            "\n",
            $message
        ) ?? $message;

        // Reduce tres o más saltos consecutivos a un máximo de dos.
        $message = preg_replace(
            "/\n{3,}/",
            "\n\n",
            $message
        ) ?? $message;

        // Elimina espacios y saltos sobrantes al comienzo y al final.
        $this->merge([
            'message' => trim($message),
        ]);
    }

    /**
     * Reglas de la observación editorial.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'message' => ['required', 'string', 'min:15', 'max:1200'],
        ];
    }
}
