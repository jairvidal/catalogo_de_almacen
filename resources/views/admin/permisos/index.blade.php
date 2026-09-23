@extends('layouts.admin')

@section('titulo', 'Funciones por perfil')

@use('App\Models\Funcionalidad')

@php
    // Casillas bloqueadas: el admin del sistema conserva todo (el servidor lo
    // impone igual) y quien solo puede ver la matriz no la cambia.
    $esAdminDelSistema = $rol?->es_admin_del_sistema ?? false;
    $bloqueada = ! $rol || $esAdminDelSistema || ! $puedeGuardar;
@endphp

@section('contenido')

    <div class="mb-4">
        <h1 class="h4 mb-1">
            <i class="bi bi-ui-checks-grid me-2 text-marca"></i>Funciones por perfil
        </h1>
        <p class="text-secondary mb-0 small">
            Seleccione un perfil y marque, por cada funcionalidad, los permisos (ver, editar, eliminar) que sus
            usuarios podran ejecutar. Las acciones no marcadas apareceran en gris y deshabilitadas en los listados.
            Los perfiles y funcionalidades nuevos aparecen aqui automaticamente.
        </p>
    </div>

    {{-- ------------------------------------------------------------------ --}}
    {{-- Seleccion del perfil                                               --}}
    {{-- ------------------------------------------------------------------ --}}
    <form method="GET" action="{{ route('admin.permisos.index') }}" class="card border-0 shadow-sm mb-3">
        <div class="card-header bg-white py-3">
            <h2 class="h6 mb-0"><i class="bi bi-person-vcard me-2"></i>Seleccionar perfil</h2>
        </div>
        <div class="card-body">
            @if ($roles->isEmpty())
                <p class="text-secondary small mb-0">No hay perfiles activos. Cree uno en Roles.</p>
            @else
                <label for="rol_id" class="form-label">Perfil</label>
                <div class="d-flex gap-2 selector-perfil">
                    <select class="form-select" id="rol_id" name="rol_id" data-autoenviar>
                        @foreach ($roles as $opcion)
                            <option value="{{ $opcion->id }}" @selected($rol?->id === $opcion->id)>
                                {{ $opcion->col_nombre }}
                            </option>
                        @endforeach
                    </select>
                    {{-- Sin JavaScript el select no se autoenvia. --}}
                    <noscript>
                        <button type="submit" class="btn btn-outline-secondary">Ver</button>
                    </noscript>
                </div>
            @endif
        </div>
    </form>

    {{-- ------------------------------------------------------------------ --}}
    {{-- Matriz del perfil                                                  --}}
    {{-- ------------------------------------------------------------------ --}}
    @if ($rol)
        <form method="POST" action="{{ route('admin.permisos.update', $rol) }}" class="card border-0 shadow-sm"
              data-matriz-permisos>
            @csrf
            @method('PUT')

            <div class="card-header bg-white py-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
                <h2 class="h6 mb-0">
                    <i class="bi bi-grid-3x3-gap me-2"></i>Funcionalidades del sistema
                    <span class="text-secondary fw-normal">&mdash; {{ $rol->col_nombre }}</span>
                </h2>

                <div class="btn-group btn-group-sm" role="group" aria-label="Marcar en bloque">
                    <button type="button" class="btn btn-outline-secondary" data-permisos-marcar="todo"
                            @disabled($bloqueada)>
                        <i class="bi bi-check2-all me-1"></i>Seleccionar todo
                    </button>
                    <button type="button" class="btn btn-outline-secondary" data-permisos-marcar="nada"
                            @disabled($bloqueada)>
                        <i class="bi bi-x-lg me-1"></i>Limpiar
                    </button>
                </div>
            </div>

            <div class="card-body">
                @if ($esAdminDelSistema)
                    <div class="alert alert-info d-flex align-items-start gap-2">
                        <i class="bi bi-shield-check mt-1"></i>
                        <div class="small">
                            <strong>{{ $rol->col_nombre }}</strong> es el perfil administrador del sistema y conserva
                            siempre todos los permisos, para que el panel nunca quede sin alguien capaz de devolverlos.
                        </div>
                    </div>
                @elseif (! $matriz['configurada'])
                    <div class="alert alert-warning d-flex align-items-start gap-2">
                        <i class="bi bi-exclamation-triangle-fill mt-1"></i>
                        <div class="small">
                            Este perfil todavia no tiene funciones guardadas. Se muestran las que hoy tiene por la marca
                            "Puede gestionar el catalogo" del rol. Al guardar, esta matriz pasa a mandar.
                        </div>
                    </div>
                @endif

                @if (! $puedeGuardar)
                    <div class="alert alert-info d-flex align-items-start gap-2">
                        <i class="bi bi-lock-fill mt-1"></i>
                        <div class="small">Su perfil puede consultar esta matriz, pero no modificarla.</div>
                    </div>
                @endif

                @forelse ($matriz['secciones'] as $seccion => $funcionalidades)
                    <h3 class="seccion-permisos">{{ $seccion }}</h3>

                    <div class="row row-cols-1 row-cols-md-2 row-cols-xl-3 g-2 mb-3">
                        @foreach ($funcionalidades as $funcionalidad)
                            <div class="col">
                                <div class="tarjeta-permiso h-100 {{ in_array(true, $funcionalidad['permisos'], true) ? 'con-permiso' : '' }}"
                                     data-tarjeta-permiso>
                                    <div class="fw-semibold mb-2">
                                        <i class="bi bi-{{ $funcionalidad['icono'] }} me-2 text-secondary"></i>{{ $funcionalidad['nombre'] }}
                                    </div>

                                    <div class="d-flex flex-wrap column-gap-3 row-gap-1">
                                        @foreach (Funcionalidad::ACCIONES as $accion => $etiqueta)
                                            @php $campo = "permiso-{$funcionalidad['id']}-{$accion}"; @endphp
                                            <div class="form-check mb-0">
                                                <input class="form-check-input" type="checkbox" value="1"
                                                       id="{{ $campo }}"
                                                       name="permisos[{{ $funcionalidad['id'] }}][{{ $accion }}]"
                                                       data-accion-permiso="{{ $accion }}"
                                                       @checked($funcionalidad['permisos'][$accion])
                                                       @disabled($bloqueada || $funcionalidad['reservada'])>
                                                <label class="form-check-label" for="{{ $campo }}">{{ $etiqueta }}</label>
                                            </div>
                                        @endforeach
                                    </div>

                                    {{-- Reservada: marcarla no concederia nada, asi que se bloquea y se
                                         dice por que. La barrera real esta en User::puede(). --}}
                                    @if ($funcionalidad['reservada'])
                                        <p class="text-secondary small mb-0 mt-2" data-permiso-reservado="{{ $funcionalidad['clave'] }}">
                                            <i class="bi bi-shield-lock me-1"></i>Reservada a los perfiles administradores;
                                            marcarla en este perfil no le daria acceso.
                                        </p>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                @empty
                    <div class="text-center py-4">
                        <i class="bi bi-ui-checks-grid fs-1 text-secondary opacity-50"></i>
                        <p class="text-secondary mt-3 mb-0 small">
                            No hay funcionalidades registradas. Corra
                            <code>php artisan db:seed --class=FuncionalidadSeeder</code>.
                        </p>
                    </div>
                @endforelse
            </div>

            @if ($matriz['secciones'] !== [])
                <div class="card-footer bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <small class="text-secondary">Marcar Editar o Eliminar marca tambien Ver.</small>
                    <button type="submit" class="btn btn-marca" @disabled($bloqueada)>
                        <i class="bi bi-save me-1"></i>Guardar permisos
                    </button>
                </div>
            @endif
        </form>
    @endif

@endsection
