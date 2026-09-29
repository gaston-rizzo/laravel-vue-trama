<?php

/* ============================================================================
 * TEST: ExampleTest.php
 * ============================================================================
 *
 * Prueba unitaria mínima de funcionamiento del entorno.
 *
 * Confirma que PHPUnit puede ejecutar pruebas sin depender de la base de datos ni de solicitudes HTTP.
 * ============================================================================ */

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class ExampleTest extends TestCase
{
    /**
     * A basic test example.
     */
    public function test_that_true_is_true(): void
    {
        $this->assertTrue(true);
    }
}
