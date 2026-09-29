<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class StrictEmail implements ValidationRule
{
    /**
     * Valida correos con dominio completo para formularios publicos.
     *
     * Laravel ya valida la forma general de email. Esta regla suma una condicion
     * editorial simple: el dominio debe tener una extension, por ejemplo
     * usuario@dominio.com o nombre@empresa.com.ar.
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $email = trim((string) $value);

        if (! preg_match('/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/i', $email)) {
            $fail('Ingresá un correo electrónico válido.');
        }
    }
}
