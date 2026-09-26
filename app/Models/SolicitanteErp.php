<?php

namespace App\Models;

use Illuminate\Auth\Authenticatable as AutenticableTrait;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * Persona del ERP que puede pedir repuestos al almacen.
 *
 * Es la lista cerrada que alimenta el cuadro "Solicitante" del formulario
 * publico. Sus datos del ERP los escribe UNICAMENTE
 * App\Services\ImportadorSolicitantesErp (hoy desde un CSV, manana desde la API
 * del ERP); no hay CRUD en el panel.
 *
 * Tambien es el usuario del portal de aprobacion (guard `solicitante`, separado
 * del panel): usuario = col_correo, contrasena = col_password. Las columnas de
 * acceso las escriben solo AccesoSolicitanteService y
 * AsignacionContrasenaService, nunca el importador.
 *
 * La solicitud copia nombre, cedula, correo, area y telefono al crearse
 * (snapshot): corregir aqui a una persona no altera el historico.
 */
class SolicitanteErp extends Model implements Authenticatable
{
    use AutenticableTrait;

    protected $table = 'tbl_solicitante_erp';

    /**
     * El hash y el token no salen nunca serializados (toArray/toJson).
     *
     * @var list<string>
     */
    protected $hidden = ['col_password', 'col_remember_token'];

    /**
     * Minimo de caracteres para buscar y maximo de resultados que devuelve el
     * buscador publico.
     */
    public const MINIMO_BUSQUEDA = 2;

    public const MAXIMO_RESULTADOS = 20;

    /**
     * Palabras del termino que se tienen en cuenta: cada una agrega un LIKE,
     * asi que se topan para que un texto largo no arme una consulta enorme.
     */
    private const MAXIMO_PALABRAS = 5;

    protected $fillable = [
        'col_codigo_erp',
        'col_nombre',
        'col_cedula',
        'col_correo',
        'col_area',
        'col_telefono',
        'col_activo',
    ];

    /**
     * Las columnas de acceso (col_password, col_remember_token y sus fechas) NO
     * son fillable a proposito: ni el importador ni un formulario pueden
     * escribirlas por asignacion masiva.
     */
    protected function casts(): array
    {
        return [
            'col_activo' => 'boolean',
            'col_password_asignada_at' => 'datetime',
            'col_password_cambiada_at' => 'datetime',
            'col_ultimo_ingreso_at' => 'datetime',
        ];
    }

    public function solicitudes(): HasMany
    {
        return $this->hasMany(Solicitud::class, 'solicitante_erp_id');
    }

    /**
     * Columnas de credenciales para el trait de Laravel. Se sobrescriben los
     * metodos y no las propiedades del trait: PHP no deja redeclararlas con
     * otro valor.
     */
    public function getAuthPasswordName(): string
    {
        return 'col_password';
    }

    public function getRememberTokenName(): string
    {
        return 'col_remember_token';
    }

    /**
     * Correo como se compara en el ingreso: recortado y en minusculas. El
     * importador ya lo guarda asi; se repite por si la fila entro por otro lado.
     */
    public function correoNormalizado(): ?string
    {
        $correo = mb_strtolower(trim((string) $this->col_correo));

        return $correo === '' ? null : $correo;
    }

    /**
     * ¿Tiene contrasena asignada? Se lee de la fecha de asignacion y no del
     * hash: el CHECK ck_tbl_solicitante_erp_password_asignada garantiza que van
     * juntas, y asi el listado del panel nunca carga el hash.
     */
    public function tieneContrasena(): bool
    {
        return $this->col_password_asignada_at !== null;
    }

    /**
     * Motivo por el que NO se le puede dar acceso, o null si se puede.
     *
     * Reglas (las mismas para asignar la contrasena y para ingresar):
     *  - tiene que estar activo en el ERP;
     *  - tiene que tener correo, porque es su usuario y a donde llega la clave;
     *  - el correo no lo puede compartir con otro solicitante ACTIVO: el
     *    usuario tiene que identificar a una sola persona, o una podria
     *    aprobar las solicitudes de la otra.
     *
     * @param  bool|null  $correoCompartido  ya calculado por el listado (una
     *                                       consulta por pagina); null = consultarlo
     */
    public function motivoSinAcceso(?bool $correoCompartido = null): ?string
    {
        if (! $this->col_activo) {
            return 'El solicitante esta inactivo en el ERP.';
        }

        $correo = $this->correoNormalizado();

        if ($correo === null) {
            return 'El solicitante no tiene correo registrado en el ERP.';
        }

        $correoCompartido ??= in_array($correo, self::correosCompartidos([$correo]), true);

        if ($correoCompartido) {
            return 'El correo lo comparten varios solicitantes activos: corrijalo en el ERP y vuelva a importar.';
        }

        return null;
    }

    /**
     * De los correos dados, los que tienen MAS DE UN solicitante activo. Una
     * sola consulta para toda una pagina del listado.
     *
     * @param  list<string|null>  $correos
     * @return list<string> en minusculas
     */
    public static function correosCompartidos(array $correos): array
    {
        $correos = array_values(array_unique(array_filter(
            array_map(fn ($correo) => mb_strtolower(trim((string) $correo)), $correos)
        )));

        if ($correos === []) {
            return [];
        }

        // La collation de la columna es CI: el = ya no distingue mayusculas.
        $filas = DB::select(
            'select lower([col_correo]) as correo
               from [tbl_solicitante_erp]
              where [col_activo] = 1 and [col_correo] in ('.implode(', ', array_fill(0, count($correos), '?')).')
              group by lower([col_correo])
             having count(*) > 1',
            $correos
        );

        return array_map(fn ($fila) => (string) $fila->correo, $filas);
    }

    /**
     * El solicitante que corresponde a un correo en el ingreso, o null.
     *
     * Devuelve null (sin distinguir por que, para no permitir enumerar) si no
     * hay un activo con ese correo, si hay MAS DE UNO -el usuario no
     * identificaria a una sola persona- o si todavia no tiene contrasena.
     */
    public static function paraIngreso(string $correo): ?self
    {
        $correo = mb_strtolower(trim($correo));

        if ($correo === '') {
            return null;
        }

        // top (2): basta saber si hay uno o mas de uno.
        $filas = DB::select(
            'select top (2) * from [tbl_solicitante_erp] where [col_activo] = 1 and [col_correo] = ?',
            [$correo]
        );

        if (count($filas) !== 1) {
            return null;
        }

        $solicitante = self::hydrate($filas)->first();

        return $solicitante->col_password === null ? null : $solicitante;
    }

    /**
     * ¿Puede seguir usando el portal? Lo revisa el middleware en cada peticion:
     * si el ERP lo inactiva, o si se le quita la contrasena, la sesion abierta
     * se cierra.
     */
    public function puedeIngresar(): bool
    {
        return $this->col_activo && $this->col_password !== null;
    }

    /**
     * Busca solicitantes ACTIVOS por nombre para el cuadro combinado publico.
     *
     * Cada palabra del termino tiene que aparecer en el nombre (AND), asi
     * "perez juan" encuentra a "JUAN CARLOS PEREZ". Primero salen los que
     * empiezan por el termino, luego el resto en orden alfabetico.
     *
     * Devuelve SOLO id, nombre y area. El endpoint que lo usa no tiene
     * autenticacion: la cedula y el correo nunca salen de aqui. Tampoco se
     * busca por cedula, porque eso permitiria confirmar a quien pertenece un
     * numero de documento.
     *
     * @return list<array{id: int, nombre: string, area: ?string}>
     */
    public static function buscarActivos(string $termino): array
    {
        $palabras = array_slice(
            preg_split('/\s+/', trim($termino), -1, PREG_SPLIT_NO_EMPTY) ?: [],
            0,
            self::MAXIMO_PALABRAS
        );

        if (mb_strlen(implode(' ', $palabras)) < self::MINIMO_BUSQUEDA) {
            return [];
        }

        $condiciones = implode(' and ', array_fill(0, count($palabras), '[col_nombre] like ?'));
        $parametros = array_map(fn (string $palabra) => '%'.self::escaparLike($palabra).'%', $palabras);

        $filas = DB::select(
            'select top ('.self::MAXIMO_RESULTADOS.') [id], [col_nombre], [col_area]
               from [tbl_solicitante_erp]
              where [col_activo] = 1 and '.$condiciones.'
              order by case when [col_nombre] like ? then 0 else 1 end, [col_nombre], [id]',
            [...$parametros, self::escaparLike(implode(' ', $palabras)).'%']
        );

        return array_map(fn ($fila) => [
            'id' => (int) $fila->id,
            'nombre' => $fila->col_nombre,
            'area' => $fila->col_area,
        ], $filas);
    }

    /**
     * Escape de LIKE de SQL Server: % y _ literales van entre corchetes. El
     * corchete de apertura tambien, porque abriria una clase de caracteres.
     */
    public static function escaparLike(string $termino): string
    {
        return str_replace(['[', '%', '_'], ['[[]', '[%]', '[_]'], $termino);
    }
}
