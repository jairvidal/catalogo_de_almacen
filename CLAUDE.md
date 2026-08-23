# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Qué es

Catálogo público de repuestos de almacén (Laravel 12 / PHP 8.2 / SQL Server). Cualquier persona busca repuestos, arma un pedido y lo envía dejando nombre, cédula y correo — **no hay registro de usuarios públicos**. El personal del almacén entra a `/admin` a despachar esas solicitudes.


## Comandos

```powershell
php artisan serve                      # servidor de desarrollo (http://localhost:8000)
composer dev                           # serve + queue:listen + pail + vite en paralelo
php artisan migrate                    # crear/actualizar el esquema en SQL Server
php artisan db:seed                    # usuarios internos + repuestos desde public/img
php artisan repuestos:importar ruta.csv --separador=";" --crear   # carga masiva desde CSV
php artisan repuestos:clasificar [--forzar] [--simular]           # categoria segun el color del marco de la foto
php artisan repuestos:sincronizar-stock [--simular]               # trae repuestos.stock desde la API de inventario
php artisan schedule:work                                         # dispara las tareas programadas (ver sincronizacion)
php artisan test                       # PHPUnit
php artisan test --filter=NombreDelTest
vendor/bin/pint                        # formateo (Laravel Pint)
```

Usuarios de prueba `UsuarioSeeder`: `admin@sidocsa.com` y `almacenista@sidocsa.com`, contraseña `Almacen2026*`.

## Base de datos

`DB_CONNECTION=sqlsrv`. Con instancia con nombre (`localhost\SQLEXPRESS`) **`DB_PORT` debe quedar vacío** para que el SQL Server Browser resuelva el puerto; con instancia por defecto se usa 1433.

`phpunit.xml` tiene las líneas de SQLite en memoria **comentadas**, así que los tests corren contra la base configurada en `.env`. Descoméntelas antes de escribir tests que usen `RefreshDatabase`.

Consecuencias de usar SQL Server que ya están resueltas en el código y hay que respetar:
- Escape de `LIKE` con corchetes: `str_replace(['%','_'], ['[%]','[_]'], $termino)` (ver los scopes `buscar()`).
- Límite de 2100 parámetros por sentencia: `RepuestoSeeder` hace `upsert` en lotes de 120.
- Violación de índice único = códigos de error 2601/2627 (ver `SolicitudService::crearConReintento`).

## Arquitectura

### Flujo del pedido

1. **Carrito en sesión** — `App\Services\Carrito` guarda un mapa `repuesto_id => cantidad` en la sesión. Topa siempre la cantidad al stock disponible y descarta solo las referencias que ya no existan o estén inactivas. Es el único lugar que toca la clave de sesión `carrito`.
2. **Creación** — `SolicitudService::crearDesdeCarrito()` **relee los repuestos dentro de la transacción** para validar contra el stock real, no contra el que vio el usuario. Genera el consecutivo `SOL-{año}-{6 dígitos}`; el índice único sobre `numero` es la garantía ante concurrencia, por eso `SolicitudController::store` llama a `crearConReintento()`.
3. **Snapshot** — cada `solicitud_items` copia `codigo`, `nombre` y `foto` del repuesto. Corregir el catálogo después no altera el histórico de una solicitud.
4. **El inventario NO se descuenta al crear la solicitud**, solo en `SolicitudService::marcarListo()`, con un `UPDATE` condicionado (`where cantidad_disponible >= $cantidad`) que topa la cantidad si otro pedido consumió el stock entre tanto. Rechazar una solicitud que ya estaba en `listo` devuelve el inventario (`SolicitudAdminController::rechazar`).

Estados: `pendiente → en_proceso → listo → entregada`, o `rechazada`. Las constantes y sus etiquetas/colores/iconos de Bootstrap viven en `Solicitud::ESTADOS`; las vistas leen `estado_label` / `estado_color` / `estado_icono`.

### Correo

Un fallo de SMTP **nunca** debe tumbar un cambio de estado. `notificarPedidoListo()` captura la excepción, la registra en `Log` y la guarda en `solicitudes.error_notificacion`; el éxito marca `notificado_at`. La acción "Reenviar aviso" del panel existe para eso. El aviso al almacén de solicitudes nuevas es opcional (`ALMACEN_NOTIFICACION_EMAIL`, lista separada por comas).

`QUEUE_CONNECTION=sync` por defecto: el correo sale dentro de la misma petición.

### Roles y acceso

Dos roles: `admin` y `almacenista`. Ambos entran al panel y despachan solicitudes; **solo el admin gestiona el catálogo y los roles**, vía el alias de middleware `es.admin` (`EnsureEsAdmin` → `User::puedeGestionarCatalogo()`). El login incluye `activo => true` en las credenciales para que un usuario deshabilitado no entre aunque la contraseña sea correcta. Los invitados que caen en `/admin` se redirigen a `admin.login` (configurado en `bootstrap/app.php`).

**El rol vive en dos sitios a propósito** (paso 1 de un cambio compatible hacia atrás, todavía sin terminar):
- `users.rol` — la clave como texto. Es la que **autentica** (`User::esAdmin()`) y la que se sigue escribiendo.
- `users.rol_id` — FK a `tbl_rol`, rellenada por la migración cruzando `users.rol` con `tbl_rol.col_clave`.

`User::puedeGestionarCatalogo()` obedece a `rolAsignado->col_gestiona_catalogo` cuando el rol existe y está activo, y cae a `esAdmin()` si el usuario todavía no tiene `rol_id`. La relación se llama **`rolAsignado()`** y no `rol()` porque la columna homónima opacaría el nombre de la relación en Eloquent. Al crear o editar usuarios hay que escribir **las dos** columnas (ver `UsuarioSeeder`); mientras exista `users.rol`, no elimine ese paso.

### Roles (`tbl_rol`)

CRUD en `/admin/roles`, solo para el admin. Columnas con el prefijo de la convención: `col_clave` (única, minúsculas, espeja `users.rol`), `col_nombre`, `col_descripcion`, `col_gestiona_catalogo`, `col_sistema`, `col_activo`.

- **Anular ≠ borrar**: `destroy` pone `col_activo = false` porque los usuarios apuntan al registro por `rol_id`.
- `RolService::anular()` bloquea la anulación de un rol del sistema o con usuarios activos, releyendo con `lockForUpdate()` dentro de la transacción.
- Los roles `admin` y `almacenista` tienen `col_sistema = true`: se les puede cambiar nombre y descripción, **nunca** clave, permiso ni estado. Es lo que impide que un administrador se deje a sí mismo fuera del panel; `RolRequest::prepareForValidation()` reimpone esos tres valores.
- `col_sistema` nunca es `fillable`: no llega desde el formulario.

### Parámetros (`tbl_parametro`)

Configuración que el administrador cambia en caliente desde `/admin/parametros` (solo admin, `es.admin`). Es lo que configura la sincronización con el ERP.

- **`col_estado` es texto (`'activo'` / `'inactivo'`), no un booleano** — a diferencia del `col_activo` de `tbl_rol` y `tbl_categoria`. Fue un pedido explícito del usuario; las constantes viven en `Parametro::ESTADO_ACTIVO` / `ESTADO_INACTIVO` y la base lo acota con el CHECK `ck_tbl_parametro_estado`.
- **`Parametro::valor($nombre, $porDefecto)` es el único lector**: devuelve el valor solo si el parámetro está activo. No consulte la tabla desde ningún otro sitio.
- **El valor se guarda literal, sin `trim`**: un valor de los criterios de la API puede terminar en un espacio a propósito y ese espacio viaja a la API. Por eso `bootstrap/app.php` excluye `col_valor` del middleware `TrimStrings` — sin esa excepción, guardar el formulario borraba el espacio en silencio. **La misma regla vale al partir una lista** (ver abajo): a los elementos no se les hace `trim`.
- **Anular ≠ borrar**: `destroy` pone `col_estado = 'inactivo'`. `ParametroService::anular()` relee con `lockForUpdate()` dentro de la transacción y bloquea los parámetros del sistema.
- `col_sistema` nunca es `fillable`, y `ParametroRequest::prepareForValidation()` le reimpone nombre y estado a los del sistema (editar el estado en el formulario sería la puerta trasera para saltarse el bloqueo de `anular`).
- `Parametro::SENSIBLES` marca `codigo_api`: el listado imprime puntos, el formulario lo manda vacío (dejarlo vacío conserva el valor) y `scopeBuscar()` no busca dentro de `col_valor`. **Nunca escriba esa credencial ni el token en un log.**
- **Opciones cerradas**: `Parametro::OPCIONES` es un mapa `nombre => [valor => etiqueta]` y `Parametro::opcionesDe()` su único lector. Es un mecanismo genérico, no un caso especial escondido en la vista: el formulario pinta **botones de radio** para cualquier parámetro que aparezca en el mapa y el input de texto de siempre para el resto, `ParametroRequest` valida con `Rule::in(array_keys(...))` — un valor fuera de la lista se rechaza **en el servidor**, no solo en el HTML — y el listado imprime la etiqueta, no la clave (`valor_visible`). Hoy solo `inv.actualizar` lo usa.
- **Listas separadas por comas**: `Parametro::LISTAS` enumera los parámetros cuyo valor es una lista (`api.criterio` y `api.criterio_2`) y `Parametro::lista($nombre)` es su **único** lector — lee por `Parametro::valor()`, así que un parámetro inactivo devuelve `[]`. Es el mismo mecanismo genérico de `OPCIONES`: el formulario cambia la ayuda del campo cuando el accesor `es_lista` es cierto, en vez de esconder el caso en la vista. **`lista()` NO le hace `trim` a cada elemento** (un grupo que legítimamente lleve un espacio tiene que llegar con él a la API); solo descarta los elementos vacíos, para que `""` o `"A,"` no manden cadenas vacías dentro del arreglo JSON.
- `ParametroSeeder` hace `upsert` por `col_nombre` refrescando **solo** la descripción: el valor es del administrador. Siembra siete parámetros con `col_sistema = true`: `codigo_api`, `tiempo.actualizar`, `api.id_bod`, `api.id_cia`, `api.criterio`, `api.criterio_2` e `inv.actualizar`. **`codigo_api = 123` sigue siendo un marcador de posición** y hay que reemplazarlo antes de sincronizar; `api.id_bod = P2ALM` ya es la bodega real del almacén. Como el `upsert` no pisa el valor, un cambio del valor sembrado solo alcanza a las instalaciones nuevas: en una que ya existe se cambia desde `/admin/parametros`.
- **`inv.actualizar`** (`automatico` / `manual`, por defecto `automatico`) decide quién dispara la sincronización con el ERP. Las constantes son `Parametro::INV_ACTUALIZAR`, `INV_AUTOMATICO` e `INV_MANUAL`; no deje literales sueltos de esas cadenas. Ver la sección siguiente.

### Sincronización de stock con el ERP

`php artisan repuestos:sincronizar-stock [--simular]` trae la existencia de la API de inventario de Sidocsa (`https://user.appsidocsas.com:98/api/v1`) y la escribe en `repuestos.stock`.

- **Escribe ÚNICAMENTE `stock`, nunca `cantidad_disponible`.** `cantidad_disponible` es el saldo operativo que descuenta `SolicitudService::marcarListo()` y que topa el `Carrito`: si el ERP lo pisara, borraría lo ya despachado y el almacén entregaría contra un saldo fantasma. Lo sostienen los tests `test_la_sincronizacion_no_toca_cantidad_disponible` (comando) y `test_la_sincronizacion_manual_escribe_stock_y_no_cantidad_disponible` (botón).
- **Reparto en tres piezas**: `App\Services\InventarioApiSidocsa` trata con el endpoint (token, paginación, lectura de la respuesta); `App\Services\SincronizadorStockRepuestos` **es dueño de toda la orquestación** (recorre las páginas, arma el índice numérico del catálogo, empareja, escribe en lotes) y devuelve un `ResultadoSincronizacionStock` de solo lectura con los contadores; el comando es únicamente la cara en consola (tabla, avisos y `--simular`). **El botón "Actualizar" del panel llama al mismo servicio**, así que consola y panel no pueden divergir — no duplique la orquestación en ningún sitio.

- **El cuerpo de `POST /inventario/consultar` se arma en un solo sitio**, `InventarioApiSidocsa::pedirInventario()`, y es exactamente este:

  ```json
  {
    "cant": "500", "cant_page": "100", "tipo_inv": "INV1455",
    "id_bod": "P2ALM", "id_cia": "1", "page": "1", "existencias": 1,
    "criterio": [],
    "criterio_2": ["ELECTRICO", "MATERIAS PRIMAS"]
  }
  ```

  `criterio` (SUBGRUPO) y `criterio_2` (GRUPO) son **arreglos JSON y viajan siempre, aunque queden vacíos**: el endpoint espera la llave. Los configuran `api.criterio` y `api.criterio_2`, cuyo valor es una **lista separada por comas** que parte `Parametro::lista()` (ver la sección de parámetros: sin `trim` por elemento). `id_bod` e `id_cia` salen de sus parámetros; `cant`, `cant_page`, `page` y `existencias` los fija el servicio y **`tipo_inv` es la constante `'INV1455'`**, no un parámetro. Lo sostienen cuatro pruebas de `SincronizarStockTest` con `Http::fake()` + `Http::assertSent()`.

**Modo automático / manual** (`inv.actualizar`):

- **`automatico`**: la tarea programada dispara la sincronización cada `tiempo.actualizar` minutos, como siempre.
- **`manual`**: el scheduler **no dispara nunca** (`debeCorrer()` exige las dos condiciones: modo automático *y* que haya pasado el intervalo) y aparece en `/admin/parametros` un botón **Actualizar** que corre la sincronización a petición del administrador. Ruta `POST admin/parametros/sincronizar-stock`, dentro del grupo `es.admin`: el almacenista recibe 403.
- **El respaldo cuando el parámetro falta, está inactivo o trae un valor desconocido es `automatico`**, y es deliberado: es como se comportaba el sistema antes de que el parámetro existiera, y un stock envejeciendo no avisa. Con respaldo `manual`, anular el parámetro apagaría la integración en silencio.
- El botón se pinta solo en modo manual, pero **eso no es la barrera**: el controlador vuelve a comprobar el modo y responde 409 si está en automático.
- Como `QUEUE_CONNECTION=sync`, la sincronización corre **dentro de la petición**; por eso el controlador sube `set_time_limit` a 600s a propósito.
- **Candado contra corridas simultáneas**: el servicio toma `Cache::lock('repuestos.sincronizar_stock.candado', 900)` antes de trabajar y lanza `App\Exceptions\SincronizacionEnCursoException` si no lo consigue (409 en el panel, warning con exit 0 en consola: no es un fallo, otra corrida llegó antes). Aparte del candado escribe la marca `repuestos.sincronizar_stock.en_curso`, que es la que **lee la vista** para mostrar "Sincronización en curso..." — un lock no se puede consultar sin intentar adquirirlo. Las dos se sueltan en un `finally`, para que un fallo de la API no deje el panel pegado ni el botón inservible.
- **Aviso al usuario en los dos modos**: el botón va por `fetch` (`form[data-sincronizar-stock]` en `app.js`, mismo patrón que `data-agregar-carrito`) con spinner, botón deshabilitado y toast al empezar y al terminar con el resumen; el listado muestra siempre la última corrida, la próxima y si hay una en curso.
- **Seguridad**: ni `codigo_api` ni el token pasan por el resultado, la respuesta JSON, el HTML ni el log. Del `catch` general solo se repite el mensaje de las `RuntimeException` que construye `InventarioApiSidocsa` (dicen el código HTTP, nunca el cuerpo del endpoint de token); cualquier otra excepción sale genérica porque delataría rutas del servidor.
- El token **vence a los 5 minutos y se cachea 4**. Un 401 en una página bota el token cacheado y reintenta **una** vez con uno fresco. El `retry` del cliente usa `throw: false` a propósito: con el `throw` por defecto Laravel convertiría el 401 en excepción y ese manejo nunca correría.
- **El campo de cruce es `item`** (confirmado), y es lo único que lee `InventarioApiSidocsa::extraerCodigo()` — con `trim`, porque puede venir con espacios. Ningún otro campo cuenta como código.
- **El cruce es NUMÉRICO, y esa es la regla que hay que respetar.** `repuestos.codigo` guarda ceros a la izquierda (`0003729`) y el ERP manda el mismo ítem sin ellos (`3729`). El servicio trae los 591 códigos a memoria una sola vez y arma un índice `intval(codigo) => codigo`; empareja en PHP contra `intval($fila['item'])`. **La conversión sirve solo para emparejar: el `UPDATE` va siempre por `codigo` con su valor literal** — un `where cast(codigo as int) in (...)` anularía el índice único de la columna y reventaría con el primer código no numérico.
- **Colisiones**: si dos códigos del catálogo colapsan al mismo entero (`0003729` y `3729`), elegir uno sería escribirle el stock al repuesto equivocado, así que **quedan los dos fuera del cruce** con un `Log::warning` y un aviso en pantalla. Lo mismo con los códigos no numéricos, de cualquiera de los dos lados: se saltan y se cuentan aparte, nunca pasan por `intval` para terminar cruzando contra un 0. Hoy el catálogo no tiene ni colisiones ni códigos no numéricos: los 591 son de 7 dígitos.
- Actualiza agrupando por cantidad, en lotes de 120 códigos (límite de 2100 parámetros). **No crea repuestos**: los códigos sin correspondencia se cuentan y se registran con una muestra de 10 ejemplos.
- La tarea está en `routes/console.php` con `everyMinute()` + `withoutOverlapping()` y un filtro que resuelve `SincronizadorStockRepuestos::debeCorrer()`: solo la deja pasar en modo automático y cuando pasaron los minutos de `tiempo.actualizar`. La marca de la última corrida va en el **cache** (`repuestos.sincronizar_stock.ultima`, timestamp unix) y no en `tbl_parametro`, porque es estado operativo y no configuración; se pone **al inicio** de la corrida, para que un fallo también espere el intervalo completo. **Nada de esto se dispara solo**: hace falta `php artisan schedule:work` corriendo o una tarea programada de Windows que ejecute `php artisan schedule:run` cada minuto.
- La verificación del certificado TLS queda **activada**; el host real se probó y no da problema de certificado.

### Categorías (`tbl_categoria`)

**La categoría de un repuesto es el color del marco impreso en el borde de su foto.** Las imágenes de `public/img` traen ese marco y es el único dato de categoría que existe: los nombres no correlacionan (cada color mezcla filtros, válvulas, tornillos y rodamientos), así que **nunca intente inferir la categoría por el nombre**.

Once colores, sembrados por `CategoriaSeeder` y nombrados por su color a la espera de que el administrador les ponga el nombre real desde el panel. Los siete primeros salieron de la carga inicial de 489 fotos; rosa, gris, fucsia y amarillo aparecieron en la segunda carga de 102:

| Categoría | `col_slug` | `col_color_hex` | Repuestos |
|---|---|---|---|
| Rojo | `rojo` | `#D81818` | 153 |
| Celeste | `celeste` | `#00A8F0` | 103 |
| Morado | `morado` | `#603090` | 94 |
| Negro | `negro` | `#000000` | 56 |
| Azul | `azul` | `#000090` | 48 |
| Verde | `verde` | `#00A848` | 42 |
| Rosa | `rosa` | `#D890D8` | 33 |
| Durazno | `durazno` | `#F0C0A8` | 31 |
| Gris | `gris` | `#A8A8A8` | 13 |
| Fucsia | `fucsia` | `#A80078` | 8 |
| Amarillo | `amarillo` | `#F0F000` | 3 |

Quedan **7 repuestos sin `categoria_id`** sobre 591: cuatro PNG cuyo marco no se distingue del margen y tres fotos tomadas con celular que no tienen marco (ver más abajo).

- **Azul marino y celeste son categorías distintas y no se fusionan**: las separa el corte de matiz en 200 grados (celeste 193-198, azul 222-240).
- **Rosa y fucsia tampoco se fusionan**: comparten el tramo de matiz ≥ 290 y los separa la luminosidad (rosa `#D890D8` en 180, fucsia `#A80078` en 84), con el corte en 132. Se verificó en las fotos que son dos marcos distintos del almacén, no la misma tinta con más o menos carga.
- **No existe una categoría "blanco"**, y no debe crearse a partir de lo que reporte el detector: su único caso era una foto de celular sin marco donde el detector midió el piso con 9.3% de dominancia. Lo mismo pasó con otras dos fotos de celular (`0040470`, `0040471`), que quedaron con `categoria_id` nulo a mano. **Un umbral mínimo de dominancia no sirve para atajar esos falsos positivos**: la foto legítima `0031454.jpg` tiene 13.3%, por debajo de ellos, así que el umbral movería un repuesto real de categoría.
- `App\Services\DetectorColorMarco` muestrea la banda exterior (8% de cada lado), descarta el casi-blanco del margen (>235 en los tres canales), cuantiza a bloques de 24 y clasifica por saturación / matiz / luminosidad. **El durazno `#F0C0A8` cae en matiz 20, igual que un naranja fuerte; lo que los separa es la luminosidad** (≥180 es durazno). Los umbrales están calibrados contra las 489 fotos de la carga inicial y revalidados contra las 102 de la segunda: cualquier cambio hay que cotejarlo contra los conteos de la tabla de arriba corriendo `repuestos:clasificar --simular --forzar`, que reporta sin escribir.
- `php artisan repuestos:clasificar` escribe `repuestos.categoria_id`. Por defecto solo llena los nulos; `--forzar` reasigna todo y `--simular` reporta sin escribir. Actualiza en lotes de 120 ids por el límite de 2100 parámetros y falla con mensaje claro si falta la extensión GD.
- **`col_slug` es inmutable**: es la clave con la que el detector y el seeder reconocen el registro. El formulario no lo envía (no es `fillable`), lo deriva `CategoriaService::crear()` del nombre, y `update` no lo toca. Renombrar la categoría desde el panel es seguro; cambiarle el slug rompería la clasificación y haría que el seeder creara un duplicado.
- **Anular ≠ borrar**: `destroy` pone `col_activo = false`. `CategoriaService::anular()` releé con `lockForUpdate()` dentro de la transacción y bloquea la anulación si la categoría tiene repuestos **activos** asociados.
- `CategoriaSeeder` hace `upsert` por `col_slug` refrescando **solo** `col_color_hex`: el nombre y la descripción son del administrador y volver a correr el seeder no debe deshacer sus cambios.
- El chip de color es **la única excepción legítima a "no hex sueltos en las vistas"**: `col_color_hex` es contenido (el color real del marco), no una decisión de diseño, así que va en el atributo `style`. La forma vive en `.chip-color` / `.chip-color-sm` en `app.css`.

**Ojo con el nombre de la relación**: `repuestos` ya tenía una columna de texto `categoria` (la línea genérica que inventa `RepuestoSeeder`: Rodamientos, Neumática, Tornillería…), que es **otra cosa** y se deja como estaba. Por eso la relación se llama **`categoriaAsignada()`** y no `categoria()`, exactamente por el mismo motivo que `User::rolAsignado()`: la columna homónima opacaría la relación y `$repuesto->categoria` seguiría devolviendo el texto. En el catálogo público el filtro nuevo es **Categoría** (`categoria_id`) y el heredado quedó etiquetado **Tipo de repuesto** (`categoria`), para que dos filtros no se llamen igual.

Las dos vistas públicas de una solicitud están protegidas sin autenticación:
- `confirmacion/{numero}` exige que el número venga en el flash de sesión, si no redirige a consultar.
- `/consultar` exige **número + cédula**, para que nadie vea pedidos ajenos probando consecutivos.

### Imágenes

`repuestos.foto` guarda solo el **nombre de archivo** dentro de `public/img/`, normalmente el código del repuesto (`0003729.jpg`, `0015040_v2.png`). El accesor `foto_url` cae en `assets/img/sin-foto.svg` si el archivo no existe. `RepuestoSeeder` construye el catálogo recorriendo esa carpeta y `ImportarRepuestos --crear` asocia la foto sola si encuentra `{codigo}.{ext}` o `{codigo}_v2.{ext}`.

Los datos que no vienen de la imagen (nombre, línea, ubicación, stock) los produce `App\Services\GeneradorDatosRepuesto` a partir del `crc32` del código, de modo que el mismo código siempre da el mismo resultado y volver a sembrar no altera lo ya publicado. **`RepuestoSeeder` lo consume; cualquier alta masiva nueva debe usarlo también** en vez de repetir el criterio, o las filas dejarán de ser homogéneas.

`public/img` tiene hoy **758 archivos** para 591 repuestos: sobran fotos alternas, tres imágenes sin código derivable (`activo_fijo_*`) y varios códigos con dos extensiones. La carpeta **no es un espejo del catálogo** y no debe tratarse como tal.

## Frontend

**Bootstrap 5.3 + Bootstrap Icons servidos como archivos estáticos desde `public/assets/`, cargados con `asset()`.** El scaffolding de Vite/Tailwind viene del esqueleto de Laravel pero **ninguna vista usa `@vite`** — no hay build necesario para trabajar en la UI; edite `public/assets/css/app.css` y `public/assets/js/app.js` directamente.

`public/assets/js/app.js` es JS plano sin framework, todo por delegación de eventos y atributos `data-`:
- `form[data-agregar-carrito]` → envía por `fetch`, `CarritoController::store` responde JSON cuando `expectsJson()` y actualiza `[data-contador-carrito]` sin recargar.
- `form[data-sincronizar-stock]` → botón "Actualizar" de `/admin/parametros`: envía por `fetch`, deja el botón con spinner mientras corre y avisa con toasts (ver sincronización con el ERP).
- `form[data-confirmar="mensaje"]` → confirmación antes de acciones destructivas.
- `[data-autoenviar]` → autoenvía el formulario de filtros al cambiar.
- `[data-paso]` / `[data-objetivo]` → botones +/- de cantidad.
- `window.mostrarAviso(mensaje, tipo)` → toast.

Layouts: `layouts/app.blade.php` (público) y `layouts/admin.blade.php` (panel), ambos incluyen `partials/alertas`.

### Marca de agua

El logo de la empresa (`public/logo.png`) aparece de fondo en todas las páginas, vía `partials/marca-agua.blade.php`.

- **Hay tres documentos HTML en el proyecto, no dos**: los dos layouts y `admin/login.blade.php`, que no extiende ninguno. El partial se incluye en los tres; el resto de vistas extienden un layout y quedan cubiertas solas. Si algún día se agrega otro documento suelto, hay que incluirlo ahí también.
- **Va ENCIMA del contenido (`z-index: 990`), no detrás, y hay que dejarlo así.** Se intentó primero con `z-index: -1` y quedaba invisible: las superficies (`--ca-superficie`) son blancas y **opacas**, así que en el catálogo las tarjetas cubren casi toda la ventana y del logo solo asomaba un manchón en los huecos. **990** es el número: sobre todo el contenido normal pero por debajo de las capas de Bootstrap que sí deben taparla — dropdown 1000, navbar sticky 1020, offcanvas 1045, modal 1055 y `.zona-toast` 1090.
- **El `<img>` va como hijo directo de `<body>` a propósito**: dentro de un contenedor con `transform`, opacidad o filtro ese contenedor le crearía un contexto de apilamiento y la marca quedaría atrapada en él.
- La URL sale de `asset('logo.png')` en el Blade y no de una ruta relativa en el CSS, para que resuelva igual con `php artisan serve` y con Apache bajo subcarpeta.
- Los parámetros son tokens: `--ca-marca-agua-opacidad` (.10) y `--ca-marca-agua-ancho` (`min(70vw, 520px)`, topado además con `max-width: calc(100vw - 2rem)` para no sacar scroll horizontal en móvil). **Al ir al frente, la opacidad es el único freno**: por encima de .14 el rojo del logo compite con el texto que queda debajo.
- Está oculta en `@media print` y es decorativa (`alt=""` + `aria-hidden`).
- Las páginas de error 404/500 **no la llevan**: son las vistas por defecto del framework y ni siquiera cargan `app.css`.

### Paleta

Todo el color vive en los tokens `--ca-*` del `:root` de `app.css`, que además reasigna las variables `--bs-*` para que las utilidades de Bootstrap hablen esa paleta. **No agregue hex sueltos en las vistas** — use el token o las clases `.text-marca` / `.bg-marca` / `.btn-marca`. No edite `bootstrap.min.css`; sobrescriba desde `app.css`.

- **Marca `--ca-primario: #f20a0a`** — rojo de señalización industrial (extintor, parada de emergencia). Se usa en **relleno sólido** y en superficies pequeñas: acción principal, indicadores, y el *filete* de 3px (`--ca-filete`) que marca "estás aquí" en el sidebar, la pestaña activa y el borde de la tarjeta enfocada. Debe ocupar ~10% de la pantalla, no dominar.
- **Peligro `--ca-peligro: #8f1616`** — óxido, oscuro y desaturado. Nunca relleno sólido en reposo: contorno o fondo tenue, siempre con icono y texto.
- **La regla que separa los dos rojos es la FORMA, no el tono: lo lleno es la marca, lo contorneado es destructivo.** Por eso ningún estado de `Solicitud::ESTADOS` usa el rojo de marca (`rechazada` va en óxido, `entregada` en grafito) y el contador del carrito es un chip grafito, no `text-bg-danger`.
- **Contraste**: `#f20a0a` da 4.35:1 sobre blanco — alcanza para relleno, indicador y texto grande, **no para texto pequeño**. Cuando la marca va como texto o enlace sobre fondo claro se usa `--ca-primario-texto: #c40808` (6.20:1). Los neutros 500 y 600 están calculados para pasar 4.5:1; no los aclare.
- **Neutros**: una sola familia cálida (`--ca-neutro-50…900`) derivada de `#fafafa`. Entre superficies cambia solo la luminosidad, nunca el matiz. No reintroduzca grises azulados (slate) al lado del rojo.
- **Los correos llevan los colores en línea y a mano**, porque no cargan `app.css`. Si cambia la paleta, actualice también `emails/pedido-listo.blade.php` y `emails/nueva-solicitud.blade.php`.
- `welcome.blade.php` es la plantilla por defecto de Laravel y **ninguna ruta la usa**: está fuera de la paleta a propósito.

## Convenciones

- **Todo el código, comentarios, rutas, columnas y textos de UI están en español sin tildes** (`solicitante_cedula`, `cantidad_disponible`, "Consultar mi solicitud"). Mantenga ese estilo al agregar código; las tildes solo aparecen en este archivo.
- Las claves de sesión flash son **`exito`** y **`error`**, no `success` (ver `partials/alertas`).
- La lógica de negocio con transacciones vive en `app/Services`; los controladores solo validan, delegan y redirigen con `back()->with(...)`.
- Los repuestos no se borran: `destroy` solo pone `activo = false`, porque los items históricos apuntan al registro.
- **Tablas nuevas**: `tbl_<nombre>` con `id` bigint autoincremental y columnas con prefijo `col_` (ver `tbl_rol`, `tbl_categoria`, `tbl_parametro`). Las tablas anteriores a esta convención (`repuestos`, `solicitudes`, `solicitud_items`, `users`) se dejan como están; las FK que se les agregan sí van sin prefijo para no mezclar estilos dentro de la misma tabla (`repuestos.categoria_id`, `users.rol_id`).
- Cada cambio se registra en `changelog.txt` y el estado de la funcionalidad en `feature_list.json`. Versión actual: **V 1.3.5**.
- `phpunit.xml` apunta a la base real, así que las pruebas usan `DatabaseTransactions` y **no** `RefreshDatabase` (ver `tests/Feature/RolAdminTest`).
