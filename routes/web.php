<?php

use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\CategoriaAdminController;
use App\Http\Controllers\Admin\ParametroAdminController;
use App\Http\Controllers\Admin\PermisoAdminController;
use App\Http\Controllers\Admin\RepuestoAdminController;
use App\Http\Controllers\Admin\RolAdminController;
use App\Http\Controllers\Admin\SolicitanteAdminController;
use App\Http\Controllers\Admin\SolicitudAdminController;
use App\Http\Controllers\CarritoController;
use App\Http\Controllers\CatalogoController;
use App\Http\Controllers\Solicitante\AccesoController;
use App\Http\Controllers\Solicitante\SolicitudPortalController;
use App\Http\Controllers\SolicitanteController;
use App\Http\Controllers\SolicitudController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Catalogo publico
|--------------------------------------------------------------------------
| Sin registro de usuarios: cualquiera busca, selecciona y al final elige
| su nombre en la lista de solicitantes del ERP para generar la solicitud.
*/

Route::get('/', [CatalogoController::class, 'index'])->name('catalogo.index');
Route::get('/repuestos/{repuesto}', [CatalogoController::class, 'show'])->name('catalogo.show');

Route::controller(CarritoController::class)->prefix('solicitud')->name('carrito.')->group(function () {
    Route::get('/', 'index')->name('index');
    Route::post('/agregar/{repuesto}', 'store')->name('store');
    Route::patch('/actualizar/{repuesto}', 'update')->name('update');
    Route::delete('/quitar/{repuesto}', 'destroy')->name('destroy');
    Route::delete('/vaciar', 'vaciar')->name('vaciar');
});

Route::controller(SolicitudController::class)->prefix('solicitud')->name('solicitudes.')->group(function () {
    Route::get('/datos', 'create')->name('create');
    Route::post('/enviar', 'store')->name('store');
    Route::get('/confirmacion/{numero}', 'confirmacion')->name('confirmacion');
});

// Consultar pide numero + solicitante. El solicitante sale de una lista
// publica, asi que el throttle es lo que frena probar consecutivos a mano.
Route::get('/consultar', [SolicitudController::class, 'consultar'])
    ->middleware('throttle:30,1')
    ->name('solicitudes.consultar');

// Cuadro combinado "Solicitante" (formulario y consulta). Publico: solo
// devuelve id, nombre y area.
Route::get('/solicitantes/buscar', [SolicitanteController::class, 'buscar'])
    ->middleware('throttle:60,1')
    ->name('solicitantes.buscar');

/*
|--------------------------------------------------------------------------
| Portal del solicitante del ERP
|--------------------------------------------------------------------------
| Guard `solicitante`, separado del panel: usuario = su correo del ERP. Aqui
| aprueba o deniega las solicitudes hechas a su nombre antes de que lleguen
| al almacen.
*/

Route::prefix('solicitante')->name('solicitante.')->group(function () {
    Route::controller(AccesoController::class)->group(function () {
        Route::get('/ingresar', 'showLogin')->name('login');
        // El throttle por IP se suma al limite por correo + IP del servicio.
        Route::post('/ingresar', 'login')->middleware('throttle:10,1')->name('login.attempt');
        Route::get('/cambiar-contrasena', 'editContrasena')->name('contrasena.edit');
        Route::post('/cambiar-contrasena', 'updateContrasena')->middleware('throttle:10,1')->name('contrasena.update');
    });

    // auth.session cierra la sesion si la contrasena cambio (restablecida por
    // el administrador o cambiada en otro equipo); solicitante.activo, si el
    // ERP lo inactivo.
    Route::middleware(['auth:solicitante', 'auth.session', 'solicitante.activo'])->group(function () {
        Route::post('/salir', [AccesoController::class, 'logout'])->name('logout');

        Route::controller(SolicitudPortalController::class)->prefix('solicitudes')->name('solicitudes.')->group(function () {
            Route::get('/', 'index')->name('index');
            Route::get('/{id}', 'show')->whereNumber('id')->name('show');
            Route::post('/{id}/aprobar', 'aprobar')->whereNumber('id')->middleware('throttle:30,1')->name('aprobar');
            Route::post('/{id}/denegar', 'denegar')->whereNumber('id')->middleware('throttle:30,1')->name('denegar');
        });
    });
});

/*
|--------------------------------------------------------------------------
| Panel del almacen
|--------------------------------------------------------------------------
| Aqui llegan todas las solicitudes. Requiere usuario y contrasena.
*/

Route::prefix('admin')->name('admin.')->group(function () {
    Route::get('login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('login', [AuthController::class, 'login'])
        ->middleware('throttle:10,1')
        ->name('login.attempt');

    // Guard web EXPLICITO: con `auth` a secas mandaria el guard por defecto de
    // la peticion, y un solicitante del portal (guard solicitante) no debe
    // poder llegar nunca a una ruta del panel.
    Route::middleware('auth:web')->group(function () {
        Route::post('logout', [AuthController::class, 'logout'])->name('logout');

        // Cada ruta del panel pide su permiso de la matriz Funciones por
        // perfil (middleware permiso:funcionalidad,accion -> User::puede()):
        // ver para listar y abrir, editar para crear y modificar, eliminar
        // para anular o rechazar. Reemplaza al es.admin de antes; un rol que
        // todavia no tiene su matriz guardada conserva lo que le daba
        // col_gestiona_catalogo, asi que el cambio no deja a nadie por fuera.

        Route::controller(SolicitudAdminController::class)->prefix('solicitudes')->name('solicitudes.')->group(function () {
            Route::get('/', 'index')->name('index')->middleware('permiso:solicitudes,ver');
            // Bandeja de "Listos para reclamar": el estado lo fija la ruta, no
            // la URL. Va ANTES de /{solicitud} para que el binding no la capture.
            Route::get('/listos', 'listos')->name('listos')->middleware('permiso:solicitudes,ver');
            Route::get('/{solicitud}', 'show')->name('show')->middleware('permiso:solicitudes,ver');
            Route::post('/{solicitud}/tomar', 'tomar')->name('tomar')->middleware('permiso:solicitudes,editar');
            Route::post('/{solicitud}/listo', 'marcarListo')->name('listo')->middleware('permiso:solicitudes,editar');
            Route::post('/{solicitud}/entregar', 'entregar')->name('entregar')->middleware('permiso:solicitudes,editar');
            // Rechazar cierra la solicitud y devuelve el inventario: es la
            // accion destructiva del modulo.
            Route::post('/{solicitud}/rechazar', 'rechazar')->name('rechazar')->middleware('permiso:solicitudes,eliminar');
            Route::post('/{solicitud}/reenviar-aviso', 'reenviarAviso')->name('reenviar')->middleware('permiso:solicitudes,editar');
        });

        // Solicitantes del ERP: listado de solo lectura y asignar/restablecer la
        // contrasena del portal (editar). La clave va al correo del solicitante.
        Route::prefix('solicitantes')->name('solicitantes.')
            ->controller(SolicitanteAdminController::class)->group(function () {
                Route::get('/', 'index')->name('index')->middleware('permiso:solicitantes,ver');
                Route::post('/{solicitante}/contrasena', 'asignarContrasena')->whereNumber('solicitante')
                    ->name('contrasena')->middleware(['permiso:solicitantes,editar', 'throttle:20,1']);
            });

        Route::prefix('repuestos')->name('repuestos.')
            ->controller(RepuestoAdminController::class)->group(function () {
                Route::get('/', 'index')->name('index')->middleware('permiso:repuestos,ver');
                Route::get('/nuevo', 'create')->name('create')->middleware('permiso:repuestos,editar');
                Route::post('/', 'store')->name('store')->middleware('permiso:repuestos,editar');
                Route::get('/{repuesto}/editar', 'edit')->name('edit')->middleware('permiso:repuestos,editar');
                Route::put('/{repuesto}', 'update')->name('update')->middleware('permiso:repuestos,editar');
                Route::patch('/{repuesto}/stock', 'ajustarStock')->name('stock')->middleware('permiso:repuestos,editar');
                Route::delete('/{repuesto}', 'destroy')->name('destroy')->middleware('permiso:repuestos,eliminar');
            });

        Route::prefix('categorias')->name('categorias.')
            ->controller(CategoriaAdminController::class)->group(function () {
                Route::get('/', 'index')->name('index')->middleware('permiso:categorias,ver');
                Route::get('/nueva', 'create')->name('create')->middleware('permiso:categorias,editar');
                Route::post('/', 'store')->name('store')->middleware('permiso:categorias,editar');
                Route::get('/{categoria}/editar', 'edit')->name('edit')->middleware('permiso:categorias,editar');
                Route::put('/{categoria}', 'update')->name('update')->middleware('permiso:categorias,editar');
                Route::delete('/{categoria}', 'destroy')->name('destroy')->middleware('permiso:categorias,eliminar');
            });

        Route::prefix('roles')->name('roles.')
            ->controller(RolAdminController::class)->group(function () {
                Route::get('/', 'index')->name('index')->middleware('permiso:roles,ver');
                Route::get('/nuevo', 'create')->name('create')->middleware('permiso:roles,editar');
                Route::post('/', 'store')->name('store')->middleware('permiso:roles,editar');
                Route::get('/{rol}/editar', 'edit')->name('edit')->middleware('permiso:roles,editar');
                Route::put('/{rol}', 'update')->name('update')->middleware('permiso:roles,editar');
                Route::delete('/{rol}', 'destroy')->name('destroy')->middleware('permiso:roles,eliminar');
            });

        // Funciones por perfil. Guardar exige EDITAR el modulo: ver la matriz
        // no alcanza para cambiarla.
        Route::prefix('permisos')->name('permisos.')
            ->controller(PermisoAdminController::class)->group(function () {
                Route::get('/', 'index')->name('index')->middleware('permiso:permisos,ver');
                Route::put('/{rol}', 'update')->name('update')->middleware('permiso:permisos,editar');
            });

        // Los parametros configuran la integracion con el ERP y llevan una
        // credencial.
        Route::prefix('parametros')->name('parametros.')
            ->controller(ParametroAdminController::class)->group(function () {
                Route::get('/', 'index')->name('index')->middleware('permiso:parametros,ver');
                Route::get('/nuevo', 'create')->name('create')->middleware('permiso:parametros,editar');
                Route::post('/', 'store')->name('store')->middleware('permiso:parametros,editar');
                // Boton "Actualizar" del modo manual de inv.actualizar. Va
                // antes del recurso por nombre para que no la capture
                // /{parametro}. Disparar la sincronizacion con el ERP es editar.
                Route::post('/sincronizar-stock', 'sincronizarStock')->name('sincronizar')->middleware('permiso:parametros,editar');
                // Suelta el candado que quedo colgado cuando una corrida murio
                // a medias. Tambien es editar: deja disparar otra corrida.
                Route::post('/liberar-sincronizacion', 'liberarSincronizacion')->name('liberar')->middleware('permiso:parametros,editar');
                Route::get('/{parametro}/editar', 'edit')->name('edit')->middleware('permiso:parametros,editar');
                Route::put('/{parametro}', 'update')->name('update')->middleware('permiso:parametros,editar');
                Route::delete('/{parametro}', 'destroy')->name('destroy')->middleware('permiso:parametros,eliminar');
            });
    });
});
