{{--
    Tabla REPUESTOS del portal mientras la solicitud esta por aprobar: por cada
    item, todas las columnas de SolicitudItem::REFERENCIAS_APROBACION en el
    MISMO renglon y en ese orden (Motivo, Bodega, Und. medida, Centro de op.,
    Centro de costo, Cant. aprobada, Unidad de negocio, Proyecto, Notas y
    Descripcion). Con anchos minimos la tabla se desplaza dentro de
    .table-responsive en vez de aplastar los cuadros.

    Todo viaja con Aprobar (atributo form="form-aprobar") como
    items[{id del detalle}][campo]; Denegar no lo guarda. La unidad de negocio
    se muestra en solo lectura y SIN name: la impone el servidor.

    Parametros:
      $solicitud      con items cargados
      $opcionesItem   columna => [codigo => texto], una sola vez para todos
                      los renglones
--}}
@use('App\Models\SolicitudItem')
@php
    $etiquetas = SolicitudItem::REFERENCIAS_APROBACION;
    $columnas = array_keys($etiquetas);
    $hayOld = session()->hasOldInput();

    // La columna CANTIDAD de solo lectura es la cantidad_solicitada; el cuadro
    // editable es la que ajusta el aprobador. Encabezados distintos para que
    // no se lean como la misma cosa.
    $encabezados = array_merge($etiquetas, ['cantidad' => 'Cant. aprobada']);
    $etiquetasOcultas = array_merge($etiquetas, ['cantidad' => 'Cantidad aprobada']);

    // Ancho minimo de cada tipo de columna (ver .tabla-items-aprobacion en app.css).
    $claseColumna = fn (string $columna) => match (true) {
        array_key_exists($columna, SolicitudItem::REFERENCIAS_DE_MAESTRA) => 'columna-lista',
        $columna === 'cantidad' => 'columna-cantidad',
        $columna === 'cod_unidad_negocio' => 'columna-unidad-negocio',
        default => 'columna-texto',
    };

    // Lo elegido en la cabecera (old o guardado): prellena centros y proyecto
    // del item que no tenga valor propio, si la opcion esta en la lista del item.
    $cabecera = [];
    foreach (SolicitudItem::HEREDADAS_DE_LA_CABECERA as $columna) {
        $cabecera[$columna] = old($columna, $solicitud->getAttribute($columna));
    }

    $valorDe = function (SolicitudItem $item, string $columna) use ($hayOld, $cabecera, $opcionesItem) {
        // Tras un error de validacion manda lo que la persona envio, tambien
        // si lo dejo vacio a proposito.
        if ($hayOld) {
            return old("items.{$item->id}.{$columna}");
        }

        $guardado = $item->getAttribute($columna);

        if ($guardado !== null && $guardado !== '') {
            return $columna === 'cantidad' ? SolicitudItem::formatearCantidad($guardado) : $guardado;
        }

        if ($columna === 'cantidad') {
            return (string) $item->cantidad_solicitada;
        }

        $heredado = $cabecera[$columna] ?? null;

        return $heredado !== null && array_key_exists((string) $heredado, $opcionesItem[$columna] ?? [])
            ? $heredado
            : null;
    };
@endphp

<div class="table-responsive border-top tabla-items-aprobacion">
    <table class="table align-middle mb-0">
        <thead class="table-light">
        <tr>
            <th scope="col" class="ps-3" style="width:5.5rem">Foto</th>
            <th scope="col" style="width:5rem">Id</th>
            <th scope="col" class="columna-repuesto">Repuesto</th>
            <th scope="col" class="text-end text-nowrap">Cant. solicitada</th>
            @foreach ($columnas as $columna)
                <th scope="col" class="{{ $claseColumna($columna) }}">{{ $encabezados[$columna] }}</th>
            @endforeach
        </tr>
        </thead>
        <tbody>
        @foreach ($solicitud->items as $item)
            @php $prefijo = "item-{$item->id}"; @endphp
            <tr class="fila-item">
                <td class="ps-3">
                    <img src="{{ $item->foto_url }}" alt="{{ $item->nombre }}" class="miniatura">
                </td>
                <td class="text-secondary small">{{ $item->repuesto_id }}</td>
                <td>
                    <div class="fw-semibold">{{ $item->nombre }}</div>
                    <span class="repuesto-codigo">{{ $item->codigo }}</span>
                </td>
                <td class="text-end">
                    <span class="fw-semibold">{{ $item->cantidad_solicitada }}</span>
                </td>
                @foreach ($columnas as $columna)
                    @php
                        $idCampo = "{$prefijo}-{$columna}";
                        $claveError = "items.{$item->id}.{$columna}";
                        $etiquetaOculta = rtrim($etiquetasOcultas[$columna], '.').' del item '.$item->codigo;
                    @endphp
                    <td class="{{ $claseColumna($columna) }}">
                        @if (array_key_exists($columna, SolicitudItem::REFERENCIAS_DE_MAESTRA))
                            @include('solicitudes.partials.combo-maestra', [
                                'nombre' => "items[{$item->id}][{$columna}]",
                                'id' => $idCampo,
                                'claveError' => $claveError,
                                'etiqueta' => $etiquetaOculta,
                                'etiquetaOculta' => true,
                                'clases' => 'mb-0',
                                'opciones' => $opcionesItem[$columna] ?? [],
                                'valor' => $valorDe($item, $columna),
                                'form' => 'form-aprobar',
                                'hereda' => in_array($columna, SolicitudItem::HEREDADAS_DE_LA_CABECERA, true) ? $columna : null,
                            ])
                        @elseif ($columna === 'cantidad')
                            <label for="{{ $idCampo }}" class="visually-hidden">{{ $etiquetaOculta }}</label>
                            <input type="text" id="{{ $idCampo }}" name="items[{{ $item->id }}][cantidad]" form="form-aprobar"
                                   class="form-control form-control-sm text-end @error($claveError) is-invalid @enderror"
                                   value="{{ $valorDe($item, 'cantidad') }}"
                                   inputmode="decimal" autocomplete="off" maxlength="14"
                                   title="Hasta 3 decimales, con coma o punto."
                                   aria-describedby="{{ $idCampo }}-ayuda">
                            @error($claveError)
                                <div id="{{ $idCampo }}-ayuda" class="invalid-feedback d-block">{{ $message }}</div>
                            @else
                                {{-- La ayuda visible inflaria el renglon: queda para lectores de pantalla y en el title. --}}
                                <div id="{{ $idCampo }}-ayuda" class="visually-hidden">Hasta 3 decimales, con coma o punto.</div>
                            @enderror
                        @elseif ($columna === 'cod_unidad_negocio')
                            <label for="{{ $idCampo }}" class="visually-hidden">{{ $etiquetaOculta }}</label>
                            {{-- Sin name: no viaja. El servidor impone el valor. --}}
                            <input type="text" id="{{ $idCampo }}"
                                   class="form-control form-control-sm" readonly
                                   value="{{ SolicitudItem::UNIDAD_NEGOCIO_POR_DEFECTO }}">
                        @else
                            <label for="{{ $idCampo }}" class="visually-hidden">{{ $etiquetaOculta }}</label>
                            <textarea id="{{ $idCampo }}" name="items[{{ $item->id }}][{{ $columna }}]" form="form-aprobar"
                                      class="form-control form-control-sm campo-texto-item @error($claveError) is-invalid @enderror"
                                      rows="1" maxlength="{{ SolicitudItem::LARGO_MAXIMO[$columna] }}"
                                      @error($claveError) aria-describedby="{{ $idCampo }}-error" @enderror>{{ $valorDe($item, $columna) }}</textarea>
                            @error($claveError)
                                <div id="{{ $idCampo }}-error" class="invalid-feedback d-block">{{ $message }}</div>
                            @enderror
                        @endif
                    </td>
                @endforeach
            </tr>
        @endforeach
        </tbody>
        <tfoot class="table-light">
        <tr>
            <td colspan="3" class="text-end fw-semibold">Total unidades</td>
            <td class="text-end fw-bold">{{ $solicitud->total_unidades }}</td>
            <td colspan="{{ count($columnas) }}"></td>
        </tr>
        </tfoot>
    </table>
</div>
