<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\AsignacionContrasenaException;
use App\Http\Controllers\Controller;
use App\Models\SolicitanteErp;
use App\Services\AsignacionContrasenaService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Solicitantes del ERP en el panel: listado de solo lectura (los datos los
 * escribe el importador) y la accion de asignar o restablecer la contrasena
 * del portal de aprobacion.
 */
class SolicitanteAdminController extends Controller
{
    /** Filtros rapidos: lista blanca del parametro `filtro`. */
    private const FILTROS = ['activos', 'inactivos', 'con_contrasena', 'sin_contrasena'];

    public function index(Request $request): View
    {
        $termino = trim($request->string('q')->toString());
        $filtro = $request->string('filtro')->toString();
        $filtro = in_array($filtro, self::FILTROS, true) ? $filtro : '';

        $solicitantes = SolicitanteErp::query()
            // Nunca se carga el hash ni el token: el listado no los necesita.
            ->select([
                'id', 'col_codigo_erp', 'col_nombre', 'col_cedula', 'col_correo', 'col_area',
                'col_activo', 'col_password_asignada_at', 'col_password_cambiada_at', 'col_ultimo_ingreso_at',
            ])
            ->when($termino !== '', function (Builder $consulta) use ($termino) {
                $like = '%'.SolicitanteErp::escaparLike($termino).'%';

                $consulta->where(fn (Builder $q) => $q
                    ->where('col_nombre', 'like', $like)
                    ->orWhere('col_codigo_erp', 'like', $like)
                    ->orWhere('col_cedula', 'like', $like)
                    ->orWhere('col_correo', 'like', $like)
                    ->orWhere('col_area', 'like', $like));
            })
            ->when($filtro === 'activos', fn (Builder $q) => $q->where('col_activo', true))
            ->when($filtro === 'inactivos', fn (Builder $q) => $q->where('col_activo', false))
            ->when($filtro === 'con_contrasena', fn (Builder $q) => $q->whereNotNull('col_password_asignada_at'))
            ->when($filtro === 'sin_contrasena', fn (Builder $q) => $q->whereNull('col_password_asignada_at'))
            ->orderBy('col_nombre')
            ->orderBy('id')
            ->paginate(20)
            ->appends(array_filter(['q' => $termino, 'filtro' => $filtro]));

        return view('admin.solicitantes.index', [
            'solicitantes' => $solicitantes,
            // Una consulta para toda la pagina, no una por fila.
            'correosCompartidos' => SolicitanteErp::correosCompartidos(
                $solicitantes->getCollection()->pluck('col_correo')->all()
            ),
            'totales' => $this->totales(),
            'termino' => $termino,
            'filtro' => $filtro,
        ]);
    }

    /**
     * Genera una contrasena, guarda su hash y la envia al correo del
     * solicitante. El administrador no la ve en ningun momento.
     */
    public function asignarContrasena(SolicitanteErp $solicitante, AsignacionContrasenaService $servicio): RedirectResponse
    {
        try {
            $correo = $servicio->asignar($solicitante->id, Auth::user());
        } catch (AsignacionContrasenaException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with(
            'exito',
            "Se genero una contrasena nueva para {$solicitante->col_nombre} y se envio a {$correo}. Usted no la vera: solo le llega al solicitante."
        );
    }

    /**
     * Conteos de las pastillas en una sola consulta.
     *
     * @return array{todos: int, activos: int, inactivos: int, con_contrasena: int, sin_contrasena: int}
     */
    private function totales(): array
    {
        $fila = DB::selectOne(
            'select count(*) as todos,
                    sum(case when [col_activo] = 1 then 1 else 0 end) as activos,
                    sum(case when [col_activo] = 0 then 1 else 0 end) as inactivos,
                    sum(case when [col_password_asignada_at] is not null then 1 else 0 end) as con_contrasena
               from [tbl_solicitante_erp]'
        );

        $todos = (int) $fila->todos;
        $conContrasena = (int) $fila->con_contrasena;

        return [
            'todos' => $todos,
            'activos' => (int) $fila->activos,
            'inactivos' => (int) $fila->inactivos,
            'con_contrasena' => $conContrasena,
            'sin_contrasena' => $todos - $conContrasena,
        ];
    }
}
