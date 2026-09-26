@extends('layouts.app')

@section('titulo', 'Solicitud enviada')

@section('contenido')

    <div class="row justify-content-center">
        <div class="col-lg-9">

            <div class="card border-0 shadow-sm mb-4">
                <div class="card-body text-center p-4 p-md-5">
                    <div class="d-inline-flex align-items-center justify-content-center rounded-circle mb-3"
                         style="width:76px;height:76px;background:var(--ca-exito-claro)">
                        <i class="bi bi-check-lg text-success" style="font-size:2.4rem"></i>
                    </div>

                    <h1 class="h3 mb-2">Su solicitud fue enviada</h1>
                    <p class="text-secondary mb-4">
                        Ahora espera la aprobacion de <strong>{{ $solicitud->solicitante_nombre }}</strong>;
                        cuando la apruebe pasara al almacen.
                        @if ($solicitud->correoAvisoEnmascarado())
                            Le llegara un correo a <strong>{{ $solicitud->correoAvisoEnmascarado() }}</strong>
                            cuando se apruebe o se deniegue, y otro cuando el pedido este listo para reclamar.
                        @else
                            No tiene un correo registrado en el almacen: consulte el estado con el numero de abajo.
                        @endif
                    </p>

                    <div class="d-inline-block border rounded-3 px-4 py-3 bg-light mb-4">
                        <div class="small text-secondary text-uppercase" style="letter-spacing:.06em">
                            Numero de solicitud
                        </div>
                        <div class="h4 mb-0 font-monospace text-marca">{{ $solicitud->numero }}</div>
                    </div>

                    <p class="small text-secondary mb-4">
                        Guarde este numero. Con el y su nombre puede consultar el estado en cualquier momento.
                    </p>

                    <div class="d-flex flex-wrap justify-content-center gap-2 no-imprimir">
                        <a href="{{ route('solicitudes.consultar', array_filter(['numero' => $solicitud->numero, 'solicitante' => $solicitud->solicitante_erp_id])) }}"
                           class="btn btn-marca">
                            <i class="bi bi-search me-1"></i>Consultar estado
                        </a>
                        <button type="button" class="btn btn-outline-secondary" onclick="window.print()">
                            <i class="bi bi-printer me-1"></i>Imprimir
                        </button>
                        <a href="{{ route('catalogo.index') }}" class="btn btn-outline-secondary">
                            <i class="bi bi-grid me-1"></i>Volver al catalogo
                        </a>
                    </div>
                </div>
            </div>

            @include('solicitudes.partials.detalle', ['solicitud' => $solicitud])

        </div>
    </div>

@endsection
