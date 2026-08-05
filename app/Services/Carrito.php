<?php

namespace App\Services;

use App\Models\Repuesto;
use Illuminate\Session\Store;
use Illuminate\Support\Collection;

/**
 * Carrito de repuestos guardado en la sesion del visitante.
 *
 * El catalogo es publico y no hay registro de usuarios, asi que la seleccion
 * vive en la sesion hasta que la persona confirma la solicitud.
 */
class Carrito
{
    private const CLAVE = 'carrito';

    public function __construct(private readonly Store $session) {}

    /**
     * Mapa repuesto_id => cantidad.
     *
     * @return array<int, int>
     */
    public function contenido(): array
    {
        return $this->session->get(self::CLAVE, []);
    }

    public function estaVacio(): bool
    {
        return $this->contenido() === [];
    }

    /**
     * Cantidad de referencias distintas (no de unidades).
     */
    public function cantidadReferencias(): int
    {
        return count($this->contenido());
    }

    public function cantidadUnidades(): int
    {
        return (int) array_sum($this->contenido());
    }

    public function cantidadDe(int $repuestoId): int
    {
        return (int) ($this->contenido()[$repuestoId] ?? 0);
    }

    /**
     * Agrega unidades respetando el stock disponible del repuesto.
     * Devuelve la cantidad que quedo finalmente en el carrito.
     */
    public function agregar(Repuesto $repuesto, int $cantidad = 1): int
    {
        $nueva = $this->cantidadDe($repuesto->id) + max(1, $cantidad);

        return $this->fijar($repuesto, $nueva);
    }

    /**
     * Fija la cantidad exacta de un repuesto, topada al stock disponible.
     */
    public function fijar(Repuesto $repuesto, int $cantidad): int
    {
        $carrito = $this->contenido();

        if ($cantidad <= 0) {
            unset($carrito[$repuesto->id]);
            $this->session->put(self::CLAVE, $carrito);

            return 0;
        }

        $cantidad = min($cantidad, max(0, $repuesto->cantidad_disponible));

        if ($cantidad <= 0) {
            unset($carrito[$repuesto->id]);
            $this->session->put(self::CLAVE, $carrito);

            return 0;
        }

        $carrito[$repuesto->id] = $cantidad;
        $this->session->put(self::CLAVE, $carrito);

        return $cantidad;
    }

    public function quitar(int $repuestoId): void
    {
        $carrito = $this->contenido();
        unset($carrito[$repuestoId]);
        $this->session->put(self::CLAVE, $carrito);
    }

    public function vaciar(): void
    {
        $this->session->forget(self::CLAVE);
    }

    /**
     * Repuestos del carrito con la cantidad pedida en el atributo
     * `cantidad_pedida`. Descarta de la sesion los que ya no existan o
     * hayan sido desactivados.
     *
     * @return Collection<int, Repuesto>
     */
    public function lineas(): Collection
    {
        $carrito = $this->contenido();

        if ($carrito === []) {
            return collect();
        }

        $repuestos = Repuesto::whereIn('id', array_keys($carrito))->activos()->get();

        // Si algo desaparecio del catalogo, se limpia para que el carrito no
        // arrastre referencias muertas.
        if ($repuestos->count() !== count($carrito)) {
            $vigentes = array_intersect_key($carrito, $repuestos->keyBy('id')->all());
            $this->session->put(self::CLAVE, $vigentes);
            $carrito = $vigentes;
        }

        return $repuestos->map(function (Repuesto $repuesto) use ($carrito) {
            $repuesto->cantidad_pedida = (int) $carrito[$repuesto->id];

            return $repuesto;
        })->sortBy('nombre')->values();
    }
}
