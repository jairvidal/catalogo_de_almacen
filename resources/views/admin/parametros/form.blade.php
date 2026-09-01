@extends('layouts.admin')

@php
    $esNuevo = ! $parametro->exists;
    $bloqueado = $parametro->es_del_sistema;
    $sensible = $parametro->es_sensible;
    // Un parametro de lista cerrada se edita con botones de radio; el resto,
    // con el campo de texto de siempre. Las opciones viven en Parametro::OPCIONES
    // y las valida el servidor, no solo el HTML.
    $opciones = $parametro->opciones;
    // Los criterios de la API son listas separadas por coma (ver Parametro::LISTAS);
    // el campo es el mismo de texto, lo que cambia es la ayuda del formulario.
    // Los de LISTAS_ENTERAS ademas solo admiten IDs numericos, y eso lo valida
    // el servidor en ParametroRequest.
    $esLista = $parametro->es_lista;
    $esListaEntera = $parametro->es_lista_entera;
@endphp

@section('titulo', $esNuevo ? 'Nuevo parametro' : 'Editar '.$parametro->col_nombre)

@section('contenido')

    <a href="{{ route('admin.parametros.index') }}" class="text-decoration-none small">
        <i class="bi bi-arrow-left me-1"></i>Volver a parametros
    </a>

    <h1 class="h4 mt-2 mb-4">
        {{ $esNuevo ? 'Nuevo parametro' : 'Editar parametro '.$parametro->col_nombre }}
    </h1>

    @if ($bloqueado)
        <div class="alert alert-info d-flex align-items-start gap-2">
            <i class="bi bi-info-circle-fill mt-1"></i>
            <div>
                Este es un parametro del sistema: el valor y la descripcion se pueden ajustar, pero el
                nombre y el estado quedan fijos porque el codigo lo pide por nombre y apagarlo dejaria
                la sincronizacion con el ERP sin configuracion.
            </div>
        </div>
    @endif

    <form method="POST"
          action="{{ $esNuevo ? route('admin.parametros.store') : route('admin.parametros.update', $parametro) }}"
          autocomplete="off">
        @csrf
        @unless ($esNuevo)
            @method('PUT')
        @endunless

        <div class="row g-4">

            <div class="col-lg-8">
                <div class="card border-0 shadow-sm">
                    <div class="card-body p-4">
                        <div class="row g-3">

                            <div class="col-sm-6">
                                <label for="col_nombre" class="form-label">Nombre <span class="text-danger">*</span></label>
                                <input type="text" class="form-control font-monospace @error('col_nombre') is-invalid @enderror"
                                       id="col_nombre" name="col_nombre"
                                       value="{{ old('col_nombre', $parametro->col_nombre) }}"
                                       maxlength="100" required @disabled($bloqueado)
                                       placeholder="Ej: api.id_bod">
                                <div class="form-text">Minusculas, numeros, punto y guion bajo. No se repite.</div>
                                @error('col_nombre')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            <div class="col-sm-6">
                                {{-- Sin "for" cuando son radios: ahi no hay un solo campo al que apuntar. --}}
                                <label @if ($opciones === []) for="col_valor" @endif class="form-label">
                                    Valor
                                    @if ($sensible)
                                        <i class="bi bi-shield-lock ms-1 text-secondary" title="Credencial"></i>
                                    @endif
                                </label>

                                @if ($opciones !== [])
                                    {{-- Lista cerrada: botones de radio. --}}
                                    <div class="pt-1">
                                        @foreach ($opciones as $clave => $etiqueta)
                                            <div class="form-check">
                                                <input class="form-check-input @error('col_valor') is-invalid @enderror"
                                                       type="radio" name="col_valor"
                                                       id="col_valor_{{ $clave }}" value="{{ $clave }}"
                                                       @checked(old('col_valor', $parametro->col_valor) === $clave)>
                                                <label class="form-check-label" for="col_valor_{{ $clave }}">
                                                    {{ $etiqueta }}
                                                </label>
                                            </div>
                                        @endforeach
                                    </div>

                                    <div class="form-text">
                                        Este parametro solo admite las opciones de la lista.
                                    </div>
                                    @error('col_valor')
                                        <div class="text-danger small mt-1">{{ $message }}</div>
                                    @enderror
                                @else
                                    {{-- De una credencial no se manda el valor al navegador: el campo
                                         va vacio y solo se escribe si el administrador digita uno nuevo. --}}
                                    <input type="{{ $sensible ? 'password' : 'text' }}"
                                           class="form-control font-monospace @error('col_valor') is-invalid @enderror"
                                           id="col_valor" name="col_valor"
                                           value="{{ $sensible ? '' : old('col_valor', $parametro->col_valor) }}"
                                           maxlength="255" autocomplete="new-password"
                                           placeholder="{{ $sensible && ! $esNuevo ? 'Dejar vacio conserva el valor actual' : '' }}">

                                    <div class="form-text">
                                        @if ($sensible)
                                            Es una credencial: no se muestra ni se registra en el log.
                                            {{ $esNuevo ? '' : 'Si deja el campo vacio se conserva el valor guardado.' }}
                                        @elseif ($esListaEntera)
                                            Es una lista de IDs numericos del ERP separados por coma
                                            (ej: <span class="font-monospace">3038,1230</span>).
                                            Dejarlo vacio envia la lista vacia, es decir sin filtro.
                                            Los espacios alrededor de cada ID no cuentan; un valor que no
                                            sea numerico se rechaza.
                                        @elseif ($esLista)
                                            Es una lista: escriba los valores separados por coma.
                                            Dejarlo vacio envia la lista vacia, es decir sin filtro.
                                            Se guarda tal cual, incluidos los espacios del inicio y del final.
                                        @else
                                            Se guarda tal cual, incluidos los espacios del inicio y del final.
                                        @endif
                                    </div>
                                    @error('col_valor')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                @endif
                            </div>

                            <div class="col-12">
                                <label for="col_descripcion" class="form-label">Descripcion</label>
                                <textarea class="form-control @error('col_descripcion') is-invalid @enderror"
                                          id="col_descripcion" name="col_descripcion" rows="3"
                                          maxlength="255">{{ old('col_descripcion', $parametro->col_descripcion) }}</textarea>
                                <div class="form-text">Para que el proximo administrador sepa que hace este parametro.</div>
                                @error('col_descripcion')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            <div class="col-sm-6">
                                <label for="col_estado" class="form-label">Estado <span class="text-danger">*</span></label>
                                <select class="form-select @error('col_estado') is-invalid @enderror"
                                        id="col_estado" name="col_estado" required @disabled($bloqueado)>
                                    @foreach (\App\Models\Parametro::ESTADOS as $clave => $etiqueta)
                                        <option value="{{ $clave }}"
                                            @selected(old('col_estado', $parametro->col_estado) === $clave)>
                                            {{ $etiqueta }}
                                        </option>
                                    @endforeach
                                </select>
                                <div class="form-text">Un parametro inactivo no lo lee el sistema.</div>
                                @error('col_estado')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-4">
                <div class="d-grid gap-2">
                    <button type="submit" class="btn btn-marca btn-lg">
                        <i class="bi bi-save me-1"></i>{{ $esNuevo ? 'Crear parametro' : 'Guardar cambios' }}
                    </button>
                    <a href="{{ route('admin.parametros.index') }}" class="btn btn-outline-secondary">Cancelar</a>
                </div>
            </div>

        </div>
    </form>

@endsection
