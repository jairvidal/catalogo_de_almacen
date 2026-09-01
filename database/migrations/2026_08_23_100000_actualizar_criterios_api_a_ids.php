<?php

use App\Models\Parametro;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Valor que le corresponde a cada criterio ahora que la API los recibe como
     * IDs. Es el mismo que siembra ParametroSeeder.
     *
     * @var array<string, string>
     */
    private const NUEVOS = [
        Parametro::API_CRITERIO => '3038,1230',
        Parametro::API_CRITERIO_2 => '2002,2010,2013,2014',
    ];

    /**
     * Pasa api.criterio y api.criterio_2 de nombres de grupo a IDs numericos.
     *
     * POR QUE HACE FALTA UNA MIGRACION: ParametroSeeder hace upsert por
     * col_nombre y NO pisa el valor, a proposito, porque el valor es del
     * administrador. Pero el contrato de la API cambio: 'ELECTRICO,MATERIAS
     * PRIMAS' ya no es un filtro valido, y Parametro::listaEnteros() lo
     * descartaria entero dejando la consulta SIN filtro de grupo. Sin este
     * paso, una instalacion que ya existe seguiria sincronizando —sin error
     * visible— contra un universo de items que no es el acordado.
     *
     * Se reemplaza el valor que NO aporta ni un solo ID: los nombres de grupo
     * de antes, y tambien el vacio, porque el vacio de api.criterio tampoco fue
     * una decision del administrador sino el valor que sembraba el seeder bajo
     * el contrato anterior. Un valor que ya trae IDs no se toca: ese si es una
     * configuracion hecha bajo el contrato nuevo.
     */
    public function up(): void
    {
        if (! Schema::hasTable('tbl_parametro')) {
            return;
        }

        foreach (self::NUEVOS as $nombre => $nuevoValor) {
            $valor = DB::scalar(
                'select [col_valor] from [tbl_parametro] where [col_nombre] = ?',
                [$nombre]
            );

            if ($valor === null || $this->tieneAlgunId((string) $valor)) {
                continue;
            }

            DB::update(
                'update [tbl_parametro] set [col_valor] = ?, [updated_at] = ? where [col_nombre] = ?',
                [$nuevoValor, now(), $nombre]
            );

            info("El parametro {$nombre} no traia ningun ID numerico y se reemplazo por {$nuevoValor}. "
                .'Verifiquelo en /admin/parametros antes de la proxima sincronizacion.');
        }
    }

    /**
     * Sin vuelta atras: los nombres anteriores no se pueden reconstruir desde
     * los IDs, y devolverlos dejaria la sincronizacion sin filtro otra vez.
     */
    public function down(): void
    {
        //
    }

    /**
     * Si la lista aporta al menos un ID numerico, que es lo que la vuelve un
     * filtro util. Mismo criterio que Parametro::listaEnteros(), escrito aqui a
     * mano para que la migracion no dependa de codigo que puede cambiar.
     */
    private function tieneAlgunId(string $valor): bool
    {
        foreach (explode(',', $valor) as $elemento) {
            if (ctype_digit(trim($elemento))) {
                return true;
            }
        }

        return false;
    }
};
