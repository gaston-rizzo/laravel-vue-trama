<?php

/* ============================================================================
 * REQUEST: CommentModerationRequest.php
 * ============================================================================
 *
 * Valida la decisión aplicada por un editor desde la moderación de comentarios.
 *
 * La acción utiliza una lista cerrada para impedir que una petición manipulada
 * pueda guardar estados arbitrarios o ejecutar operaciones distintas de las
 * disponibles en la interface.
 *
 * Acciones admitidas:
 *
 *      - approve: aprueba el comentario;
 *      - reject: rechaza un pendiente o retira un aprobado;
 *      - maintain: mantiene aprobado un comentario reportado y cierra sus
 *        reportes abiertos como revisados.
 * ============================================================================ */

namespace App\Http\Requests\Editorial;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CommentModerationRequest extends FormRequest
{
    /**
     * El acceso al módulo ya está protegido por EnsureCommentModerationAccess.
     *
     * El controlador vuelve a comprobar canModerateComments() antes de ejecutar
     * cualquier modificación sobre el comentario o sus reportes.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Reglas aplicadas a la decisión enviada desde la grilla o el modal.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'action' => [
                'required',
                'string',
                Rule::in([
                    'approve',
                    'reject',
                    'maintain',
                ]),
            ],
        ];
    }

    /**
     * Normaliza la acción antes de validarla.
     */
    protected function prepareForValidation(): void
    {
        $action = trim((string) $this->input('action'));

        $this->merge([
            'action' => $action !== ''
                ? strtolower($action)
                : null,
        ]);
    }
}
