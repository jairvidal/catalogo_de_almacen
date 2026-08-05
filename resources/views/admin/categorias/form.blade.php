@extends('layouts.admin')

@php
    $esNueva = ! $categoria->exists;
@endphp

@section('titulo', $esNueva ? 'Nueva categoria' : 'Editar '.$categoria->col_nombre)

@section('contenido')

    <a href="{{ route('admin.categorias.index') }}" class="text-decoration-none small">
        <i class="bi bi-arrow-left me-1"></i>Volver a categorias
    </a>

    <h1 class="h4 mt-2 mb-4">
        {{ $esNueva ? 'Nueva categoria' : 'Editar categoria '.$categoria->col_nombre }}
    </h1>

    @unless ($esNueva)
        <div class="alert alert-info d-flex align-items-start gap-2">
            <i class="bi bi-info-circle-fill mt-1"></i>
            <div>
                La clave <code>{{ $categoria->col_slug }}</code> no cambia: es con la que
                <code>repuestos:clasificar</code> reconoce esta categoria al leer el marco de
                las fotos. El nombre visible si se puede cambiar cuando quiera.
            </div>
        </div>
    @endunless

    <form method="POST"
          action="{{ $esNueva ? route('admin.categorias.store') : route('admin.categorias.update', $categoria) }}">
        @csrf
        @unless ($esNueva)
            @method('PUT')
        @endunless

        <div class="row g-4">

            <div class="col-lg-8">
                <div class="card border-0 shadow-sm">
                    <div class="card-body p-4">
                        <div class="row g-3">

                            <div class="col-sm-8">
                                <label for="col_nombre" class="form-label">Nombre <span class="text-danger">*</span></label>
                                <input type="text" class="form-control @error('col_nombre') is-invalid @enderror"
                                       id="col_nombre" name="col_nombre"
                                       value="{{ old('col_nombre', $categoria->col_nombre) }}"
                                       maxlength="60" required placeholder="Ej: Filtros de aire">
                                <div class="form-text">Es lo que ve el usuario en el filtro del catalogo publico.</div>
                                @error('col_nombre')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            <div class="col-sm-4">
                                <label for="col_color_hex" class="form-label">Color del marco <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    {{-- El valor es un color real de la foto, no un token de la
                                         paleta: el selector nativo trabaja con el hex crudo. --}}
                                    <input type="color" class="form-control form-control-color"
                                           id="col_color_hex_selector"
                                           value="{{ old('col_color_hex', $categoria->col_color_hex ?? '#D81818') }}"
                                           data-objetivo-color="col_color_hex"
                                           aria-label="Selector de color">
                                    <input type="text" class="form-control font-monospace @error('col_color_hex') is-invalid @enderror"
                                           id="col_color_hex" name="col_color_hex"
                                           value="{{ old('col_color_hex', $categoria->col_color_hex) }}"
                                           maxlength="7" required placeholder="#D81818">
                                    @error('col_color_hex')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>
                                <div class="form-text">Color del marco impreso en la foto.</div>
                            </div>

                            <div class="col-12">
                                <label for="col_descripcion" class="form-label">Descripcion</label>
                                <textarea class="form-control @error('col_descripcion') is-invalid @enderror"
                                          id="col_descripcion" name="col_descripcion" rows="3"
                                          maxlength="255">{{ old('col_descripcion', $categoria->col_descripcion) }}</textarea>
                                @error('col_descripcion')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            <div class="col-12">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" role="switch"
                                           id="col_activo" name="col_activo" value="1"
                                           @checked(old('col_activo', $categoria->col_activo ?? true))>
                                    <label class="form-check-label" for="col_activo">
                                        Categoria activa (aparece en el filtro del catalogo publico)
                                    </label>
                                </div>
                            </div>

                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-4">
                @unless ($esNueva)
                    <div class="card border-0 shadow-sm mb-3">
                        <div class="card-body">
                            <h2 class="h6 text-uppercase text-secondary mb-3" style="letter-spacing:.05em">
                                Repuestos en esta categoria
                            </h2>

                            @php
                                $totalRepuestos = $categoria->repuestos()->count();
                                $activosRepuestos = $categoria->repuestos()->where('activo', true)->count();
                            @endphp

                            @if ($totalRepuestos === 0)
                                <p class="text-secondary small mb-0">Ningun repuesto tiene asignada esta categoria.</p>
                            @else
                                <p class="mb-2">
                                    <span class="fs-4 fw-semibold">{{ $activosRepuestos }}</span>
                                    <span class="text-secondary small">activos de {{ $totalRepuestos }}</span>
                                </p>
                                <a href="{{ route('catalogo.index', ['categoria_id' => $categoria->id]) }}"
                                   class="btn btn-sm btn-outline-secondary" target="_blank" rel="noopener">
                                    <i class="bi bi-box-arrow-up-right me-1"></i>Verlos en el catalogo
                                </a>
                            @endif
                        </div>
                    </div>
                @endunless

                <div class="d-grid gap-2">
                    <button type="submit" class="btn btn-marca btn-lg">
                        <i class="bi bi-save me-1"></i>{{ $esNueva ? 'Crear categoria' : 'Guardar cambios' }}
                    </button>
                    <a href="{{ route('admin.categorias.index') }}" class="btn btn-outline-secondary">Cancelar</a>
                </div>
            </div>

        </div>
    </form>

@endsection
