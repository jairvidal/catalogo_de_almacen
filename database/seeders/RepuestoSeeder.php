<?php

namespace Database\Seeders;

use App\Models\Repuesto;
use App\Services\GeneradorDatosRepuesto;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;

class RepuestoSeeder extends Seeder
{
    public function run(GeneradorDatosRepuesto $generador): void
    {
        $directorio = public_path('img');

        if (! File::isDirectory($directorio)) {
            $this->command?->warn("No existe la carpeta {$directorio}; no se sembraron repuestos.");

            return;
        }

        $archivos = collect(File::files($directorio))
            ->filter(fn ($archivo) => in_array(
                strtolower($archivo->getExtension()),
                ['jpg', 'jpeg', 'png', 'webp', 'gif'],
                true
            ))
            ->sortBy(fn ($archivo) => $archivo->getFilename())
            ->values();

        if ($archivos->isEmpty()) {
            $this->command?->warn('No se encontraron imagenes en public/img.');

            return;
        }

        $filas = [];
        $ahora = now();

        foreach ($archivos as $archivo) {
            $nombreArchivo = $archivo->getFilename();

            // 0015040_v2.png -> codigo 0015040
            $codigo = preg_replace('/_v\d+$/i', '', pathinfo($nombreArchivo, PATHINFO_FILENAME));

            // El nombre, la linea y el stock salen del crc32 del codigo, asi que
            // volver a correr el seeder no altera lo que ya se publico.
            $filas[] = $generador->generar($codigo, $nombreArchivo) + [
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ];
        }

        // upsert para poder volver a correr el seeder sin duplicar codigos.
        // El lote es de 120 filas porque SQL Server admite maximo 2100
        // parametros por sentencia y cada fila aporta 12.
        foreach (array_chunk($filas, 120) as $lote) {
            Repuesto::upsert(
                $lote,
                ['codigo'],
                ['nombre', 'descripcion', 'categoria', 'ubicacion', 'unidad_medida', 'foto', 'updated_at']
            );
        }

        $this->command?->info('Repuestos sembrados: '.count($filas));
    }
}
