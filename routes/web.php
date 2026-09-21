<?php

use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\CategoriaAdminController;
use App\Http\Controllers\Admin\ParametroAdminController;
use App\Http\Controllers\Admin\PermisoAdminController;
use App\Http\Controllers\Admin\RepuestoAdminController;
use App\Http\Controllers\Admin\RolAdminController;
use App\Http\Controllers\Admin\SolicitudAdminController;
use App\Http\Controllers\CarritoController;
use App\Http\Controllers\CatalogoController;
use App\Http\Controllers\SolicitudController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Catalogo publico
|--------------------------------------------------------------------------
| Sin registro de usuarios: cualquiera busca, selecciona y al final digita
| nombre, cedula y correo para generar la solicitud.
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

Route::get('/consultar', [SolicitudController::class, 'consultar'])->name('solicitudes.consultar');

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

    Route::middleware('auth')->group(function () {
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
                Route::get('/{parametro}/editar', 'edit')->name('edit')->middleware('permiso:parametros,editar');
                Route::put('/{parametro}', 'update')->name('update')->middleware('permiso:parametros,editar');
                Route::delete('/{parametro}', 'destroy')->name('destroy')->middleware('permiso:parametros,eliminar');
            });
    });
});
