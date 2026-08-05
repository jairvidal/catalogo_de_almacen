<?php

namespace App\Console\Commands;

use App\Models\Repuesto;
use Illuminate\Console\Command;

/**
 * Carga masiva de repuestos desde un CSV.
 *
 * Pensado para reemplazar los nombres genericos del seeder por los nombres
 * reales del almacen sin tener que editarlos uno por uno en el panel.
 */
class ImportarRepuestos extends Command
{
    protected $signature = 'repuestos:importar
                            {archivo : Ruta del archivo CSV}
                            {--separador=; : Separador de columnas (; o ,)}
                            {--crear : Crea los codigos que aun no existan en el catalogo}';

    protected $description = 'Importa o actualiza repuestos desde un archivo CSV (codigo,nombre,descripcion,categoria,ubicacion,unidad_medida,cantidad_disponible,stock_minimo)';

    /**
     * Columnas admitidas. Solo `codigo` es obligatoria; el resto se actualiza
     * unicamente si viene en el encabezado del archivo.
     *
     * @var list<string>
     */
    private const COLUMNAS = [
        'codigo', 'nombre', 'descripcion', 'categoria',
        'ubicacion', 'unidad_medida', 'cantidad_disponible', 'stock_minimo',
    ];

    public function handle(): int
    {
        $archivo = $this->argument('archivo');

        if (! is_file($archivo)) {
            $this->error("No se encontro el archivo: {$archivo}");

            return self::FAILURE;
        }

        $manejador = fopen($archivo, 'r');

        if ($manejador === false) {
            $this->error('No se pudo abrir el archivo.');

            return self::FAILURE;
        }

        $separador = $this->option('separador');
        $encabezado = fgetcsv($manejador, 0, $separador);

        if ($encabezado === false) {
            fclose($manejador);
            $this->error('El archivo esta vacio.');

            return self::FAILURE;
        }

        // Se limpia el BOM que Excel deja al guardar como CSV UTF-8.
        $encabezado = array_map(
            fn ($columna) => strtolower(trim(str_replace("\xEF\xBB\xBF", '', (string) $columna))),
            $encabezado
        );

        if (! in_array('codigo', $encabezado, true)) {
            fclose($manejador);
            $this->error('El archivo debe tener una columna "codigo".');
            $this->line('Columnas admitidas: '.implode(', ', self::COLUMNAS));

            return self::FAILURE;
        }

        $actualizados = 0;
        $creados = 0;
        $omitidos = 0;
        $linea = 1;

        while (($fila = fgetcsv($manejador, 0, $separador)) !== false) {
            $linea++;

            if ($fila === [null] || $fila === []) {
                continue;
            }

            $datos = [];

            foreach ($encabezado as $indice => $columna) {
                if (in_array($columna, self::COLUMNAS, true) && isset($fila[$indice])) {
                    $valor = trim((string) $fila[$indice]);

                    if ($valor !== '') {
                        $datos[$columna] = $valor;
                    }
                }
            }

            $codigo = $datos['codigo'] ?? null;
            unset($datos['codigo']);

            if (! $codigo || $datos === []) {
                $omitidos++;

                continue;
            }

            foreach (['cantidad_disponible', 'stock_minimo'] as $numerica) {
                if (isset($datos[$numerica])) {
                    $datos[$numerica] = (int) $datos[$numerica];
                }
            }

            $repuesto = Repuesto::where('codigo', $codigo)->first();

            if ($repuesto) {
                $repuesto->update($datos);
                $actualizados++;

                continue;
            }

            if (! $this->option('crear')) {
                $this->warn("Linea {$linea}: el codigo {$codigo} no existe en el catalogo (use --crear para agregarlo).");
                $omitidos++;

                continue;
            }

            // Si existe una foto con ese codigo en public/img, se asocia sola.
            Repuesto::create($datos + [
                'codigo' => $codigo,
                'nombre' => $datos['nombre'] ?? $codigo,
                'unidad_medida' => $datos['unidad_medida'] ?? 'UND',
                'foto' => $this->buscarFoto($codigo),
                'activo' => true,
            ]);
            $creados++;
        }

        fclose($manejador);

        $this->newLine();
        $this->info("Actualizados: {$actualizados}");
        $this->info("Creados:      {$creados}");
        $this->info("Omitidos:     {$omitidos}");

        return self::SUCCESS;
    }

    private function buscarFoto(string $codigo): ?string
    {
        foreach (['jpg', 'jpeg', 'png', 'webp'] as $extension) {
            foreach (["{$codigo}.{$extension}", "{$codigo}_v2.{$extension}"] as $nombre) {
                if (is_file(public_path('img/'.$nombre))) {
                    return $nombre;
                }
            }
        }

        return null;
    }
}
