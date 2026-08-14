<?php

namespace App\Services;

/**
 * Genera los datos genericos de un repuesto a partir de su codigo.
 *
 * El almacen todavia no entrego los nombres reales del catalogo, asi que lo
 * unico que existe por item es la foto. Esta clase produce un nombre, una
 * linea, una ubicacion y un stock estables: se derivan del crc32 del codigo,
 * de modo que el mismo codigo siempre da el mismo resultado y volver a correr
 * el seeder o la importacion no cambia lo que ya se mostro en el catalogo.
 *
 * La usan RepuestoSeeder (carga inicial) e ImportadorImagenes (altas nuevas),
 * para que ambos caminos produzcan filas indistinguibles entre si.
 */
class GeneradorDatosRepuesto
{
    /**
     * Catalogo base de tipos de repuesto. Cada entrada es
     * [categoria, nombre generico, unidad de medida].
     *
     * @var list<array{0: string, 1: string, 2: string}>
     */
    private const TIPOS = [
        ['Rodamientos', 'Rodamiento rigido de bolas', 'UND'],
        ['Rodamientos', 'Rodamiento de rodillos conicos', 'UND'],
        ['Rodamientos', 'Chumacera de piso', 'UND'],
        ['Transmision', 'Correa trapezoidal', 'UND'],
        ['Transmision', 'Cadena de rodillos', 'MTR'],
        ['Transmision', 'Pinon de transmision', 'UND'],
        ['Transmision', 'Acople flexible', 'UND'],
        ['Transmision', 'Polea de aluminio', 'UND'],
        ['Sellos y empaques', 'Reten de aceite', 'UND'],
        ['Sellos y empaques', 'Empaque de teflon', 'UND'],
        ['Sellos y empaques', 'Oring de nitrilo', 'UND'],
        ['Neumatica', 'Cilindro neumatico doble efecto', 'UND'],
        ['Neumatica', 'Electrovalvula 5/2', 'UND'],
        ['Neumatica', 'Unidad de mantenimiento FRL', 'UND'],
        ['Neumatica', 'Racor recto para manguera', 'UND'],
        ['Hidraulica', 'Bomba hidraulica de engranajes', 'UND'],
        ['Hidraulica', 'Manguera hidraulica R2', 'MTR'],
        ['Hidraulica', 'Filtro hidraulico de retorno', 'UND'],
        ['Electrico', 'Contactor tripolar', 'UND'],
        ['Electrico', 'Rele termico regulable', 'UND'],
        ['Electrico', 'Breaker riel DIN', 'UND'],
        ['Electrico', 'Fuente de poder 24VDC', 'UND'],
        ['Electrico', 'Variador de velocidad', 'UND'],
        ['Electrico', 'Cable encauchetado', 'MTR'],
        ['Instrumentacion', 'Sensor inductivo M12', 'UND'],
        ['Instrumentacion', 'Sensor fotoelectrico', 'UND'],
        ['Instrumentacion', 'Transmisor de presion', 'UND'],
        ['Instrumentacion', 'Termocupla tipo J', 'UND'],
        ['Motores', 'Motor electrico trifasico', 'UND'],
        ['Motores', 'Motorreductor de eje hueco', 'UND'],
        ['Motores', 'Ventilador de motor', 'UND'],
        ['Tornilleria', 'Tornillo hexagonal inoxidable', 'UND'],
        ['Tornilleria', 'Tuerca de seguridad', 'UND'],
        ['Tornilleria', 'Arandela plana galvanizada', 'UND'],
        ['Herramientas', 'Juego de llaves mixtas', 'UND'],
        ['Herramientas', 'Disco de corte para metal', 'UND'],
        ['Herramientas', 'Broca para acero rapido', 'UND'],
        ['Lubricantes', 'Grasa de litio multiproposito', 'KG'],
        ['Lubricantes', 'Aceite para reductor ISO 220', 'LTR'],
        ['Filtros', 'Filtro de aire industrial', 'UND'],
        ['Filtros', 'Filtro de combustible', 'UND'],
        ['Valvulas', 'Valvula de bola de acero inoxidable', 'UND'],
        ['Valvulas', 'Valvula check vertical', 'UND'],
        ['Tuberia', 'Codo roscado de 90 grados', 'UND'],
        ['Tuberia', 'Union universal galvanizada', 'UND'],
        ['Bandas', 'Banda transportadora modular', 'MTR'],
        ['Bandas', 'Raspador de banda', 'UND'],
        ['Seguridad', 'Guarda de proteccion metalica', 'UND'],
    ];

    /**
     * Medidas que se anexan al nombre para que dos items del mismo tipo se
     * distingan y la busqueda por texto tenga sentido.
     *
     * @var list<string>
     */
    private const MEDIDAS = [
        '1/4"', '3/8"', '1/2"', '3/4"', '1"', '1 1/2"', '2"',
        '6 mm', '8 mm', '10 mm', '12 mm', '16 mm', '20 mm', '25 mm', '32 mm',
        'ref. 6203', 'ref. 6205', 'ref. 6305', 'ref. 30206', 'ref. A-42',
        'serie B-56', 'serie C-72', '110V', '220V', '440V',
        '1 HP', '2 HP', '5 HP', '7.5 HP', '15 HP',
    ];

    /**
     * @var list<string>
     */
    private const UBICACIONES = [
        'Estante A-01', 'Estante A-02', 'Estante B-01', 'Estante B-02',
        'Estante C-01', 'Estante C-02', 'Estante D-01', 'Gaveta 1',
        'Gaveta 2', 'Gaveta 3', 'Zona pesada', 'Bodega externa',
    ];

    /**
     * Fila lista para insertar, sin las marcas de tiempo ni categoria_id.
     *
     * @return array{codigo: string, nombre: string, descripcion: null, categoria: string, ubicacion: string, unidad_medida: string, foto: string|null, cantidad_disponible: int, stock_minimo: int, activo: bool}
     */
    public function generar(string $codigo, ?string $nombreArchivo = null): array
    {
        // El hash del codigo hace que el tipo y la medida sean estables entre
        // ejecuciones, no aleatorios en cada corrida.
        $semilla = crc32($codigo);
        [$categoria, $tipo, $unidad] = self::TIPOS[$semilla % count(self::TIPOS)];
        $medida = self::MEDIDAS[intdiv($semilla, 7) % count(self::MEDIDAS)];
        $ubicacion = self::UBICACIONES[intdiv($semilla, 13) % count(self::UBICACIONES)];

        return [
            'codigo' => $codigo,
            'nombre' => "{$tipo} {$medida}",
            // Se deja vacia a proposito: inventar una descripcion solo
            // genera ruido que contradice el nombre real del item.
            'descripcion' => null,
            'categoria' => $categoria,
            'ubicacion' => $ubicacion,
            'unidad_medida' => $unidad,
            'foto' => $nombreArchivo,
            'cantidad_disponible' => $this->cantidad($semilla),
            'stock_minimo' => $semilla % 6 + 2,
            'activo' => true,
        ];
    }

    /**
     * Distribucion de stock: la mayoria con existencias, unos pocos agotados
     * para poder probar el comportamiento del catalogo sin disponibilidad.
     */
    private function cantidad(int $semilla): int
    {
        $resto = $semilla % 100;

        return match (true) {
            $resto < 8 => 0,
            $resto < 20 => $semilla % 4 + 1,
            $resto < 70 => $semilla % 40 + 5,
            default => $semilla % 250 + 40,
        };
    }
}
