<?php

namespace Database\Seeders;

use App\Models\Parametro;
use Illuminate\Database\Seeder;

/**
 * Los ocho parametros que necesita la sincronizacion de stock con la API de
 * inventario de Sidocsa (ver App\Services\InventarioApiSidocsa).
 *
 * Todos entran con col_sistema = true: el codigo los pide por nombre y sin
 * ellos la integracion se queda sin configuracion, asi que no se renombran ni
 * se anulan desde el panel.
 */
class ParametroSeeder extends Seeder
{
    /**
     * OJO CON api.criterio y api.criterio_2: su valor es una LISTA SEPARADA POR
     * COMAS ('ELECTRICO,MATERIAS PRIMAS'), porque la API los recibe como
     * arreglos JSON. Parametro::lista() la parte descartando solo los elementos
     * vacios, SIN hacerle trim a cada uno: ni el seeder, ni Parametro::valor(),
     * ni ParametroRequest recortan el valor, de modo que un elemento que
     * legitimamente lleve un espacio conserva ese espacio hasta la API.
     *
     * @var list<array{nombre: string, valor: string, descripcion: string}>
     */
    private const PARAMETROS = [
        [
            'nombre' => 'codigo_api',
            'valor' => '123',
            'descripcion' => 'Credencial del endpoint de token de la API de inventario. VALOR DE EJEMPLO: '
                .'reemplacelo por el codigo real. No se muestra en el listado ni se escribe en el log.',
        ],
        [
            'nombre' => 'tiempo.actualizar',
            'valor' => '60',
            'descripcion' => 'Minutos que deben pasar entre dos sincronizaciones de stock. '
                .'Lo lee el programador de tareas antes de disparar repuestos:sincronizar-stock.',
        ],
        [
            'nombre' => 'api.id_bod',
            'valor' => 'P2ALM',
            'descripcion' => 'Bodega que se consulta en la API de inventario. El valor real del almacen '
                .'es P2ALM; cambielo solo si la sincronizacion debe apuntar a otra bodega.',
        ],
        [
            'nombre' => 'api.id_cia',
            'valor' => '1',
            'descripcion' => 'Compania que se consulta en la API de inventario.',
        ],
        [
            'nombre' => Parametro::INV_ACTUALIZAR,
            'valor' => Parametro::INV_AUTOMATICO,
            'descripcion' => 'Como se actualiza el inventario desde el ERP: "automatico" deja que la '
                .'tarea programada lo haga sola cada tiempo.actualizar minutos, "manual" apaga la tarea '
                .'y deja la actualizacion en el boton Actualizar de /admin/parametros.',
        ],
        [
            'nombre' => Parametro::API_CRITERIO_2,
            'valor' => 'ELECTRICO,MATERIAS PRIMAS,FERRETERIA,HIDRAULICA Y NEUMATICA,MANGUERAS Y ACCESORIOS',
            'descripcion' => 'GRUPOS que se consultan en la API de inventario; viaja como el arreglo '
                .'criterio_2. Se escriben separados por coma y deben coincidir con repuestos.desc_cat_1. '
                .'El valor se guarda literal: lo que escriba viaja tal cual, espacios incluidos.',
        ],
        [
            'nombre' => Parametro::API_CRITERIO,
            // Vacio a proposito: en la consulta acordada con el ERP el arreglo
            // criterio va vacio. Aun asi el parametro existe y esta activo, para
            // que filtrar por subgrupo sea configurar el panel y no tocar codigo.
            'valor' => '',
            'descripcion' => 'SUBGRUPOS que se consultan en la API de inventario; viaja como el arreglo '
                .'criterio. Se escriben separados por coma, igual que api.criterio_2. Vacio significa '
                .'sin filtro de subgrupo, que es como se consulta hoy.',
        ],
        [
            'nombre' => Parametro::INV_EXISTENCIA_INICIALIZADA,
            // '0' en una instalacion nueva: la tabla arranca con existencia en
            // cero y hace falta la carga inicial. El upsert no pisa valores, asi
            // que una instalacion que ya la hizo conserva su '1'.
            'valor' => Parametro::INV_NO,
            'descripcion' => 'Marca de que repuestos.existencia (el saldo operativo del almacen) ya recibio su '
                .'carga inicial desde el stock del ERP con repuestos:inicializar-existencia. CUIDADO: '
                .'ponerlo en "No" habilita esa carga otra vez y borraria lo ya despachado.',
        ],
    ];

    public function run(): void
    {
        $ahora = now();

        $filas = array_map(fn (array $parametro) => [
            'col_nombre' => $parametro['nombre'],
            'col_valor' => $parametro['valor'],
            'col_estado' => Parametro::ESTADO_ACTIVO,
            'col_descripcion' => $parametro['descripcion'],
            'col_sistema' => true,
            'created_at' => $ahora,
            'updated_at' => $ahora,
        ], self::PARAMETROS);

        // Solo se refresca la descripcion: el valor es del administrador (la
        // credencial y la bodega reales se configuran desde /admin/parametros)
        // y volver a correr el seeder no puede pisar lo que ya configuro.
        Parametro::upsert($filas, ['col_nombre'], ['col_descripcion', 'updated_at']);

        $this->command?->info('Parametros sembrados: '.count($filas));
    }
}
