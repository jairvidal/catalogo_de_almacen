{{--
    Tabla REPUESTOS del portal mientras la solicitud esta por aprobar: por cada
    item, las columnas de SolicitudItem::REFERENCIAS_APROBACION en el MISMO
    renglon y en ese orden, con Cant. solicitada (solo lectura) justo antes de
    `cantidad`: Und. medida, Cant. solicitada, Cant. aprobada, Motivo, Bodega,
    Centro de op., Centro de costo, Proyecto y Notas. Con anchos minimos la
    tabla se desplaza dentro de .table-responsive en vez de aplastar los
    cuadros.

    Todo viaja con Aprobar (atributo form="form-aprobar") como
    items[{id del detalle}][campo]; Denegar no lo guarda. Las de
    SolicitudItem::COLUMNAS_DEL_REPUESTO (Und. medida desde V 1.4.26, Bodega
    desde V 1.4.31) se pintan en su lugar como etiqueta de texto, como Cant.
    solicitada, y SIN name: las impone el servidor desde el repuesto. Unidad de
    negocio y Descripcion no se pintan desde V 1.4.28 (no estan en
    REFERENCIAS_APROBACION); la unidad de negocio la sigue imponiendo el
    servidor ('001').

    Parametros:
      $solicitud      con items.repuesto cargados
      $opcionesItem   columna => [codigo => texto], una sola vez para todos
                      los renglones
--}}
@use('App\Models\SolicitudItem')
@php
    $etiquetas = SolicitudItem::REFERENCIAS_APROBACION;
    $columnas = array_keys($etiquetas);
    $hayOld = session()->hasOldInput();

    // Cant. solicitada va justo antes de la cantidad aprobada; el pie de la
    // tabla alinea el total bajo ella.
    $columnasAntesDeSolicitada = array_search('cantidad', $columnas, true);

    // Texto gris cuando el repuesto no trae el dato (COLUMNAS_DEL_REPUESTO).
    $sinValorDelRepuesto = [
        'cod_unidad_medida' => 'Sin unidad',
        'cod_bodega' => 'Sin bodega',
    ];

    // La columna CANTIDAD de solo lectura es la cantidad_solicitada; el cuadro
    // editable es la que ajusta el aprobador. Encabezados distintos para que
    // no se lean como la misma cosa.
    $encabezados = array_merge($etiquetas, ['cantidad' => 'Cant. aprobada']);
    $etiquetasOcultas = array_merge($etiquetas, ['cantidad' => 'Cantidad aprobada']);

    // Ancho minimo de cada tipo de columna (ver .tabla-items-aprobacion en app.css).
    $claseColumna = fn (string $columna) => match (true) {
        array_key_exists($columna, SolicitudItem::COLUMNAS_DEL_REPUESTO) => 'columna-del-repuesto',
        array_key_exists($columna, SolicitudItem::REFERENCIAS_DE_MAESTRA) => 'columna-lista',
        $columna === 'cantidad' => 'columna-cantidad',
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
            @foreach ($columnas as $columna)
                @if ($columna === 'cantidad')
                    <th scope="col" class="text-end text-nowrap">Cant. solicitada</th>
                @endif
                <th scope="col" class="{{ $claseColumna($columna) }}">{{ $encabezados[$columna] }}</th>
            @endforeach
        </tr>
        </thead>
        <tbody>
        @php $prefijosUsados = []; @endphp
        @foreach ($solicitud->items as $item)
            @php
                // id de los campos = codigo del repuesto (repuestos.codigo), uno
                // distinto por renglon (V 1.4.37). El name sigue por el id del
                // detalle: es la lista blanca del servidor. Si un repuesto se
                // repitiera en la misma solicitud (el carrito no lo permite), el
                // segundo lleva tambien el id del detalle para no duplicar el id.
                $prefijo = 'item-'.$item->codigoDelRepuesto();
                if (isset($prefijosUsados[$prefijo])) {
                    $prefijo .= '-'.$item->id;
                }
                $prefijosUsados[$prefijo] = true;
            @endphp
            <tr class="fila-item">
                <td class="ps-3">
                    <img src="{{ $item->foto_url }}" alt="{{ $item->nombre }}" class="miniatura">
                </td>
                {{-- Codigo del repuesto (repuestos.codigo), no la clave primaria (V 1.4.38). --}}
                <td class="text-secondary small">{{ $item->codigoDelRepuesto() }}</td>
                <td>
                    <div class="fw-semibold">{{ $item->nombre }}</div>
                    <span class="repuesto-codigo">{{ $item->codigo }}</span>
                </td>
                @foreach ($columnas as $columna)
                    @php
                        $idCampo = "{$prefijo}-{$columna}";
                        $claveError = "items.{$item->id}.{$columna}";
                        $etiquetaOculta = rtrim($etiquetasOcultas[$columna], '.').' del item '.$item->codigo;
                    @endphp
                    @if ($columna === 'cantidad')
                        <td class="text-end">
                            <span class="fw-semibold">{{ $item->cantidad_solicitada }}</span>
                        </td>
                    @endif
                    @if (array_key_exists($columna, SolicitudItem::COLUMNAS_DEL_REPUESTO))
                        @php $delRepuesto = $item->valorDelRepuesto($columna); @endphp
                        <td class="{{ $claseColumna($columna) }} text-center">
                            {{-- Etiqueta, como Cant. solicitada: no es un campo y no viaja.
                                 El servidor impone el valor del repuesto al aprobar. --}}
                            @if ($delRepuesto === null)
                                <span id="{{ $idCampo }}" class="text-secondary small">{{ $sinValorDelRepuesto[$columna] ?? 'Sin dato' }}</span>
                            @else
                                <span id="{{ $idCampo }}" class="fw-bold">{{ $delRepuesto }}</span>
                            @endif
                        </td>
                        @continue
                    @endif
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
            {{-- Foto, Id, Repuesto y las referencias anteriores a Cant. solicitada. --}}
            <td colspan="{{ 3 + $columnasAntesDeSolicitada }}" class="text-end fw-semibold">Total unidades</td>
            <td class="text-end fw-bold">{{ $solicitud->total_unidades }}</td>
            <td colspan="{{ count($columnas) - $columnasAntesDeSolicitada }}"></td>
        </tr>
        </tfoot>
    </table>
</div>
