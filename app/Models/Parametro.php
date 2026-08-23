<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Parametro de configuracion del sistema, editable desde /admin/parametros.
 *
 * Es el unico sitio por donde se leen los valores que no van en el .env porque
 * el administrador los cambia en caliente (la credencial y los filtros de la
 * API de inventario, cada cuanto se sincroniza el stock).
 *
 * El estado es texto ('activo' / 'inactivo') y no un booleano, a diferencia del
 * col_activo de tbl_rol y tbl_categoria: fue un pedido explicito del usuario y
 * la base lo acota con un CHECK.
 */
class Parametro extends Model
{
    protected $table = 'tbl_parametro';

    public const ESTADO_ACTIVO = 'activo';

    public const ESTADO_INACTIVO = 'inactivo';

    /** @var array<string, string> */
    public const ESTADOS = [
        self::ESTADO_ACTIVO => 'Activo',
        self::ESTADO_INACTIVO => 'Inactivo',
    ];

    /** Modo con el que se actualiza el inventario desde el ERP. */
    public const INV_ACTUALIZAR = 'inv.actualizar';

    /** Filtro por SUBGRUPO que viaja a la API de inventario como `criterio`. */
    public const API_CRITERIO = 'api.criterio';

    /** Filtro por GRUPO que viaja a la API de inventario como `criterio_2`. */
    public const API_CRITERIO_2 = 'api.criterio_2';

    /** La tarea programada dispara la sincronizacion sola. */
    public const INV_AUTOMATICO = 'automatico';

    /** La sincronizacion solo sale del boton "Actualizar" del panel. */
    public const INV_MANUAL = 'manual';

    /**
     * Marca durable de que repuestos.existencia ya recibio su carga inicial
     * desde el stock del ERP (ver App\Services\InicializadorExistenciaRepuestos).
     *
     * Vive en tbl_parametro y no en el cache A PROPOSITO: la marca de la ultima
     * corrida puede perderse sin dano (a lo sumo se sincroniza antes de tiempo),
     * pero perder esta significaria volver a escribir el saldo operativo del
     * almacen y borrar todo lo ya despachado. Un `cache:clear` no puede tener
     * esa consecuencia, y una fila de la base sobrevive a reinicios y limpiezas.
     */
    public const INV_EXISTENCIA_INICIALIZADA = 'inv.existencia_inicializada';

    /** Todavia no se hizo la carga inicial de repuestos.existencia. */
    public const INV_NO = '0';

    /** Ya se hizo: repuestos.existencia es el saldo operativo y no se vuelve a escribir en bloque. */
    public const INV_SI = '1';

    /**
     * Parametros cuyo valor es una lista cerrada.
     *
     * No es un caso especial escondido en la vista: el formulario pinta botones
     * de radio para cualquier parametro que aparezca aqui y ParametroRequest lo
     * valida con Rule::in, de modo que un valor fuera de la lista se rechaza en
     * el servidor y no solo en el HTML.
     *
     * @var array<string, array<string, string>> nombre => [valor => etiqueta]
     */
    public const OPCIONES = [
        self::INV_ACTUALIZAR => [
            self::INV_AUTOMATICO => 'Automatico',
            self::INV_MANUAL => 'Manual',
        ],
        self::INV_EXISTENCIA_INICIALIZADA => [
            self::INV_NO => 'No',
            self::INV_SI => 'Si',
        ],
    ];

    /**
     * Parametros cuyo valor es una LISTA separada por comas.
     *
     * La API de inventario espera `criterio` y `criterio_2` como arreglos JSON
     * (varios grupos o subgrupos a la vez), y en tbl_parametro un valor es una
     * sola cadena: la coma es el separador acordado. El formulario lo dice en la
     * ayuda del campo y Parametro::lista() es quien lo parte.
     *
     * @var list<string>
     */
    public const LISTAS = [
        self::API_CRITERIO,
        self::API_CRITERIO_2,
    ];

    /**
     * Parametros cuyo valor es una credencial: no se imprime en el listado ni
     * viaja al navegador en el formulario, y jamas se escribe en el log.
     *
     * @var list<string>
     */
    public const SENSIBLES = [
        'codigo_api',
    ];

    /**
     * col_sistema queda fuera a proposito: no llega desde el formulario, igual
     * que en tbl_rol.
     */
    protected $fillable = [
        'col_nombre',
        'col_valor',
        'col_estado',
        'col_descripcion',
    ];

    protected function casts(): array
    {
        return [
            'col_sistema' => 'boolean',
        ];
    }

    /**
     * Lector centralizado. Es el UNICO punto por donde se leen parametros:
     * devuelve el valor solo si el parametro esta activo, y si no, el respaldo.
     *
     * NO se le hace trim al valor: hay parametros cuyo espacio final es
     * significativo para la API de inventario (ver api.criterio_2 en
     * ParametroSeeder), y recortarlo cambiaria la consulta en silencio.
     */
    public static function valor(string $nombre, ?string $porDefecto = null): ?string
    {
        $parametro = static::query()
            ->activos()
            ->where('col_nombre', $nombre)
            ->first();

        return $parametro?->col_valor ?? $porDefecto;
    }

    /**
     * Valor de un parametro de lista, ya partido en sus elementos.
     *
     * Lee por Parametro::valor(), o sea que respeta el estado: un parametro
     * inactivo devuelve la lista vacia, igual que si no existiera.
     *
     * NO SE LE HACE trim A CADA ELEMENTO, y es a proposito: el valor de estos
     * parametros se guarda literal (ver la nota de valor() y la excepcion de
     * col_valor en el middleware TrimStrings), asi que un elemento que
     * legitimamente termine en espacio tiene que llegar con ese espacio a la
     * API. Lo unico que se descarta son los elementos vacios, para que ni ''
     * ni 'A,' terminen mandando cadenas vacias dentro del arreglo JSON.
     *
     * @return list<string>
     */
    public static function lista(string $nombre): array
    {
        $valor = (string) static::valor($nombre, '');

        if ($valor === '') {
            return [];
        }

        return array_values(array_filter(
            explode(',', $valor),
            fn (string $elemento): bool => $elemento !== ''
        ));
    }

    /**
     * Opciones cerradas de un parametro, o un arreglo vacio si admite texto
     * libre. Es el unico sitio que consulta el mapa OPCIONES.
     *
     * @return array<string, string> valor => etiqueta
     */
    public static function opcionesDe(?string $nombre): array
    {
        return self::OPCIONES[(string) $nombre] ?? [];
    }

    /**
     * Busca por nombre o descripcion. El valor queda fuera de la busqueda a
     * proposito: no se puede permitir descubrir una credencial tanteando.
     */
    public function scopeBuscar(Builder $query, ?string $termino): Builder
    {
        $termino = trim((string) $termino);

        if ($termino === '') {
            return $query;
        }

        return $query->where(function (Builder $q) use ($termino) {
            $like = '%'.str_replace(['%', '_'], ['[%]', '[_]'], $termino).'%';

            $q->where('col_nombre', 'like', $like)
                ->orWhere('col_descripcion', 'like', $like);
        });
    }

    public function scopeActivos(Builder $query): Builder
    {
        return $query->where('col_estado', self::ESTADO_ACTIVO);
    }

    public function getEsActivoAttribute(): bool
    {
        return $this->col_estado === self::ESTADO_ACTIVO;
    }

    /**
     * Los parametros del sistema sostienen la sincronizacion con el ERP: no se
     * renombran ni se anulan, para que nadie deje la integracion sin configurar.
     */
    public function getEsDelSistemaAttribute(): bool
    {
        return (bool) $this->col_sistema;
    }

    public function getEsSensibleAttribute(): bool
    {
        return in_array($this->col_nombre, self::SENSIBLES, true);
    }

    /**
     * Si el valor de este parametro es una lista separada por comas. Lo usa el
     * formulario para decirle al administrador como escribirlo.
     */
    public function getEsListaAttribute(): bool
    {
        return in_array($this->col_nombre, self::LISTAS, true);
    }

    /**
     * Opciones cerradas de este parametro; vacio si admite texto libre.
     *
     * @return array<string, string> valor => etiqueta
     */
    public function getOpcionesAttribute(): array
    {
        return self::opcionesDe($this->col_nombre);
    }

    public function getTieneOpcionesAttribute(): bool
    {
        return $this->opciones !== [];
    }

    /**
     * Lo que se puede mostrar en pantalla. De una credencial solo se dice si
     * esta configurada o no; el valor no sale del servidor.
     */
    public function getValorVisibleAttribute(): string
    {
        if ($this->es_sensible) {
            return $this->col_valor === '' ? 'Sin configurar' : str_repeat('•', 8);
        }

        // De un parametro de lista cerrada se muestra la etiqueta, que es lo
        // que el administrador eligio en el formulario.
        return $this->opciones[$this->col_valor] ?? (string) $this->col_valor;
    }
}
