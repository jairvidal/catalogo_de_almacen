{{--
    Detalle publico de una solicitud: encabezado, linea de tiempo e items.

    $referencias (opcional) decide si se pintan las columnas de referencia
    (Solicitud::REFERENCIAS_APROBACION):
      null       no se muestran (confirmacion y /consultar, que son publicas)
      'lectura'  solo las diligenciadas, sin cuadros (portal, ya decidida)
      'edicion'  cuadros asociados a <form id="form-aprobar"> (portal, por
                 aprobar); requiere $opcionesReferencia, las listas de las
                 maestras por columna, y $opcionesItem, las de los items
                 (tabla REPUESTOS, partial items-edicion)

    Las referencias POR ITEM siguen la misma regla: en 'lectura' se pintan
    bajo el nombre del repuesto (partial referencias-item) y sin
    $referencias no se muestran.
--}}
@php
    $modoReferencias = $referencias ?? null;
    $editarReferencias = $modoReferencias === 'edicion';
    $filasReferencia = collect($modoReferencias === 'lectura' ? $solicitud->referenciasParaMostrar() : []);
    $referenciasDocumento = $filasReferencia->where('grupo', 'documento');
    $referenciasCentros = $filasReferencia->where('grupo', 'centros');
    $columnaCentros = $editarReferencias || $referenciasCentros->isNotEmpty();
    $etiquetas = \App\Models\Solicitud::REFERENCIAS_APROBACION;
@endphp

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-2 py-3">
        <div>
            <span class="font-monospace fw-semibold">{{ $solicitud->numero }}</span>
            <span class="text-secondary small ms-2">
                {{ $solicitud->created_at->format('d/m/Y h:i a') }}
            </span>
        </div>
        <span class="badge text-bg-{{ $solicitud->estado_color }}">
            <i class="bi bi-{{ $solicitud->estado_icono }} me-1"></i>{{ $solicitud->estado_label }}
        </span>
    </div>

    <div class="card-body">

        <div class="row g-4">
            <div class="{{ $columnaCentros ? 'col-md-6 col-xl-4' : 'col-md-5' }}">
                <h2 class="h6 text-uppercase text-secondary mb-3" style="letter-spacing:.05em">Solicitante</h2>
                <dl class="row small mb-0">
                    @if ($solicitud->nombre_completo)
                        <dt class="col-5 fw-normal text-secondary">Nombre completo</dt>
                        <dd class="col-7">{{ $solicitud->nombre_completo }}</dd>
                    @endif

                    <dt class="col-5 fw-normal text-secondary">Solicitante</dt>
                    <dd class="col-7">{{ $solicitud->solicitante_nombre }}</dd>

                    {{-- Cedula y correo ENMASCARADOS: vienen del ERP y cualquiera puede
                         elegir un nombre de la lista publica. El telefono no se muestra. --}}
                    @if ($solicitud->cedulaEnmascarada())
                        <dt class="col-5 fw-normal text-secondary">Cedula</dt>
                        <dd class="col-7">{{ $solicitud->cedulaEnmascarada() }}</dd>
                    @endif

                    @if ($solicitud->correoAvisoEnmascarado())
                        <dt class="col-5 fw-normal text-secondary">Correo</dt>
                        <dd class="col-7 text-break">{{ $solicitud->correoAvisoEnmascarado() }}</dd>
                    @endif

                    @if ($solicitud->solicitante_area)
                        <dt class="col-5 fw-normal text-secondary">Area</dt>
                        <dd class="col-7">{{ $solicitud->solicitante_area }}</dd>
                    @endif

                    @if ($editarReferencias)
                        {{-- Viajan con el boton Aprobar (atributo form); Denegar no
                             los guarda. Enter en un cuadro no aprueba: ver el boton
                             deshabilitado dentro de form-aprobar. --}}
                        @foreach ([
                            ['cod_referencia', 'cod_referencia', 50, 'text'],
                            ['cod_oc', 'cod_OC', 50, 'text'],
                            ['dias_entrega', 'dias_entrega', null, 'numero'],
                        ] as [$campo, $columna, $largo, $tipo])
                            <dt class="col-5 fw-normal text-secondary pt-1">
                                <label for="{{ $campo }}" class="mb-0">{{ $etiquetas[$columna] }}</label>
                            </dt>
                            <dd class="col-7">
                                <input type="text" id="{{ $campo }}" name="{{ $campo }}" form="form-aprobar"
                                       class="form-control form-control-sm @error($campo) is-invalid @enderror"
                                       value="{{ old($campo, $solicitud->getAttribute($columna)) }}"
                                       autocomplete="off"
                                       @if ($largo) maxlength="{{ $largo }}" @endif
                                       @if ($tipo === 'numero')
                                           inputmode="numeric" pattern="[0-9]*" maxlength="4"
                                           title="Solo numeros enteros"
                                       @endif
                                       @error($campo) aria-describedby="{{ $campo }}-error" @enderror>
                                @error($campo)
                                    <div id="{{ $campo }}-error" class="invalid-feedback d-block">{{ $message }}</div>
                                @enderror
                            </dd>
                        @endforeach
                    @else
                        @foreach ($referenciasDocumento as $fila)
                            <dt class="col-5 fw-normal text-secondary">{{ $fila['etiqueta'] }}</dt>
                            <dd class="col-7 text-break">{{ $fila['valor'] }}</dd>
                        @endforeach
                    @endif
                </dl>
            </div>

            @if ($columnaCentros)
                <div class="col-md-6 col-xl-4">
                    <h2 class="h6 text-uppercase text-secondary mb-3" style="letter-spacing:.05em">Centros y proyecto</h2>

                    @if ($editarReferencias)
                        @foreach (\App\Models\Solicitud::REFERENCIAS_DE_MAESTRA as $columna => $relacion)
                            @include('solicitudes.partials.combo-maestra', [
                                'nombre' => $columna,
                                'etiqueta' => $etiquetas[$columna],
                                'opciones' => $opcionesReferencia[$columna] ?? [],
                                'valor' => old($columna, $solicitud->getAttribute($columna)),
                                'form' => 'form-aprobar',
                            ])
                        @endforeach
                    @else
                        <dl class="row small mb-0">
                            @foreach ($referenciasCentros as $fila)
                                <dt class="col-5 fw-normal text-secondary">{{ $fila['etiqueta'] }}</dt>
                                <dd class="col-7 text-break">{{ $fila['valor'] }}</dd>
                            @endforeach
                        </dl>
                    @endif
                </div>
            @endif

            <div class="{{ $columnaCentros ? 'col-xl-4' : 'col-md-7' }}">
                <h2 class="h6 text-uppercase text-secondary mb-3" style="letter-spacing:.05em">Seguimiento</h2>
                <ul class="linea-tiempo small">
                    <li class="cumplido">
                        <strong>Solicitud recibida</strong><br>
                        <span class="text-secondary">{{ $solicitud->created_at->format('d/m/Y h:i a') }}</span>
                    </li>

                    {{-- Paso de aprobacion: solo en las que pasaron por el. Las historicas
                         (anteriores al flujo) no lo pintan. --}}
                    @if ($solicitud->fue_denegada)
                        <li class="cumplido">
                            <strong>Denegada por {{ $solicitud->solicitante_nombre }}</strong><br>
                            <span class="text-secondary">{{ $solicitud->denegada_at->format('d/m/Y h:i a') }}</span>
                        </li>
                    @elseif ($solicitud->esta_por_aprobar || $solicitud->aprobada_at)
                        <li class="{{ $solicitud->aprobada_at ? 'cumplido' : '' }}">
                            <strong>Aprobada por {{ $solicitud->solicitante_nombre }}</strong><br>
                            <span class="text-secondary">
                                {{ $solicitud->aprobada_at?->format('d/m/Y h:i a') ?? 'Esperando su aprobacion' }}
                            </span>
                        </li>
                    @endif

                    @unless ($solicitud->fue_denegada)
                    <li class="{{ $solicitud->fecha_en_proceso ? 'cumplido' : '' }}">
                        <strong>En preparacion en el almacen</strong><br>
                        <span class="text-secondary">
                            {{ $solicitud->fecha_en_proceso?->format('d/m/Y h:i a') ?? 'Pendiente' }}
                        </span>
                    </li>
                    <li class="{{ $solicitud->fecha_listo ? 'cumplido' : '' }}">
                        <strong>Listo para reclamar</strong><br>
                        <span class="text-secondary">
                            {{ $solicitud->fecha_listo?->format('d/m/Y h:i a') ?? 'Pendiente' }}
                        </span>
                    </li>
                    <li class="{{ $solicitud->fecha_entrega ? 'cumplido' : '' }}">
                        <strong>Entregado</strong><br>
                        <span class="text-secondary">
                            {{ $solicitud->fecha_entrega?->format('d/m/Y h:i a') ?? 'Pendiente' }}
                        </span>
                    </li>
                    @endunless
                </ul>
            </div>
        </div>

        @if ($solicitud->esta_por_aprobar)
            <div class="alert alert-light border mt-3 mb-0 small d-flex gap-2 align-items-start">
                <i class="bi bi-person-check mt-1"></i>
                <div>
                    Esta solicitud espera la aprobacion de <strong>{{ $solicitud->solicitante_nombre }}</strong>.
                    Cuando la apruebe pasara al almacen.
                </div>
            </div>
        @endif

        @if ($solicitud->fue_denegada)
            <div class="alert alert-danger mt-3 mb-0 small">
                <strong>Denegada por {{ $solicitud->solicitante_nombre }}.</strong>
                {{ $solicitud->motivo_denegacion ? 'Motivo: '.$solicitud->motivo_denegacion : 'No se indico un motivo.' }}
            </div>
        @endif

        @if ($solicitud->observaciones)
            <div class="alert alert-light border mt-3 mb-0 small">
                <strong>Observaciones:</strong> {{ $solicitud->observaciones }}
            </div>
        @endif

        @if ($solicitud->nota_almacen)
            <div class="alert alert-warning mt-3 mb-0 small">
                <strong>Nota del almacen:</strong> {{ $solicitud->nota_almacen }}
            </div>
        @endif

        @if ($solicitud->estado === \App\Models\Solicitud::ESTADO_LISTO)
            <div class="alert alert-success mt-3 mb-0 d-flex gap-2 align-items-start">
                <i class="bi bi-geo-alt-fill mt-1"></i>
                <div class="small">
                    Puede reclamarlo en <strong>{{ config('almacen.ubicacion') }}</strong>,
                    {{ config('almacen.horario') }}. Presente su documento de identidad.
                </div>
            </div>
        @endif
    </div>

    @if ($editarReferencias)
        @include('solicitudes.partials.items-edicion', [
            'solicitud' => $solicitud,
            'opcionesItem' => $opcionesItem ?? [],
        ])
    @else
    <div class="table-responsive border-top">
        <table class="table align-middle mb-0">
            <thead class="table-light">
            <tr>
                <th scope="col" class="ps-3" style="width:5.5rem">Foto</th>
                <th scope="col" style="width:5rem">Id</th>
                <th scope="col">Repuesto</th>
                <th scope="col" class="text-end pe-3" style="width:9rem">Cantidad</th>
            </tr>
            </thead>
            <tbody>
            @foreach ($solicitud->items as $item)
                <tr>
                    <td class="ps-3">
                        <img src="{{ $item->foto_url }}" alt="{{ $item->nombre }}" class="miniatura">
                    </td>
                    <td class="text-secondary small">{{ $item->repuesto_id }}</td>
                    <td>
                        <div class="fw-semibold">{{ $item->nombre }}</div>
                        <span class="repuesto-codigo">{{ $item->codigo }}</span>
                        @if ($modoReferencias === 'lectura')
                            @include('solicitudes.partials.referencias-item', ['item' => $item])
                        @endif
                    </td>
                    <td class="text-end pe-3">
                        <span class="fw-semibold">{{ $item->cantidad_entregada ?? $item->cantidad_solicitada }}</span>
                        @if ($item->cantidad_entregada !== null && $item->cantidad_entregada < $item->cantidad_solicitada)
                            <div class="small text-warning-emphasis">
                                de {{ $item->cantidad_solicitada }} solicitadas
                            </div>
                        @endif
                    </td>
                </tr>
            @endforeach
            </tbody>
            <tfoot class="table-light">
            <tr>
                <td colspan="3" class="text-end fw-semibold">Total unidades</td>
                <td class="text-end pe-3 fw-bold">{{ $solicitud->total_unidades }}</td>
            </tr>
            </tfoot>
        </table>
    </div>
    @endif
</div>
