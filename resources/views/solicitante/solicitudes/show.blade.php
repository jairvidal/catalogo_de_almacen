@extends('layouts.app')

@section('titulo', 'Solicitud '.$solicitud->numero)

@section('contenido')

    <div class="row justify-content-center">
        <div class="col-lg-10">

            <div class="mb-3">
                <a href="{{ route('solicitante.solicitudes.index') }}" class="text-decoration-none small">
                    <i class="bi bi-arrow-left me-1"></i>Volver a mis solicitudes
                </a>
            </div>

            {{-- Mismo detalle que la consulta publica: cedula y correo enmascarados,
                 items del snapshot con codigo, nombre, foto y cantidad. --}}
            @include('solicitudes.partials.detalle', ['solicitud' => $solicitud])

            @if ($solicitud->esta_por_aprobar)
                {{-- Los botones van juntos abajo y cada uno pertenece a su propio
                     formulario (atributo form). El servidor vuelve a exigir que sea
                     suya y que siga por aprobar: los botones no son la barrera. --}}
                <form id="form-aprobar" method="POST" action="{{ route('solicitante.solicitudes.aprobar', $solicitud->id) }}">
                    @csrf
                </form>
                <form id="form-denegar" method="POST" action="{{ route('solicitante.solicitudes.denegar', $solicitud->id) }}"
                      data-confirmar="Denegar la solicitud {{ $solicitud->numero }}? No pasara al almacen y no se puede deshacer.">
                    @csrf
                </form>

                <div class="card border-0 shadow-sm mt-4">
                    <div class="card-body">
                        <h2 class="h6 mb-3">Su decision</h2>

                        <label for="motivo_denegacion" class="form-label small">Motivo (opcional, solo si deniega)</label>
                        <textarea class="form-control mb-3 @error('motivo_denegacion') is-invalid @enderror"
                                  id="motivo_denegacion" name="motivo_denegacion" form="form-denegar"
                                  rows="2" maxlength="1000"
                                  placeholder="Ej: ya hay existencias en la bodega de la obra.">{{ old('motivo_denegacion') }}</textarea>
                        @error('motivo_denegacion')
                            <div class="invalid-feedback d-block mb-3">{{ $message }}</div>
                        @enderror

                        <div class="d-flex flex-wrap justify-content-end gap-2">
                            <button type="submit" form="form-denegar" class="btn btn-outline-danger">
                                <i class="bi bi-x-circle me-1"></i>Denegar
                            </button>
                            <button type="submit" form="form-aprobar" class="btn btn-marca">
                                <i class="bi bi-check2-circle me-1"></i>Aprobar
                            </button>
                        </div>
                    </div>
                </div>
            @endif

        </div>
    </div>

@endsection
