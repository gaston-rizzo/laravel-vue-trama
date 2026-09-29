<?php

/* ============================================================================
 * FACTORY: UserFactory.php
 * ============================================================================
 *
 * Genera cuentas válidas para pruebas automatizadas.
 *
 * Por defecto crea usuarios registrados activos y verificados. Los tests pueden 
 * sobrescribir el rol o estado para comprobar permisos del panel editorial y 
 * bloqueos de acceso.
 * ============================================================================ */

namespace Database\Factories;

use App\Support\TramaClock;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * Hash compartido para evitar recalcular la contraseña en cada usuario.
     */
    protected static ?string $password;

    /**
     * Devuelve los atributos predeterminados de una cuenta pública activa.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => TramaClock::initial(),
            'password' => static::$password ??= Hash::make('password'),
            'role' => 'reader',
            'is_active' => true,
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Genera una cuenta cuyo correo todavía no fue verificado.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
