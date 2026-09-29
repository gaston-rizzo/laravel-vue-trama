<?php

/* ============================================================================
 * ACTION: PasswordValidationRules.php
 * ============================================================================
 *
 * Define las reglas comunes para validar contraseñas.
 *
 * Fortify reutiliza estas reglas en registro, cambio de contraseña y
 * recuperación. Mantenerlas en un solo lugar evita que cada formulario acepte
 * requisitos distintos.
 * ============================================================================ */

namespace App\Actions\Fortify;

use Illuminate\Contracts\Validation\Rule;

trait PasswordValidationRules
{
    /**
     * Devuelve las reglas que debe cumplir cualquier contraseña nueva.
     *
     * @return array<int, Rule|array<mixed>|string>
     */
    protected function passwordRules(): array
    {
        // Unifica los requisitos de contraseña nueva en registro, cambio
        // y recuperación. Confirmed exige que password_confirmation coincida.
        return [
            'required',
            'string',
            'min:8',
            'max:20',
            'regex:/\pL/u',
            'regex:/\d/u',
            'confirmed',
        ];
    }
}
