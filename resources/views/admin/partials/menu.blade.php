@use('App\Models\Funcionalidad')

{{--
    Menu lateral del panel en grupos plegables (V 1.4.42).

    Se incluye DOS veces por pagina (menu lateral y offcanvas movil), por eso
    los ids del collapse llevan el prefijo $prefijo: repetidos, la flecha de
    una instancia abriria el grupo de la otra.

    Cada grupo es un arreglo con sus entradas; una entrada se pinta solo si
    'visible' es cierto (el perfil puede VER la funcionalidad). Ocultarla no es
    la barrera: la ruta vuelve a pedir el permiso. Un grupo sin entradas
    visibles y sin enlace propio no se pinta.

    Entrada con 'ruta' => null = modulo que todavia no existe: se pinta
    deshabilitada con "Proximamente". Convertirla en enlace real es darle su
    'ruta', su patron 'activo' y su condicion 'visible' con el permiso.
--}}
@php
    $prefijo = $prefijo ?? 'menu';
    $usuario = auth('web')->user();
    $puedeVer = fn (string $clave) => $usuario->puede($clave, Funcionalidad::ACCION_VER);
    $verSolicitudes = $puedeVer(Funcionalidad::SOLICITUDES);

    // Se cuenta lo que esta a la espera de accion del almacenista para el
    // indicador rojo del menu. Solo si el perfil ve las solicitudes.
    $porAtender = $verSolicitudes
        ? \App\Models\Solicitud::visiblesParaAlmacen()->whereIn('estado', [
            \App\Models\Solicitud::ESTADO_PENDIENTE,
            \App\Models\Solicitud::ESTADO_EN_PROCESO,
        ])->count()
        : 0;

    $grupos = [
        [
            'id' => 'solicitudes',
            'titulo' => 'Solicitudes',
            'icono' => 'inbox',
            // El encabezado lleva a la bandeja general; la flecha pliega.
            'enlace' => $verSolicitudes ? route('admin.solicitudes.index') : null,
            'enlace_activo' => $verSolicitudes && request()->routeIs('admin.solicitudes.index', 'admin.solicitudes.show'),
            'contador' => $porAtender,
            'entradas' => [
                ['visible' => $verSolicitudes, 'ruta' => 'admin.solicitudes.listos', 'activo' => 'admin.solicitudes.listos', 'icono' => 'bag-check', 'etiqueta' => 'Listos para reclamar'],
                ['visible' => $puedeVer(Funcionalidad::SOLICITANTES), 'ruta' => 'admin.solicitantes.index', 'activo' => 'admin.solicitantes.*', 'icono' => 'person-lines-fill', 'etiqueta' => 'Solicitantes del ERP'],
                ['visible' => $puedeVer(Funcionalidad::REPUESTOS), 'ruta' => 'admin.repuestos.index', 'activo' => 'admin.repuestos.*', 'icono' => 'box-seam', 'etiqueta' => 'Catalogo e inventario'],
                ['visible' => $puedeVer(Funcionalidad::CATEGORIAS), 'ruta' => 'admin.categorias.index', 'activo' => 'admin.categorias.*', 'icono' => 'tags', 'etiqueta' => 'Categorias'],
                ['visible' => true, 'ruta' => 'catalogo.index', 'activo' => null, 'icono' => 'box-arrow-up-right', 'etiqueta' => 'Ver catalogo publico', 'externo' => true],
            ],
        ],
        [
            'id' => 'configuracion',
            'titulo' => 'Configuracion',
            'icono' => 'gear',
            'entradas' => [
                ['visible' => $puedeVer(Funcionalidad::ROLES), 'ruta' => 'admin.roles.index', 'activo' => 'admin.roles.*', 'icono' => 'person-badge', 'etiqueta' => 'Roles'],
                ['visible' => $puedeVer(Funcionalidad::PERMISOS), 'ruta' => 'admin.permisos.index', 'activo' => 'admin.permisos.*', 'icono' => 'ui-checks-grid', 'etiqueta' => 'Funciones por perfil'],
                ['visible' => $puedeVer(Funcionalidad::PARAMETROS), 'ruta' => 'admin.parametros.index', 'activo' => 'admin.parametros.*', 'icono' => 'sliders', 'etiqueta' => 'Parametros'],
            ],
        ],
        // Modulos sin construir: no hay rutas ni funcionalidad en la matriz.
        [
            'id' => 'ordenes-salida',
            'titulo' => 'Ordenes de salida',
            'icono' => 'box-arrow-up',
            'entradas' => [
                ['visible' => true, 'ruta' => null, 'activo' => null, 'icono' => 'list-ul', 'etiqueta' => 'Listados'],
                ['visible' => true, 'ruta' => null, 'activo' => null, 'icono' => 'bar-chart', 'etiqueta' => 'Reportes'],
            ],
        ],
        [
            'id' => 'registro-entradas',
            'titulo' => 'Registro de entradas',
            'icono' => 'box-arrow-in-down',
            'entradas' => [
                ['visible' => true, 'ruta' => null, 'activo' => null, 'icono' => 'journal-plus', 'etiqueta' => 'Registro'],
                ['visible' => true, 'ruta' => null, 'activo' => null, 'icono' => 'bar-chart', 'etiqueta' => 'Reportes'],
            ],
        ],
    ];

    $grupos = array_values(array_filter(array_map(function (array $grupo) {
        $grupo['entradas'] = array_values(array_filter(
            $grupo['entradas'],
            fn (array $entrada) => $entrada['visible']
        ));

        foreach ($grupo['entradas'] as $i => $entrada) {
            $grupo['entradas'][$i]['es_activa'] = $entrada['activo'] !== null && request()->routeIs($entrada['activo']);
        }

        $grupo['enlace'] ??= null;
        $grupo['enlace_activo'] ??= false;
        $grupo['contador'] ??= 0;
        // El grupo que contiene la pantalla actual arranca abierto.
        $grupo['abierto'] = $grupo['enlace_activo']
            || in_array(true, array_column($grupo['entradas'], 'es_activa'), true);

        return $grupo;
    }, $grupos), fn (array $grupo) => $grupo['entradas'] !== [] || $grupo['enlace'] !== null));
@endphp

<ul class="nav flex-column menu-panel">
    @foreach ($grupos as $grupo)
        @php($idGrupo = $prefijo.'-grupo-'.$grupo['id'])

        <li class="nav-item menu-grupo">
            <div class="menu-grupo-encabezado {{ $grupo['abierto'] ? 'contiene-activo' : '' }}">
                @if ($grupo['enlace'])
                    <a class="menu-grupo-titulo {{ $grupo['enlace_activo'] ? 'active' : '' }}"
                       href="{{ $grupo['enlace'] }}"
                       @if ($grupo['enlace_activo']) aria-current="page" @endif>
                        <i class="bi bi-{{ $grupo['icono'] }} me-2" aria-hidden="true"></i>
                        <span class="flex-grow-1">{{ $grupo['titulo'] }}</span>
                        @if ($grupo['contador'] > 0)
                            <span class="badge rounded-pill text-bg-danger">{{ $grupo['contador'] }}</span>
                        @endif
                    </a>
                    @if ($grupo['entradas'] !== [])
                        <button type="button" class="menu-grupo-plegar {{ $grupo['abierto'] ? '' : 'collapsed' }}"
                                data-bs-toggle="collapse" data-bs-target="#{{ $idGrupo }}"
                                aria-controls="{{ $idGrupo }}"
                                aria-expanded="{{ $grupo['abierto'] ? 'true' : 'false' }}"
                                aria-label="Opciones de {{ $grupo['titulo'] }}">
                            <i class="bi bi-chevron-down menu-grupo-flecha" aria-hidden="true"></i>
                        </button>
                    @endif
                @else
                    <button type="button" class="menu-grupo-titulo {{ $grupo['abierto'] ? '' : 'collapsed' }}"
                            data-bs-toggle="collapse" data-bs-target="#{{ $idGrupo }}"
                            aria-controls="{{ $idGrupo }}"
                            aria-expanded="{{ $grupo['abierto'] ? 'true' : 'false' }}">
                        <i class="bi bi-{{ $grupo['icono'] }} me-2" aria-hidden="true"></i>
                        <span class="flex-grow-1 text-start">{{ $grupo['titulo'] }}</span>
                        <i class="bi bi-chevron-down menu-grupo-flecha" aria-hidden="true"></i>
                    </button>
                @endif
            </div>

            @if ($grupo['entradas'] !== [])
                <div class="collapse {{ $grupo['abierto'] ? 'show' : '' }}" id="{{ $idGrupo }}">
                    <ul class="nav flex-column menu-subnivel">
                        @foreach ($grupo['entradas'] as $entrada)
                            <li class="nav-item">
                                @if ($entrada['ruta'] === null)
                                    {{-- Modulo pendiente: sin href, no navegable. --}}
                                    <a class="nav-link d-flex align-items-center menu-entrada-pendiente"
                                       role="link" aria-disabled="true">
                                        <i class="bi bi-{{ $entrada['icono'] }} me-2" aria-hidden="true"></i>
                                        <span class="flex-grow-1">{{ $entrada['etiqueta'] }}</span>
                                        <span class="menu-proximamente">Proximamente</span>
                                    </a>
                                @elseif ($entrada['externo'] ?? false)
                                    <a class="nav-link" href="{{ route($entrada['ruta']) }}" target="_blank" rel="noopener">
                                        <i class="bi bi-{{ $entrada['icono'] }} me-2" aria-hidden="true"></i>{{ $entrada['etiqueta'] }}
                                    </a>
                                @else
                                    <a class="nav-link {{ $entrada['es_activa'] ? 'active' : '' }}"
                                       href="{{ route($entrada['ruta']) }}"
                                       @if ($entrada['es_activa']) aria-current="page" @endif>
                                        <i class="bi bi-{{ $entrada['icono'] }} me-2" aria-hidden="true"></i>{{ $entrada['etiqueta'] }}
                                    </a>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </li>
    @endforeach
</ul>
