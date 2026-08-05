@extends('layouts.admin')

@php
    $esNuevo = ! $rol->exists;
    $bloqueado = $rol->es_del_sistema;
@endphp

@section('titulo', $esNuevo ? 'Nuevo rol' : 'Editar '.$rol->col_nombre)

@section('contenido')

    <a href="{{ route('admin.roles.index') }}" class="text-decoration-none small">
        <i class="bi bi-arrow-left me-1"></i>Volver a roles
    </a>

    <h1 class="h4 mt-2 mb-4">
        {{ $esNuevo ? 'Nuevo rol' : 'Editar rol '.$rol->col_nombre }}
    </h1>

    @if ($bloqueado)
        <div class="alert alert-info d-flex align-items-start gap-2">
            <i class="bi bi-info-circle-fill mt-1"></i>
            <div>
                Este es un rol del sistema: se pueden ajustar el nombre y la descripcion, pero la
                clave, el permiso sobre el catalogo y el estado quedan fijos para no dejar a nadie
                por fuera del panel.
            </div>
        </div>
    @endif

    <form method="POST"
          action="{{ $esNuevo ? route('admin.roles.store') : route('admin.roles.update', $rol) }}">
        @csrf
        @unless ($esNuevo)
            @method('PUT')
        @endunless

        <div class="row g-4">

            <div class="col-lg-8">
                <div class="card border-0 shadow-sm">
                    <div class="card-body p-4">
                        <div class="row g-3">

                            <div class="col-sm-5">
                                <label for="col_clave" class="form-label">Clave <span class="text-danger">*</span></label>
                                <input type="text" class="form-control font-monospace @error('col_clave') is-invalid @enderror"
                                       id="col_clave" name="col_clave"
                                       value="{{ old('col_clave', $rol->col_clave) }}"
                                       maxlength="20" required @disabled($bloqueado)
                                       placeholder="Ej: supervisor">
                                <div class="form-text">Minusculas, numeros y guion bajo. No se repite.</div>
                                @error('col_clave')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            <div class="col-sm-7">
                                <label for="col_nombre" class="form-label">Nombre <span class="text-danger">*</span></label>
                                <input type="text" class="form-control @error('col_nombre') is-invalid @enderror"
                                       id="col_nombre" name="col_nombre"
                                       value="{{ old('col_nombre', $rol->col_nombre) }}"
                                       maxlength="60" required>
                                @error('col_nombre')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            <div class="col-12">
                                <label for="col_descripcion" class="form-label">Descripcion</label>
                                <textarea class="form-control @error('col_descripcion') is-invalid @enderror"
                                          id="col_descripcion" name="col_descripcion" rows="3"
                                          maxlength="255">{{ old('col_descripcion', $rol->col_descripcion) }}</textarea>
                                @error('col_descripcion')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            <div class="col-12">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" role="switch"
                                           id="col_gestiona_catalogo" name="col_gestiona_catalogo" value="1"
                                           @checked(old('col_gestiona_catalogo', $rol->col_gestiona_catalogo))
                                           @disabled($bloqueado)>
                                    <label class="form-check-label" for="col_gestiona_catalogo">
                                        Puede gestionar el catalogo, el inventario y los roles
                                    </label>
                                </div>
                                <div class="form-text">
                                    Sin esta marca el usuario entra al panel y solo despacha solicitudes.
                                </div>
                            </div>

                            <div class="col-12">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" role="switch"
                                           id="col_activo" name="col_activo" value="1"
                                           @checked(old('col_activo', $rol->col_activo ?? true))
                                           @disabled($bloqueado)>
                                    <label class="form-check-label" for="col_activo">
                                        Rol activo (se puede asignar a usuarios)
                                    </label>
                                </div>
                            </div>

                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-4">
                @unless ($esNuevo)
                    <div class="card border-0 shadow-sm mb-3">
                        <div class="card-body">
                            <h2 class="h6 text-uppercase text-secondary mb-3" style="letter-spacing:.05em">
                                Usuarios con este rol
                            </h2>
                            @php $usuarios = $rol->usuarios()->orderBy('name')->get(); @endphp

                            @if ($usuarios->isEmpty())
                                <p class="text-secondary small mb-0">Ningun usuario tiene asignado este rol.</p>
                            @else
                                <ul class="list-unstyled mb-0 small">
                                    @foreach ($usuarios as $usuario)
                                        <li class="d-flex justify-content-between gap-2 py-1 border-bottom">
                                            <span class="text-truncate">{{ $usuario->name }}</span>
                                            <span class="badge text-bg-{{ $usuario->activo ? 'success' : 'secondary' }}">
                                                {{ $usuario->activo ? 'Activo' : 'Inactivo' }}
                                            </span>
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                        </div>
                    </div>
                @endunless

                <div class="d-grid gap-2">
                    <button type="submit" class="btn btn-marca btn-lg">
                        <i class="bi bi-save me-1"></i>{{ $esNuevo ? 'Crear rol' : 'Guardar cambios' }}
                    </button>
                    <a href="{{ route('admin.roles.index') }}" class="btn btn-outline-secondary">Cancelar</a>
                </div>
            </div>

        </div>
    </form>

@endsection
