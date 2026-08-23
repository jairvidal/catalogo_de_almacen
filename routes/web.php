<?php

use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\CategoriaAdminController;
use App\Http\Controllers\Admin\ParametroAdminController;
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

        Route::controller(SolicitudAdminController::class)->prefix('solicitudes')->name('solicitudes.')->group(function () {
            Route::get('/', 'index')->name('index');
            Route::get('/{solicitud}', 'show')->name('show');
            Route::post('/{solicitud}/tomar', 'tomar')->name('tomar');
            Route::post('/{solicitud}/listo', 'marcarListo')->name('listo');
            Route::post('/{solicitud}/entregar', 'entregar')->name('entregar');
            Route::post('/{solicitud}/rechazar', 'rechazar')->name('rechazar');
            Route::post('/{solicitud}/reenviar-aviso', 'reenviarAviso')->name('reenviar');
        });

        // La gestion del catalogo queda reservada al administrador.
        Route::middleware('es.admin')->prefix('repuestos')->name('repuestos.')
            ->controller(RepuestoAdminController::class)->group(function () {
                Route::get('/', 'index')->name('index');
                Route::get('/nuevo', 'create')->name('create');
                Route::post('/', 'store')->name('store');
                Route::get('/{repuesto}/editar', 'edit')->name('edit');
                Route::put('/{repuesto}', 'update')->name('update');
                Route::patch('/{repuesto}/stock', 'ajustarStock')->name('stock');
                Route::delete('/{repuesto}', 'destroy')->name('destroy');
            });

        // Las categorias agrupan el catalogo publico: solo el admin las toca.
        Route::middleware('es.admin')->prefix('categorias')->name('categorias.')
            ->controller(CategoriaAdminController::class)->group(function () {
                Route::get('/', 'index')->name('index');
                Route::get('/nueva', 'create')->name('create');
                Route::post('/', 'store')->name('store');
                Route::get('/{categoria}/editar', 'edit')->name('edit');
                Route::put('/{categoria}', 'update')->name('update');
                Route::delete('/{categoria}', 'destroy')->name('destroy');
            });

        // Los roles definen quien gestiona el catalogo: solo el admin los toca.
        Route::middleware('es.admin')->prefix('roles')->name('roles.')
            ->controller(RolAdminController::class)->group(function () {
                Route::get('/', 'index')->name('index');
                Route::get('/nuevo', 'create')->name('create');
                Route::post('/', 'store')->name('store');
                Route::get('/{rol}/editar', 'edit')->name('edit');
                Route::put('/{rol}', 'update')->name('update');
                Route::delete('/{rol}', 'destroy')->name('destroy');
            });

        // Los parametros configuran la integracion con el ERP y llevan una
        // credencial: solo el admin los ve.
        Route::middleware('es.admin')->prefix('parametros')->name('parametros.')
            ->controller(ParametroAdminController::class)->group(function () {
                Route::get('/', 'index')->name('index');
                Route::get('/nuevo', 'create')->name('create');
                Route::post('/', 'store')->name('store');
                // Boton "Actualizar" del modo manual de inv.actualizar. Va
                // antes del recurso por nombre para que no la capture
                // /{parametro}, y hereda el es.admin del grupo: el almacenista
                // no dispara la sincronizacion con el ERP.
                Route::post('/sincronizar-stock', 'sincronizarStock')->name('sincronizar');
                Route::get('/{parametro}/editar', 'edit')->name('edit');
                Route::put('/{parametro}', 'update')->name('update');
                Route::delete('/{parametro}', 'destroy')->name('destroy');
            });
    });
});
