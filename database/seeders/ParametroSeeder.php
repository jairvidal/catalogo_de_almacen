<?php

namespace Database\Seeders;

use App\Models\Parametro;
use Illuminate\Database\Seeder;

/**
 * Los nueve parametros que necesita la sincronizacion de stock con la API de
 * inventario de Sidocsa (ver App\Services\InventarioApiSidocsa), mas los dos de
 * la seccion "Importacion ERP" (V 1.4.33, Parametro::IMPORTACION_ERP).
 *
 * Todos entran con col_sistema = true: el codigo los pide por nombre y sin
 * ellos la integracion se queda sin configuracion, asi que no se renombran ni
 * se anulan desde el panel.
 */
class ParametroSeeder extends Seeder
{
    /**
     * OJO CON api.criterio, api.criterio_2 y api.criterio_3: su valor es una LISTA DE IDs
     * NUMERICOS SEPARADOS POR COMA ('2002,2010'), porque la API los recibe como
     * arreglos JSON de enteros. Parametro::listaEnteros() la parte, le hace trim
     * a cada elemento y descarta —con Log::warning— lo que no sea numerico.
     *
     * CUIDADO EN UNA INSTALACION QUE YA EXISTE: el upsert de abajo NO pisa el
     * valor, asi que si el administrador tenia los nombres de grupo
     * ('ELECTRICO,MATERIAS PRIMAS') hay que reemplazarlos por los IDs desde
     * /admin/parametros. La migracion 2026_08_23_100000 hace ese reemplazo una
     * sola vez, pero solo sobre los valores que quedaron con texto.
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
            'valor' => '2002,2010,2013,2014',
            'descripcion' => 'IDs de los GRUPOS que se consultan en la API de inventario; viajan como el '
                .'arreglo criterio_2. Se escriben separados por coma y son NUMEROS, no nombres: un valor '
                .'que no sea numerico se descarta y queda registrado en el log.',
        ],
        [
            'nombre' => Parametro::API_CRITERIO,
            'valor' => '3038,1230',
            'descripcion' => 'IDs de los SUBGRUPOS que se consultan en la API de inventario; viajan como el '
                .'arreglo criterio. Se escriben separados por coma y son NUMEROS, igual que api.criterio_2. '
                .'Vacio significa sin filtro de subgrupo.',
        ],
        [
            'nombre' => Parametro::API_CRITERIO_3,
            'valor' => '9601,9602,9603,9604,9605,9606,9607,9608,9609,9610,9611,9612,9613,9614,9615,'
                .'9616,9617,9618,9619,9620,9621',
            'descripcion' => 'IDs del tercer nivel de criterio del ERP; viajan como el arreglo criterio_3. '
                .'Separados por coma y NUMEROS, igual que api.criterio_2. Vacio = sin este filtro.',
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
        // Seccion "Importacion ERP" (V 1.4.33): se editan solo desde su propio
        // formulario y todavia no los lee ningun proceso. Vacios al sembrar.
        [
            'nombre' => Parametro::ERP_IMPORTACION_URL,
            'valor' => '',
            'descripcion' => 'URL del servicio web (WS) de importacion desde el ERP. Se edita en la seccion '
                .'"Importacion ERP"; todavia no la usa ningun proceso.',
        ],
        [
            'nombre' => Parametro::ERP_IMPORTACION_JSON,
            'valor' => '',
            'descripcion' => 'Estructura JSON de la importacion desde el ERP. Se edita en la seccion '
                .'"Importacion ERP"; todavia no la usa ningun proceso.',
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
