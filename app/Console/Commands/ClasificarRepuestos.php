<?php

namespace App\Console\Commands;

use App\Models\Categoria;
use App\Models\Repuesto;
use App\Services\DetectorColorMarco;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Asigna repuestos.categoria_id leyendo el color del marco de la foto.
 *
 * En este almacen la categoria de un repuesto es el color del marco impreso en
 * el borde de su foto; el nombre no dice nada (cada color mezcla filtros,
 * valvulas, tornillos y rodamientos). El analisis de pixeles vive en
 * DetectorColorMarco: aqui solo se recorre el catalogo y se escribe.
 */
class ClasificarRepuestos extends Command
{
    protected $signature = 'repuestos:clasificar
                            {--forzar : Reasigna tambien los repuestos que ya tienen categoria}
                            {--simular : Reporta sin escribir en la base}';

    protected $description = 'Asigna la categoria de cada repuesto segun el color del marco de su foto en public/img';

    /**
     * Filas por sentencia. SQL Server admite 2100 parametros y cada UPDATE
     * gasta uno por id mas el de la categoria, asi que 120 sobra de margen
     * (igual que el lote de RepuestoSeeder).
     */
    private const LOTE = 120;

    public function handle(DetectorColorMarco $detector): int
    {
        if (! $detector->disponible()) {
            $this->error('La extension GD de PHP no esta disponible y sin ella no se puede leer el marco de las fotos.');
            $this->line('Habilite extension=gd en php.ini y reinicie el servidor web.');

            return self::FAILURE;
        }

        $categorias = Categoria::query()->activas()->pluck('id', 'col_slug');

        if ($categorias->isEmpty()) {
            $this->error('No hay categorias activas. Corra primero: php artisan db:seed --class=CategoriaSeeder');

            return self::FAILURE;
        }

        $forzar = (bool) $this->option('forzar');
        $simular = (bool) $this->option('simular');

        $consulta = Repuesto::query()
            ->when(! $forzar, fn ($q) => $q->whereNull('categoria_id'));

        $pendientes = (clone $consulta)->count();

        if ($pendientes === 0) {
            $this->info('No hay repuestos por clasificar. Use --forzar para reasignar los que ya tienen categoria.');

            return self::SUCCESS;
        }

        $this->info(($simular ? 'Simulando la clasificacion de ' : 'Clasificando ').$pendientes.' repuesto(s).');

        /** @var array<string, list<int>> $porSlug ids agrupados por slug detectado */
        $porSlug = [];
        $sinFoto = 0;
        $fotoIlegible = 0;
        /** @var array<string, int> $colorSinCategoria */
        $colorSinCategoria = [];

        $barra = $this->output->createProgressBar($pendientes);
        $barra->start();

        // chunkById y no chunk: el filtro whereNull cambia a medida que se
        // escribe y paginar por offset se saltaria filas.
        $consulta->select(['id', 'foto'])->chunkById(500, function (Collection $repuestos) use (
            $detector, &$porSlug, &$sinFoto, &$fotoIlegible, &$colorSinCategoria, $categorias, $barra
        ) {
            foreach ($repuestos as $repuesto) {
                $barra->advance();

                if (! $repuesto->foto) {
                    $sinFoto++;

                    continue;
                }

                $marco = $detector->detectar(public_path('img/'.$repuesto->foto));

                if ($marco === null) {
                    $fotoIlegible++;

                    continue;
                }

                if (! $categorias->has($marco->slug)) {
                    $colorSinCategoria[$marco->slug] = ($colorSinCategoria[$marco->slug] ?? 0) + 1;

                    continue;
                }

                $porSlug[$marco->slug][] = $repuesto->id;
            }
        });

        $barra->finish();
        $this->newLine(2);

        $asignados = 0;

        if (! $simular) {
            foreach ($porSlug as $slug => $ids) {
                foreach (array_chunk($ids, self::LOTE) as $lote) {
                    Repuesto::whereIn('id', $lote)->update(['categoria_id' => $categorias->get($slug)]);
                }

                $asignados += count($ids);
            }
        } else {
            $asignados = array_sum(array_map('count', $porSlug));
        }

        $this->reportar($porSlug, $categorias, $simular);

        $sinClasificar = $sinFoto + $fotoIlegible + array_sum($colorSinCategoria);

        $this->line(($simular ? 'Se asignarian: ' : 'Asignados: ').$asignados);
        $this->line('Sin clasificar: '.$sinClasificar);

        if ($sinFoto > 0) {
            $this->warn("  {$sinFoto} sin foto registrada.");
        }

        if ($fotoIlegible > 0) {
            $this->warn("  {$fotoIlegible} con la foto ausente en public/img o ilegible.");
        }

        foreach ($colorSinCategoria as $slug => $cantidad) {
            $this->warn("  {$cantidad} con marco \"{$slug}\", que no tiene categoria activa.");
        }

        if ($simular) {
            $this->newLine();
            $this->comment('Simulacion: no se escribio nada. Repita sin --simular para aplicar.');
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, list<int>>  $porSlug
     * @param  Collection<string, int>  $categorias
     */
    private function reportar(array $porSlug, Collection $categorias, bool $simular): void
    {
        $nombres = Categoria::query()->whereIn('id', $categorias->values())->pluck('col_nombre', 'id');

        $filas = [];

        foreach ($categorias as $slug => $id) {
            $enEstaCorrida = count($porSlug[$slug] ?? []);
            // El total del catalogo se lee de la base para poder cotejar el
            // conteo real, no solo lo que toco esta corrida.
            $totalCatalogo = $simular ? '-' : Repuesto::where('categoria_id', $id)->count();

            $filas[] = [$nombres[$id] ?? $slug, $slug, $enEstaCorrida, $totalCatalogo];
        }

        // Mayor primero: es como se leen los conteos esperados del catalogo.
        usort($filas, fn (array $a, array $b) => $b[2] <=> $a[2]);

        $this->table(
            ['Categoria', 'Slug', $simular ? 'Se asignarian' : 'Asignados ahora', 'Total en catalogo'],
            $filas
        );
    }
}
