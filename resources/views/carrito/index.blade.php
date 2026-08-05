@extends('layouts.app')

@section('titulo', 'Mi solicitud')

@section('contenido')

    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
        <div>
            <h1 class="h3 mb-1">Mi solicitud</h1>
            <p class="text-secondary mb-0">Revise los repuestos seleccionados antes de enviarlos al almacen.</p>
        </div>
        <a href="{{ route('catalogo.index') }}" class="btn btn-outline-marca">
            <i class="bi bi-arrow-left me-1"></i>Seguir buscando
        </a>
    </div>

    @if ($lineas->isEmpty())

        <div class="card border-0 shadow-sm">
            <div class="card-body text-center py-5">
                <i class="bi bi-cart-x fs-1 text-secondary opacity-50"></i>
                <h2 class="h5 mt-3">Todavia no ha seleccionado repuestos</h2>
                <p class="text-secondary mb-3">Busquelos en el catalogo y agreguelos a su solicitud.</p>
                <a href="{{ route('catalogo.index') }}" class="btn btn-marca">Ir al catalogo</a>
            </div>
        </div>

    @else

        <div class="row g-4">
            <div class="col-lg-8">
                <div class="card border-0 shadow-sm">
                    <div class="table-responsive">
                        <table class="table align-middle mb-0">
                            <thead class="table-light">
                            <tr>
                                <th scope="col" colspan="2" class="ps-3">Repuesto</th>
                                <th scope="col" class="text-center" style="width:11rem">Cantidad</th>
                                <th scope="col" class="text-center" style="width:5rem">Quitar</th>
                            </tr>
                            </thead>
                            <tbody>
                            @foreach ($lineas as $linea)
                                <tr>
                                    <td class="ps-3" style="width:5.5rem">
                                        <img src="{{ $linea->foto_url }}" alt="{{ $linea->nombre }}" class="miniatura">
                                    </td>

                                    <td>
                                        <a href="{{ route('catalogo.show', $linea) }}"
                                           class="fw-semibold text-decoration-none text-dark d-block">
                                            {{ $linea->nombre }}
                                        </a>
                                        <span class="repuesto-codigo">
                                            <i class="bi bi-upc me-1"></i>{{ $linea->codigo }}
                                            &middot; Id {{ $linea->id }}
                                        </span>
                                        <div class="small text-secondary mt-1">
                                            Disponibles: {{ $linea->cantidad_disponible }} {{ $linea->unidad_medida }}
                                        </div>
                                    </td>

                                    <td>
                                        <form method="POST" action="{{ route('carrito.update', $linea) }}"
                                              class="d-flex justify-content-center">
                                            @csrf
                                            @method('PATCH')
                                            <div class="input-group input-group-sm" style="width:8.5rem">
                                                <button class="btn btn-outline-secondary" type="button"
                                                        data-paso="-1" data-objetivo="cant-{{ $linea->id }}"
                                                        aria-label="Disminuir">
                                                    <i class="bi bi-dash"></i>
                                                </button>
                                                <input type="number" class="form-control text-center"
                                                       id="cant-{{ $linea->id }}" name="cantidad"
                                                       value="{{ $linea->cantidad_pedida }}"
                                                       min="1" max="{{ $linea->cantidad_disponible }}" required>
                                                <button class="btn btn-outline-secondary" type="button"
                                                        data-paso="1" data-objetivo="cant-{{ $linea->id }}"
                                                        aria-label="Aumentar">
                                                    <i class="bi bi-plus"></i>
                                                </button>
                                            </div>
                                            <button type="submit" class="btn btn-sm btn-outline-marca ms-1"
                                                    title="Actualizar cantidad">
                                                <i class="bi bi-arrow-clockwise"></i>
                                            </button>
                                        </form>
                                    </td>

                                    <td class="text-center">
                                        <form method="POST" action="{{ route('carrito.destroy', $linea) }}">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-sm btn-outline-danger"
                                                    title="Quitar de la solicitud">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>

                <form method="POST" action="{{ route('carrito.vaciar') }}" class="mt-3"
                      data-confirmar="Se quitaran todos los repuestos de su solicitud. Continuar?">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="btn btn-sm btn-link text-danger text-decoration-none px-0">
                        <i class="bi bi-x-circle me-1"></i>Vaciar la solicitud
                    </button>
                </form>
            </div>

            <div class="col-lg-4">
                <div class="card border-0 shadow-sm position-sticky" style="top:5.5rem">
                    <div class="card-body">
                        <h2 class="h6 text-uppercase text-secondary mb-3" style="letter-spacing:.05em">Resumen</h2>

                        <dl class="row mb-3">
                            <dt class="col-8 fw-normal text-secondary">Referencias distintas</dt>
                            <dd class="col-4 text-end fw-semibold mb-1">{{ $lineas->count() }}</dd>

                            <dt class="col-8 fw-normal text-secondary">Unidades totales</dt>
                            <dd class="col-4 text-end fw-semibold mb-0" data-unidades-carrito>{{ $unidades }}</dd>
                        </dl>

                        <a href="{{ route('solicitudes.create') }}" class="btn btn-marca btn-lg w-100">
                            Continuar
                            <i class="bi bi-arrow-right ms-1"></i>
                        </a>

                        <p class="small text-secondary mt-3 mb-0">
                            En el siguiente paso se le pediran su nombre, cedula y correo electronico
                            para poder avisarle cuando el pedido este listo.
                        </p>
                    </div>
                </div>
            </div>
        </div>

    @endif

@endsection
