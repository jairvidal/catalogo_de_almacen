<?php

namespace App\Console\Commands;

use App\Models\Repuesto;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Vuelve a amarrar repuestos.foto con los archivos de public/img.
 *
 * La carga del ERP dejo repuestos.foto en NULL en toda la tabla, asi que el
 * catalogo quedo mostrando la imagen generica en todos los items aunque las
 * fotos siguen en disco.
 *
 * El cruce es NUMERICO: los archivos conservan el codigo con ceros a la
 * izquierda de la epoca anterior (0003729.jpg) y repuestos.codigo hoy es un
 * entero (3729). La conversion sirve solo para emparejar; el UPDATE va siempre
 * por codigo con su valor real.
 */
class VincularFotosRepuestos extends Command
{
    protected $signature = 'repuestos:vincular-fotos
                            {--forzar : Reasigna tambien los repuestos que ya tienen foto}
                            {--simular : Reporta sin escribir en la base}';

    protected $description = 'Asocia cada repuesto con su archivo de public/img cruzando el codigo';

    /** Extensiones que se consideran foto del catalogo. */
    private const EXTENSIONES = ['jpg', 'jpeg', 'png', 'webp'];

    /** repuestos.foto es varchar(50): un nombre mas largo no cabe. */
    private const LARGO_MAXIMO = 50;

    public function handle(): int
    {
        $forzar = (bool) $this->option('forzar');
        $simular = (bool) $this->option('simular');

        [$porCodigo, $descartados, $largos] = $this->indiceDeArchivos();

        if ($porCodigo === []) {
            $this->error('No se encontro ninguna foto con codigo derivable en public/img.');

            return self::FAILURE;
        }

        $this->info(count($porCodigo).' archivo(s) con codigo derivable en public/img.');

        $repuestos = Repuesto::query()
            ->when(! $forzar, fn ($q) => $q->whereNull('foto'))
            ->pluck('codigo', 'id');

        if ($repuestos->isEmpty()) {
            $this->info('No hay repuestos por vincular. Use --forzar para reasignar los que ya tienen foto.');

            return self::SUCCESS;
        }

        // id => nombre de archivo, solo para los que efectivamente cruzan.
        $aEscribir = [];

        foreach ($repuestos as $id => $codigo) {
            $archivo = $porCodigo[(int) $codigo] ?? null;

            if ($archivo !== null) {
                $aEscribir[$id] = $archivo;
            }
        }

        $this->line('Repuestos revisados: '.$repuestos->count());
        $this->line(($simular ? 'Se vincularian: ' : 'Vinculados: ').count($aEscribir));
        $this->line('Sin foto en disco: '.($repuestos->count() - count($aEscribir)));

        if (! $simular && $aEscribir !== []) {
            $this->escribir($aEscribir);
        }

        if ($descartados !== []) {
            $this->newLine();
            $this->warn(count($descartados).' archivo(s) sin codigo derivable, se ignoran:');
            foreach (array_slice($descartados, 0, 10) as $nombre) {
                $this->line('  - '.$nombre);
            }
        }

        if ($largos !== []) {
            $this->newLine();
            $this->warn(count($largos).' archivo(s) con nombre de mas de '.self::LARGO_MAXIMO.' caracteres, no caben en la columna:');
            foreach (array_slice($largos, 0, 10) as $nombre) {
                $this->line('  - '.$nombre);
            }
        }

        if ($simular) {
            $this->newLine();
            $this->comment('Simulacion: no se escribio nada. Repita sin --simular para aplicar.');
        }

        return self::SUCCESS;
    }

    /**
     * Recorre public/img y arma el indice codigo numerico => nombre de archivo.
     *
     * LA VARIANTE _vN GANA SOBRE EL ARCHIVO PLANO, y no al reves. Son las fotos
     * repetidas del almacen y traen el marco bueno: en los 32 codigos donde los
     * dos archivos existen y dan colores distintos, la variante da "rojo" y la
     * plana da negro, gris, naranja o directamente no se puede leer. Preferir la
     * plana bajaba Rojo de 153 a 121 contra la tabla de conteos de CLAUDE.md.
     *
     * @return array{0: array<int, string>, 1: list<string>, 2: list<string>}
     */
    private function indiceDeArchivos(): array
    {
        $porCodigo = [];
        // codigo => [variante, posicion de la extension] con el que se quedo.
        $puntaje = [];
        $descartados = [];
        $largos = [];

        foreach (self::EXTENSIONES as $posicion => $extension) {
            // GLOB_BRACE no es portable; una pasada por extension si lo es. En
            // Windows glob() ya no distingue mayusculas de minusculas.
            foreach (glob(public_path('img').'/*.'.$extension) ?: [] as $ruta) {
                $nombre = basename($ruta);
                $base = pathinfo($ruta, PATHINFO_FILENAME);

                // 0003729 y 0015040_v2 son el mismo codigo; el resto se ignora.
                if (! preg_match('/^(\d+)(?:_v(\d+))?$/', $base, $partes)) {
                    $descartados[] = $nombre;

                    continue;
                }

                if (strlen($nombre) > self::LARGO_MAXIMO) {
                    $largos[] = $nombre;

                    continue;
                }

                $codigo = (int) $partes[1];
                $variante = (int) ($partes[2] ?? 0);
                $actual = $puntaje[$codigo] ?? null;

                // Gana la variante mas alta; a igualdad, el orden de EXTENSIONES
                // desempata para que el resultado no dependa de como liste el
                // sistema de archivos.
                if ($actual === null || $variante > $actual[0] || ($variante === $actual[0] && $posicion < $actual[1])) {
                    $porCodigo[$codigo] = $nombre;
                    $puntaje[$codigo] = [$variante, $posicion];
                }
            }
        }

        return [$porCodigo, array_values(array_unique($descartados)), array_values(array_unique($largos))];
    }

    /**
     * @param  array<int, string>  $aEscribir  id => nombre de archivo
     */
    private function escribir(array $aEscribir): void
    {
        $barra = $this->output->createProgressBar(count($aEscribir));
        $barra->start();

        DB::transaction(function () use ($aEscribir, $barra) {
            foreach ($aEscribir as $id => $archivo) {
                // Una sentencia por repuesto: cada uno lleva un archivo distinto,
                // asi que no hay nada que agrupar en lotes.
                Repuesto::whereKey($id)->update([
                    'foto' => $archivo,
                    'tiene_foto' => true,
                ]);

                $barra->advance();
            }
        });

        $barra->finish();
        $this->newLine(2);
    }
}
