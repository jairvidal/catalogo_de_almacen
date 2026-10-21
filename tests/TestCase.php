<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Las pruebas corren contra la base real: si alguien configura la URL
        // del ERP (erp.importacion_url), aprobar en una prueba no debe mandar
        // un documento de juguete al ERP. Toda llamada HTTP sin Http::fake()
        // falla en vez de salir.
        Http::preventStrayRequests();
    }
}
