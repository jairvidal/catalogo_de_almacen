<?php

namespace Tests\Feature;

use App\Models\Rol;
use App\Models\Solicitud;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Bandeja "Listos para reclamar" (admin.solicitudes.listos).
 *
 * Comparte la vista con el listado general, asi que lo que hay que sostener es
 * la diferencia: alli no van las tarjetas de conteo por estado, y aqui si.
 *
 * Corre contra la base de .env (ver phpunit.xml): DatabaseTransactions y no
 * RefreshDatabase.
 */
class SolicitudesListosTest extends TestCase
{
    use DatabaseTransactions;

    private function administrador(): User
    {
        $rol = Rol::where('col_clave', 'admin')->first();

        return User::create([
            'name' => 'Admin de prueba',
            'email' => 'listos.prueba.'.uniqid().'@sidocsa.com',
            'password' => 'Almacen2026*',
            'rol' => 'admin',
            'rol_id' => $rol?->id,
            'activo' => true,
        ]);
    }

    private function solicitud(string $estado): Solicitud
    {
        return Solicitud::create([
            // El consecutivo real son 6 digitos (000001), pero aqui el numero
            // solo tiene que ser unico. Se le deja una marca no numerica a
            // proposito para que estas filas de prueba no entren en el MAX()
            // con el que SolicitudService calcula el siguiente consecutivo.
            'numero' => 'SOL-TEST-'.substr(uniqid(), -8),
            'solicitante_nombre' => 'Solicitante de prueba',
            'solicitante_cedula' => '100'.random_int(100000, 999999),
            'solicitante_email' => 'solicitante.prueba@sidocsa.com',
            'estado' => $estado,
        ]);
    }

    public function test_la_bandeja_de_listos_no_pinta_las_tarjetas_de_conteo(): void
    {
        $respuesta = $this->actingAs($this->administrador())
            ->get(route('admin.solicitudes.listos'));

        $respuesta->assertOk();
        $respuesta->assertDontSee('tarjeta-metrica');
        $respuesta->assertDontSee('Entregadas');
    }

    public function test_el_listado_general_conserva_las_tarjetas_de_conteo(): void
    {
        $respuesta = $this->actingAs($this->administrador())
            ->get(route('admin.solicitudes.index'));

        $respuesta->assertOk();
        $respuesta->assertSee('tarjeta-metrica');
        $respuesta->assertSee('Entregadas');
    }

    public function test_la_bandeja_de_listos_solo_muestra_solicitudes_en_estado_listo(): void
    {
        $lista = $this->solicitud(Solicitud::ESTADO_LISTO);
        $pendiente = $this->solicitud(Solicitud::ESTADO_PENDIENTE);

        $respuesta = $this->actingAs($this->administrador())
            ->get(route('admin.solicitudes.listos'));

        $respuesta->assertOk();
        $respuesta->assertSee($lista->numero);
        $respuesta->assertDontSee($pendiente->numero);
    }

    public function test_la_busqueda_de_la_bandeja_de_listos_no_se_lleva_las_de_otros_estados(): void
    {
        $lista = $this->solicitud(Solicitud::ESTADO_LISTO);
        $pendiente = $this->solicitud(Solicitud::ESTADO_PENDIENTE);

        $respuesta = $this->actingAs($this->administrador())
            ->get(route('admin.solicitudes.listos', ['q' => 'Solicitante de prueba']));

        $respuesta->assertOk();
        $respuesta->assertSee($lista->numero);
        $respuesta->assertDontSee($pendiente->numero);
    }

    public function test_el_filtro_por_estado_del_listado_general_sigue_funcionando(): void
    {
        $lista = $this->solicitud(Solicitud::ESTADO_LISTO);
        $pendiente = $this->solicitud(Solicitud::ESTADO_PENDIENTE);

        $respuesta = $this->actingAs($this->administrador())
            ->get(route('admin.solicitudes.index', ['estado' => Solicitud::ESTADO_LISTO]));

        $respuesta->assertOk();
        $respuesta->assertSee($lista->numero);
        $respuesta->assertDontSee($pendiente->numero);
    }
}
