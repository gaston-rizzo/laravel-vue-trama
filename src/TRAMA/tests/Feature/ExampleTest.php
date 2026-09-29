<?php

/* ============================================================================
 * TEST: ExampleTest.php
 * ============================================================================
 *
 * Comprueba que la portada pública pueda responder correctamente con una base
 * de pruebas recién preparada por RefreshDatabase.
 *
 * Es una prueba mínima de disponibilidad: no necesita cargar el conjunto grande
 * de datos de prueba porque la portada debe poder abrirse incluso cuando todavía
 * no existen noticias publicadas.
 * ============================================================================ */

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_application_returns_a_successful_response(): void
    {
        $response = $this->get('/');

        $response->assertStatus(200);
    }
}
