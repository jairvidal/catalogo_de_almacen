<?php

namespace App\Console\Commands;

use App\Exceptions\ClaudeApiException;
use App\Services\CalculadoraCostoClaude;
use App\Services\ClienteClaudeApi;
use App\Services\IndexadorImagenesCatalogo;
use App\Services\UsoClaude;
use Illuminate\Console\Command;

/**
 * Indice visual previo que necesita la busqueda por imagen del catalogo.
 *
 * Se corre UNA vez al instalar y despues cada vez que lleguen fotos nuevas a
 * public/img: solo envia a Claude lo nuevo o lo que cambio. CUESTA DINERO; use
 * primero --simular para ver cuantas fotos y cuanto costaria, y --limite para
 * probar con unas pocas.
 *
 * No va al programador de tareas: indexar es una decision de quien administra
 * el gasto, no una rutina.
 */
class IndexarImagenesRepuestos extends Command
{
    protected $signature = 'repuestos:indexar-imagenes
                            {--forzar : Vuelve a describir tambien las fotos que ya estan al dia}
                            {--simular : Reporta lo pendiente y el costo estimado sin llamar a la API}
                            {--limite= : Procesa como maximo esta cantidad de fotos}';

    protected $description = 'Describe con Claude las fotos de public/img para la busqueda por imagen del catalogo';

    public function handle(IndexadorImagenesCatalogo $indexador, ClienteClaudeApi $cliente, CalculadoraCostoClaude $calculadora): int
    {
        $simular = (bool) $this->option('simular');
        $limite = $this->option('limite') !== null ? max(1, (int) $this->option('limite')) : null;

        $plan = $indexador->planificar((bool) $this->option('forzar'), $limite);
        $pendientes = $plan['pendientes'];

        $this->line('Fotos ya indexadas y al dia: '.$plan['al_dia']);
        $this->line('Fotos por indexar: '.count($pendientes));
        $this->line('Filas de fotos que ya no existen: '.count($plan['huerfanos']));

        if ($pendientes !== []) {
            $tokensImagen = $indexador->tokensDeImagen($pendientes);
            $estimado = $calculadora->costo(new UsoClaude(
                entrada: $tokensImagen + 500 * (int) ceil(count($pendientes) / max(1, (int) config('services.anthropic.imagen.lote_indice', 8))),
                salida: IndexadorImagenesCatalogo::TOKENS_SALIDA_POR_IMAGEN * count($pendientes),
            ));
            $this->line(sprintf(
                'Costo estimado: USD %.2f (%s tokens de imagen, modelo %s; no incluye el razonamiento del modelo).',
                $estimado,
                number_format($tokensImagen),
                config('services.anthropic.modelo'),
            ));
        }

        if ($simular) {
            $this->newLine();
            $this->comment('Simulacion: no se llamo a la API ni se escribio nada.');

            return self::SUCCESS;
        }

        if ($plan['huerfanos'] !== []) {
            $this->line('Filas borradas del indice: '.$indexador->borrarHuerfanos($plan['huerfanos']));
        }

        if ($pendientes === []) {
            $this->info('El indice esta al dia.');

            return self::SUCCESS;
        }

        if (! $cliente->estaConfigurado()) {
            $this->error('Falta ANTHROPIC_API_KEY en el .env. No se envio nada.');

            return self::FAILURE;
        }

        $barra = $this->output->createProgressBar(count($pendientes));
        $barra->start();

        try {
            $resultado = $indexador->indexar($pendientes, fn (int $cuantas) => $barra->advance($cuantas));
        } catch (ClaudeApiException $e) {
            $barra->finish();
            $this->newLine(2);
            $this->error('Se detuvo el indexado: '.$e->getMessage());
            $this->line('Lo ya indexado quedo guardado; vuelva a correr el comando para continuar.');

            return self::FAILURE;
        }

        $barra->finish();
        $this->newLine(2);

        $this->info('Fotos indexadas: '.$resultado['indexadas']);
        $this->line(sprintf(
            'Costo estimado de esta corrida: USD %.4f (%d llamada(s)). Detalle en la bitacora de costos.',
            $calculadora->costo($resultado['uso']),
            $resultado['uso']->llamadas,
        ));

        if ($resultado['fallidas'] !== []) {
            $this->warn(count($resultado['fallidas']).' foto(s) sin indexar (archivo ilegible o sin descripcion):');

            foreach (array_slice($resultado['fallidas'], 0, 10) as $nombre) {
                $this->line('  - '.$nombre);
            }
        }

        return self::SUCCESS;
    }
}
