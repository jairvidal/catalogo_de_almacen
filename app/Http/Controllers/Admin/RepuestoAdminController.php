<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\RepuestoRequest;
use App\Models\Repuesto;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\View\View;
use RuntimeException;

class RepuestoAdminController extends Controller
{
    public function index(Request $request): View
    {
        $termino = $request->string('q')->toString();
        $filtro = $request->string('filtro')->toString();

        $repuestos = Repuesto::query()
            ->buscar($termino)
            ->when($filtro === 'agotados', fn ($q) => $q->where('existencia', '<=', 0))
            ->when($filtro === 'bajos', fn ($q) => $q->whereColumn('existencia', '<=', 'stock_minimo')
                ->where('existencia', '>', 0))
            // Un stock_maximo en 0 (o nulo) es un maximo sin definir, no un
            // maximo de cero: 25.086 de los 28.490 repuestos estan asi, y sin
            // este filtro cualquiera con existencia daria "sobre stock".
            ->when($filtro === 'sobre_stock', fn ($q) => $q->where('stock_maximo', '>', 0)
                ->whereColumn('existencia', '>', 'stock_maximo'))
            ->when($filtro === 'inactivos', fn ($q) => $q->where('estado', Repuesto::ESTADO_INACTIVO))
            ->orderBy('codigo')
            ->paginate(20)
            ->withQueryString();

        return view('admin.repuestos.index', [
            'repuestos' => $repuestos,
            'termino' => $termino,
            'filtro' => $filtro,
            'totales' => $this->totales(),
        ]);
    }

    public function create(): View
    {
        return view('admin.repuestos.form', [
            'repuesto' => new Repuesto([
                'unidad_medida' => 'UND',
                'estado' => Repuesto::ESTADO_ACTIVO,
            ]),
            'categorias' => $this->categorias(),
        ]);
    }

    public function store(RepuestoRequest $request): RedirectResponse
    {
        $datos = $request->validated();
        $datos['foto'] = $this->guardarImagen($request->file('imagen'), $datos['codigo']);
        unset($datos['imagen']);

        $repuesto = Repuesto::create($datos);

        return redirect()
            ->route('admin.repuestos.index')
            ->with('exito', "Repuesto {$repuesto->codigo} creado.");
    }

    public function edit(Repuesto $repuesto): View
    {
        return view('admin.repuestos.form', [
            'repuesto' => $repuesto,
            'categorias' => $this->categorias(),
        ]);
    }

    public function update(RepuestoRequest $request, Repuesto $repuesto): RedirectResponse
    {
        $datos = $request->validated();

        if ($nuevaFoto = $this->guardarImagen($request->file('imagen'), $datos['codigo'])) {
            $datos['foto'] = $nuevaFoto;
        }

        unset($datos['imagen']);

        $repuesto->update($datos);

        return redirect()
            ->route('admin.repuestos.index')
            ->with('exito', "Repuesto {$repuesto->codigo} actualizado.");
    }

    /**
     * Ajuste rapido del saldo operativo desde el listado.
     *
     * Escribe `existencia` y nunca `stock`: stock lo manda el ERP y se
     * sobrescribe en la siguiente sincronizacion.
     */
    public function ajustarStock(Request $request, Repuesto $repuesto): RedirectResponse
    {
        $datos = $request->validate([
            'existencia' => ['required', 'numeric', 'min:0', 'max:999999999'],
        ], [], ['existencia' => 'existencia']);

        $repuesto->update($datos);

        return back()->with('exito', "Existencias de {$repuesto->codigo} actualizadas a {$repuesto->existencia}.");
    }

    /**
     * No se borra fisicamente: los items historicos apuntan al repuesto.
     */
    public function destroy(Repuesto $repuesto): RedirectResponse
    {
        $repuesto->update(['estado' => Repuesto::ESTADO_INACTIVO]);

        return back()->with('exito', "Repuesto {$repuesto->codigo} desactivado; ya no aparece en el catalogo publico.");
    }

    /**
     * Guarda la imagen en public/img con el codigo del repuesto como nombre.
     *
     * NO usa $imagen->move(): en IIS eso es un rename desde el directorio
     * temporal de PHP, y en NTFS un rename conserva los permisos del origen.
     * El archivo quedaba en public/img con las ACL de C:\Windows\Temp, sin
     * lectura para IUSR, e IIS respondia 401 al pedir la foto: el repuesto
     * guardaba el nombre pero el catalogo mostraba la imagen rota. Copiar el
     * contenido a un archivo NUEVO hace que herede los permisos de public/img.
     */
    private function guardarImagen(?UploadedFile $imagen, int|string $codigo): ?string
    {
        if (! $imagen) {
            return null;
        }

        $nombre = Str::slug((string) $codigo).'-'.now()->format('YmdHis').'.'.$imagen->getClientOriginalExtension();
        $destino = public_path('img/'.$nombre);

        $origen = fopen($imagen->getRealPath(), 'rb');
        $salida = $origen ? fopen($destino, 'xb') : false;
        $copiado = $salida && stream_copy_to_stream($origen, $salida) !== false;

        if ($origen) {
            fclose($origen);
        }

        if ($salida) {
            fclose($salida);
        }

        if (! $copiado) {
            if ($salida) {
                @unlink($destino);
            }

            throw new RuntimeException('No se pudo guardar la imagen en public/img.');
        }

        return $nombre;
    }

    /**
     * Los cinco conteos del encabezado en una sola pasada.
     *
     * Con 28.490 filas, cinco COUNT separados eran cinco recorridos de la
     * tabla en cada carga del listado.
     *
     * @return array<string, int>
     */
    private function totales(): array
    {
        $fila = Repuesto::query()
            ->selectRaw('count(*) as todos')
            ->selectRaw('sum(case when existencia <= 0 then 1 else 0 end) as agotados')
            ->selectRaw('sum(case when existencia > 0 and existencia <= stock_minimo then 1 else 0 end) as bajos')
            // stock_maximo > 0 tambien descarta el nulo (null > 0 no es cierto
            // en SQL Server): un maximo sin definir no es un maximo de cero, y
            // sin ese recorte 4.592 repuestos saldrian "sobre stock" en vez de
            // 703, porque 25.086 filas traen el maximo en 0.
            ->selectRaw('sum(case when stock_maximo > 0 and existencia > stock_maximo then 1 else 0 end) as sobre_stock')
            ->selectRaw('sum(case when estado = ? then 1 else 0 end) as inactivos', [Repuesto::ESTADO_INACTIVO])
            ->first();

        return [
            'todos' => (int) $fila->todos,
            'agotados' => (int) $fila->agotados,
            'bajos' => (int) $fila->bajos,
            'sobre_stock' => (int) $fila->sobre_stock,
            'inactivos' => (int) $fila->inactivos,
        ];
    }

    /**
     * Grupos del ERP, que reemplazaron a la columna de texto `categoria`.
     *
     * @return Collection<int, string>
     */
    private function categorias(): Collection
    {
        return Repuesto::whereNotNull('desc_cat_1')->distinct()->orderBy('desc_cat_1')->pluck('desc_cat_1');
    }
}
