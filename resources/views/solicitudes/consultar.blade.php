@extends('layouts.app')

@section('titulo', 'Consultar mi solicitud')

@section('contenido')

    <div class="row justify-content-center">
        <div class="col-lg-9">

            <div class="mb-4">
                <h1 class="h3 mb-1">Consultar mi solicitud</h1>
                <p class="text-secondary mb-0">
                    Digite el numero que le entrego el sistema y seleccione su nombre para ver el estado del pedido.
                </p>
            </div>

            <form method="GET" action="{{ route('solicitudes.consultar') }}" class="card border-0 shadow-sm mb-4">
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label for="numero" class="form-label">Numero de solicitud</label>
                            <input type="text" class="form-control font-monospace" id="numero" name="numero"
                                   value="{{ $numero }}" placeholder="000001" required>
                        </div>
                        <div class="col-md-8">
                            @include('solicitudes.partials.combo-solicitante', [
                                'nombre' => 'solicitante',
                                'idCampo' => 'consulta_solicitante',
                                'elegido' => $solicitanteElegido,
                                'requerido' => true,
                                'ayuda' => 'Busque y seleccione el nombre con el que hizo la solicitud.',
                            ])
                        </div>
                        <div class="col-12 text-md-end">
                            <button type="submit" class="btn btn-marca px-4">
                                <i class="bi bi-search me-1"></i>Consultar
                            </button>
                        </div>
                    </div>
                </div>
            </form>

            @if ($noEncontrada)
                <div class="alert alert-warning d-flex gap-2 align-items-start">
                    <i class="bi bi-exclamation-triangle-fill mt-1"></i>
                    <div>
                        <strong>No encontramos esa solicitud.</strong>
                        <div class="small">
                            Verifique el numero y que el solicitante sea el mismo que eligio al momento de enviarla.
                        </div>
                    </div>
                </div>
            @endif

            @if ($solicitud)
                @include('solicitudes.partials.detalle', ['solicitud' => $solicitud])
            @endif

        </div>
    </div>

@endsection
