<?php

namespace Tests\Feature;

use App\Models\Repuesto;
use App\Models\Solicitud;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Consecutivo de la solicitud: solo los 6 digitos (000001), sin el prefijo
 * SOL-{anio}- que se usaba antes.
 *
 * Lo que hay que sostener es el formato, la CONTINUIDAD del contador (es global
 * y sale del maximo existente, no de 1 ni del conteo de filas) y que el numero
 * historico que la gente anoto o recibio por correo siga sirviendo para
 * consultar y para buscar en el panel.
 *
 * Corre contra la base de .env (ver phpunit.xml): DatabaseTransactions y no
 * RefreshDatabase.
 */
class SolicitudNumeroTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        // Crear una solicitud dispara el aviso opcional al almacen.
        Mail::fake();
    }

    private function nuevoRepuesto(): Repuesto
    {
        return Repuesto::create([
            'codigo' => random_int(950000000, 999999999),
            'nombre' => 'Repuesto de prueba consecutivo',
            'unidad_medida' => 'UND',
            'existencia' => 10,
            'stock_minimo' => 1,
            'stock_maximo' => 20,
            'estado' => Repuesto::ESTADO_ACTIVO,
        ]);
    }

    /**
     * Crea una solicitud por el mismo camino que el visitante: carrito en
     * sesion y POST del formulario. Asi se ejercita el servicio completo
     * (crearConReintento incluido) y no solo el calculo del numero.
     */
    private function crearSolicitud(): Solicitud
    {
        $repuesto = $this->nuevoRepuesto();

        $this->withSession(['carrito' => [$repuesto->id => 2]])
            ->post(route('solicitudes.store'), [
                'solicitante_nombre' => 'Persona de prueba',
                'solicitante_cedula' => '100'.random_int(100000, 999999),
                'solicitante_email' => 'prueba.consecutivo@sidocsa.com',
            ])
            ->assertRedirect();

        return Solicitud::orderByDesc('id')->firstOrFail();
    }

    /**
     * Fabrica una solicitud con un numero dado, sin pasar por el servicio.
     */
    private function solicitudCon(string $numero): Solicitud
    {
        return Solicitud::create([
            'numero' => $numero,
            'solicitante_nombre' => 'Solicitante historico',
            'solicitante_cedula' => '100'.random_int(100000, 999999),
            'solicitante_email' => 'historico.prueba@sidocsa.com',
            'estado' => Solicitud::ESTADO_PENDIENTE,
        ]);
    }

    /** Mayor consecutivo vigente, leido con la misma regla que el servicio. */
    private function maximoVigente(): int
    {
        return (int) Solicitud::where('numero', 'like', str_repeat('[0-9]', Solicitud::LONGITUD_NUMERO))
            ->max('numero');
    }

    private function formatear(int $consecutivo): string
    {
        return str_pad((string) $consecutivo, Solicitud::LONGITUD_NUMERO, '0', STR_PAD_LEFT);
    }

    public function test_el_numero_generado_son_solo_seis_digitos_sin_prefijo(): void
    {
        $solicitud = $this->crearSolicitud();

        $this->assertMatchesRegularExpression('/^\d{6}$/', $solicitud->numero);
        $this->assertStringNotContainsString('SOL-', $solicitud->numero);
    }

    /**
     * El contador es global y continua desde el mayor numero guardado. Se
     * siembra un salto deliberado para que no pueda pasar por bueno un
     * contador que arranque en 1 ni uno que cuente filas.
     */
    public function test_el_siguiente_numero_continua_desde_el_maximo_existente(): void
    {
        $salto = $this->maximoVigente() + 100;
        $this->solicitudCon($this->formatear($salto));

        $solicitud = $this->crearSolicitud();

        $this->assertSame($this->formatear($salto + 1), $solicitud->numero);
    }

    /**
     * Un numero del formato viejo que todavia anduviera en la tabla no puede
     * llevarse el maximo: 'SOL-...' es mayor que '000999' comparando texto, y
     * al convertirlo a entero daria 0, o sea que el contador volveria a 000001
     * y chocaria contra el indice unico.
     */
    public function test_un_numero_historico_no_se_lleva_el_maximo(): void
    {
        $esperado = $this->formatear($this->maximoVigente() + 1);
        $this->solicitudCon('SOL-2026-999999');

        $solicitud = $this->crearSolicitud();

        $this->assertSame($esperado, $solicitud->numero);
    }

    public function test_la_normalizacion_acepta_el_formato_viejo_y_los_ceros_omitidos(): void
    {
        $this->assertSame('000004', Solicitud::normalizarNumero('SOL-2026-000004'));
        $this->assertSame('000004', Solicitud::normalizarNumero('sol-2025-4'));
        $this->assertSame('000004', Solicitud::normalizarNumero(' 4 '));
        $this->assertSame('000004', Solicitud::normalizarNumero('000004'));

        // Lo que no es un numero de solicitud tiene que devolver null para que
        // quien llama no lo confunda con un numero valido.
        $this->assertNull(Solicitud::normalizarNumero('Juan Perez'));
        $this->assertNull(Solicitud::normalizarNumero('1001234567'));
        $this->assertNull(Solicitud::normalizarNumero(''));
        $this->assertNull(Solicitud::normalizarNumero(null));
    }

    /**
     * Los correos ya enviados y los numeros anotados traen el formato viejo:
     * la consulta publica tiene que seguir encontrando el pedido con ellos.
     */
    public function test_la_consulta_publica_acepta_el_numero_con_el_prefijo_viejo(): void
    {
        $numero = $this->formatear($this->maximoVigente() + 50);
        $solicitud = $this->solicitudCon($numero);

        $respuesta = $this->get(route('solicitudes.consultar', [
            'numero' => 'SOL-2026-'.$numero,
            'cedula' => $solicitud->solicitante_cedula,
        ]));

        $respuesta->assertOk();
        $respuesta->assertViewHas('solicitud', fn ($vista) => $vista?->id === $solicitud->id);
    }

    /**
     * Sin cedula correcta no se ve nada, tambien con el formato viejo: la
     * proteccion de la consulta publica no cambio.
     */
    public function test_la_consulta_publica_sigue_exigiendo_la_cedula(): void
    {
        $numero = $this->formatear($this->maximoVigente() + 51);
        $this->solicitudCon($numero);

        $respuesta = $this->get(route('solicitudes.consultar', [
            'numero' => 'SOL-2026-'.$numero,
            'cedula' => '000000000',
        ]));

        $respuesta->assertOk();
        $respuesta->assertViewHas('solicitud', fn ($vista) => $vista === null);
        $respuesta->assertViewHas('noEncontrada', true);
    }

    /**
     * El filtro NUMERO del panel tambien acepta el numero viejo pegado tal
     * cual (antes lo sostenia el buscador general, que se retiro).
     */
    public function test_el_filtro_de_numero_del_panel_encuentra_por_el_numero_viejo(): void
    {
        $numero = $this->formatear($this->maximoVigente() + 52);
        $solicitud = $this->solicitudCon($numero);

        $encontradas = Solicitud::filtrarPorColumnas(['numero' => 'SOL-2026-'.$numero])->pluck('id')->all();

        $this->assertSame([$solicitud->id], $encontradas);
    }
}
