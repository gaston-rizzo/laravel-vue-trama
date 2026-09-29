<?php

/* ============================================================================
 * MODEL: ContactMessage.php
 * ============================================================================
 *
 * Representa una consulta enviada desde la página pública de Contacto.
 *
 * TRAMA conserva motivo, identidad de contacto, mensaje y una marca opcional de
 * resolución. La información no se expone públicamente.
 * ============================================================================ */

namespace App\Models;

use App\Models\Concerns\UsesTramaClock;

use Illuminate\Database\Eloquent\Model;

class ContactMessage extends Model
{
    use UsesTramaClock;
    /**
     * Campos habilitados para asignación masiva. El formulario público escribe
     * motivo, nombre, email y mensaje; resolved_at queda reservado al estado
     * interno de resolución.
     *
     * @var list<string>
     */
    protected $fillable = [
        'reason',
        'name',
        'email',
        'message',
        'resolved_at',
    ];

    /**
     * Convierte la marca de resolución en fecha de Laravel.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'resolved_at' => 'datetime',
        ];
    }
}
