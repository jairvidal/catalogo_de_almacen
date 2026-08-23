@extends('layouts.admin')

@php $esNuevo = ! $repuesto->exists; @endphp

@section('titulo', $esNuevo ? 'Nuevo repuesto' : 'Editar '.$repuesto->codigo)

@section('contenido')

    <a href="{{ route('admin.repuestos.index') }}" class="text-decoration-none small">
        <i class="bi bi-arrow-left me-1"></i>Volver al catalogo
    </a>

    <h1 class="h4 mt-2 mb-4">
        {{ $esNuevo ? 'Nuevo repuesto' : 'Editar repuesto '.$repuesto->codigo }}
    </h1>

    <form method="POST" enctype="multipart/form-data"
          action="{{ $esNuevo ? route('admin.repuestos.store') : route('admin.repuestos.update', $repuesto) }}">
        @csrf
        @unless ($esNuevo)
            @method('PUT')
        @endunless

        <div class="row g-4">

            <div class="col-lg-8">
                <div class="card border-0 shadow-sm">
                    <div class="card-body p-4">
                        <div class="row g-3">

                            <div class="col-sm-4">
                                <label for="codigo" class="form-label">Codigo <span class="text-danger">*</span></label>
                                <input type="number" class="form-control font-monospace @error('codigo') is-invalid @enderror"
                                       id="codigo" name="codigo" value="{{ old('codigo', $repuesto->codigo) }}"
                                       min="1" step="1" required>
                                <div class="form-text">Codigo del item en el ERP.</div>
                                @error('codigo')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            <div class="col-sm-4">
                                <label for="cod_referencia" class="form-label">Referencia</label>
                                <input type="text" class="form-control font-monospace @error('cod_referencia') is-invalid @enderror"
                                       id="cod_referencia" name="cod_referencia"
                                       value="{{ old('cod_referencia', $repuesto->cod_referencia) }}" maxlength="50">
                                <div class="form-text">Referencia comercial alterna.</div>
                                @error('cod_referencia')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            <div class="col-sm-4">
                                <label for="ubicacion" class="form-label">Ubicacion en almacen</label>
                                <input type="text" class="form-control @error('ubicacion') is-invalid @enderror"
                                       id="ubicacion" name="ubicacion"
                                       value="{{ old('ubicacion', $repuesto->ubicacion) }}" maxlength="30"
                                       placeholder="Ej: Estante A-01">
                                @error('ubicacion')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            <div class="col-12">
                                <label for="nombre" class="form-label">Nombre <span class="text-danger">*</span></label>
                                <input type="text" class="form-control @error('nombre') is-invalid @enderror"
                                       id="nombre" name="nombre" value="{{ old('nombre', $repuesto->nombre) }}"
                                       maxlength="300" required>
                                @error('nombre')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            <div class="col-sm-6">
                                <label for="desc_cat_1" class="form-label">Grupo</label>
                                <input type="text" class="form-control @error('desc_cat_1') is-invalid @enderror"
                                       id="desc_cat_1" name="desc_cat_1" list="listaGrupos"
                                       value="{{ old('desc_cat_1', $repuesto->desc_cat_1) }}" maxlength="50">
                                <datalist id="listaGrupos">
                                    @foreach ($categorias as $categoria)
                                        <option value="{{ $categoria }}"></option>
                                    @endforeach
                                </datalist>
                                @error('desc_cat_1')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            <div class="col-sm-6">
                                <label for="desc_cat_2" class="form-label">Subgrupo</label>
                                <input type="text" class="form-control @error('desc_cat_2') is-invalid @enderror"
                                       id="desc_cat_2" name="desc_cat_2"
                                       value="{{ old('desc_cat_2', $repuesto->desc_cat_2) }}" maxlength="50">
                                <div class="form-text">Agrupa los repuestos relacionados del catalogo.</div>
                                @error('desc_cat_2')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            <div class="col-sm-3">
                                <label for="unidad_medida" class="form-label">
                                    Unidad de medida <span class="text-danger">*</span>
                                </label>
                                <input type="text" class="form-control @error('unidad_medida') is-invalid @enderror"
                                       id="unidad_medida" name="unidad_medida"
                                       value="{{ old('unidad_medida', $repuesto->unidad_medida) }}"
                                       maxlength="10" required placeholder="UND, MTR, KG...">
                                @error('unidad_medida')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            <div class="col-sm-3">
                                <label for="existencia" class="form-label">
                                    Existencia <span class="text-danger">*</span>
                                </label>
                                <input type="number" class="form-control @error('existencia') is-invalid @enderror"
                                       id="existencia" name="existencia"
                                       value="{{ old('existencia', $repuesto->existencia ?? 0) }}"
                                       min="0" step="0.001" required>
                                <div class="form-text">Saldo que despacha el almacen.</div>
                                @error('existencia')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            <div class="col-sm-3">
                                <label for="stock_minimo" class="form-label">
                                    Stock minimo <span class="text-danger">*</span>
                                </label>
                                <input type="number" class="form-control @error('stock_minimo') is-invalid @enderror"
                                       id="stock_minimo" name="stock_minimo"
                                       value="{{ old('stock_minimo', $repuesto->stock_minimo ?? 0) }}"
                                       min="0" step="0.001" required>
                                <div class="form-text">Por debajo se marca "existencias bajas".</div>
                                @error('stock_minimo')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            <div class="col-sm-3">
                                {{-- Solo lectura: stock lo escribe la sincronizacion con el ERP y
                                     cualquier cambio a mano se perderia en la siguiente corrida. --}}
                                <label for="stock" class="form-label">Stock del ERP</label>
                                <input type="text" class="form-control-plaintext px-2 border rounded bg-body-secondary"
                                       id="stock" value="{{ $repuesto->stock ?? 0 }}" readonly disabled>
                                <div class="form-text">Lo actualiza el ERP.</div>
                            </div>

                            <div class="col-12">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" role="switch" id="estado"
                                           name="estado" value="1"
                                           @checked(old('estado', $repuesto->estado ?? \App\Models\Repuesto::ESTADO_ACTIVO) == \App\Models\Repuesto::ESTADO_ACTIVO)>
                                    <label class="form-check-label" for="estado">
                                        Visible en el catalogo publico
                                    </label>
                                </div>
                            </div>

                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-4">
                <div class="card border-0 shadow-sm">
                    <div class="card-body">
                        <h2 class="h6 text-uppercase text-secondary mb-3" style="letter-spacing:.05em">Foto</h2>

                        <img src="{{ $repuesto->foto_url }}" alt="Foto del repuesto"
                             class="foto-detalle mb-3" id="vistaPrevia">

                        <label for="imagen" class="form-label">Cambiar imagen</label>
                        <input type="file" class="form-control @error('imagen') is-invalid @enderror"
                               id="imagen" name="imagen" accept="image/jpeg,image/png,image/webp">
                        @error('imagen')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                        <div class="form-text">JPG, PNG o WEBP. Maximo 4 MB.</div>

                        @if ($repuesto->foto)
                            <p class="small text-secondary mt-2 mb-0 text-break">
                                Archivo actual: <code>{{ $repuesto->foto }}</code>
                            </p>
                        @endif
                    </div>
                </div>

                <div class="d-grid gap-2 mt-3">
                    <button type="submit" class="btn btn-marca btn-lg">
                        <i class="bi bi-save me-1"></i>{{ $esNuevo ? 'Crear repuesto' : 'Guardar cambios' }}
                    </button>
                    <a href="{{ route('admin.repuestos.index') }}" class="btn btn-outline-secondary">Cancelar</a>
                </div>
            </div>

        </div>
    </form>

@endsection

@push('scripts')
    <script>
        // Vista previa inmediata de la imagen seleccionada.
        document.getElementById('imagen')?.addEventListener('change', function () {
            const archivo = this.files?.[0];

            if (archivo) {
                document.getElementById('vistaPrevia').src = URL.createObjectURL(archivo);
            }
        });
    </script>
@endpush
