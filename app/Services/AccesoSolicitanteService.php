<?php

namespace App\Services;

use App\Models\SolicitanteErp;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Credenciales del solicitante en su portal: verificar el ingreso y cambiar la
 * contrasena. Iniciar la sesion (guard, regenerar id) es cosa del controlador;
 * aqui solo se decide si las credenciales valen.
 *
 * Seguridad:
 *  - Un solo mensaje para todo fallo (correo inexistente, inactivo, sin
 *    contrasena, correo compartido o clave errada): no se puede enumerar.
 *  - Cuando no hay a quien comparar se calcula igual un hash, para que el
 *    tiempo de respuesta tampoco delate si el correo existe.
 *  - Limite de intentos por correo + IP (ademas del throttle por IP de la ruta).
 *  - Nada de contrasenas, hashes ni correos en el log: solo el id.
 */
class AccesoSolicitanteService
{
    public const MENSAJE_CREDENCIALES = 'El correo o la contrasena no son correctos.';

    /** Intentos fallidos permitidos por correo + IP antes de bloquear. */
    private const INTENTOS = 5;

    private const BLOQUEO_SEGUNDOS = 60;

    /**
     * @throws ValidationException con la llave `campo` si no valen o si se agotaron los intentos
     */
    public function verificarIngreso(string $correo, string $contrasena, string $ip, string $campo = 'correo'): SolicitanteErp
    {
        $llave = $this->llave('ingreso', $correo, $ip);
        $this->exigirIntentos($llave, $campo);

        $solicitante = SolicitanteErp::paraIngreso($correo);

        if (! $this->contrasenaValida($solicitante, $contrasena)) {
            RateLimiter::hit($llave, self::BLOQUEO_SEGUNDOS);

            Log::warning('Ingreso fallido al portal del solicitante.', [
                'solicitante_id' => $solicitante?->id,
                'ip' => $ip,
            ]);

            throw ValidationException::withMessages([$campo => self::MENSAJE_CREDENCIALES]);
        }

        RateLimiter::clear($llave);

        return $solicitante;
    }

    /**
     * Marca el ingreso. Sin tocar updated_at: esa fecha es la del ultimo cambio
     * de datos que trajo el ERP (la escribe el MERGE del importador).
     */
    public function registrarIngreso(SolicitanteErp $solicitante): void
    {
        SolicitanteErp::withoutTimestamps(
            fn () => $solicitante->forceFill(['col_ultimo_ingreso_at' => now()])->save()
        );

        Log::info('Ingreso al portal del solicitante.', ['solicitante_id' => $solicitante->id]);
    }

    /**
     * Cambio de contrasena desde la pagina de ingreso: correo + actual + nueva.
     * Anula el "recordarme" de todos los equipos; las sesiones abiertas caen
     * solas en la siguiente peticion (auth.session compara el hash).
     *
     * @throws ValidationException
     */
    public function cambiarContrasena(string $correo, string $actual, string $nueva, string $ip): SolicitanteErp
    {
        $llave = $this->llave('cambio', $correo, $ip);
        $this->exigirIntentos($llave, 'correo');

        $solicitante = SolicitanteErp::paraIngreso($correo);

        if (! $this->contrasenaValida($solicitante, $actual)) {
            RateLimiter::hit($llave, self::BLOQUEO_SEGUNDOS);

            Log::warning('Cambio de contrasena fallido en el portal del solicitante.', [
                'solicitante_id' => $solicitante?->id,
                'ip' => $ip,
            ]);

            throw ValidationException::withMessages([
                'correo' => 'El correo o la contrasena actual no son correctos.',
            ]);
        }

        RateLimiter::clear($llave);

        DB::transaction(function () use ($solicitante, $nueva) {
            // Se relee con bloqueo: si el administrador la restablecio entre
            // tanto, no se pisa su asignacion con un cambio sobre la clave vieja.
            $fila = SolicitanteErp::whereKey($solicitante->id)->lockForUpdate()->first();

            if ($fila === null || ! hash_equals((string) $fila->col_password, (string) $solicitante->col_password)) {
                throw ValidationException::withMessages([
                    'correo' => 'La contrasena cambio mientras tanto. Intente de nuevo.',
                ]);
            }

            SolicitanteErp::withoutTimestamps(fn () => $fila->forceFill([
                'col_password' => Hash::make($nueva),
                'col_password_cambiada_at' => now(),
                'col_remember_token' => null,
            ])->save());
        });

        Log::info('El solicitante cambio su contrasena.', ['solicitante_id' => $solicitante->id]);

        return $solicitante;
    }

    private function contrasenaValida(?SolicitanteErp $solicitante, string $contrasena): bool
    {
        if ($solicitante === null) {
            // Mismo costo que una verificacion real: el tiempo no delata nada.
            Hash::make($contrasena);

            return false;
        }

        return Hash::check($contrasena, (string) $solicitante->col_password);
    }

    private function exigirIntentos(string $llave, string $campo): void
    {
        if (! RateLimiter::tooManyAttempts($llave, self::INTENTOS)) {
            return;
        }

        $segundos = RateLimiter::availableIn($llave);

        throw ValidationException::withMessages([
            $campo => "Demasiados intentos. Espere {$segundos} segundos e intente de nuevo.",
        ]);
    }

    /**
     * El correo entra con hash: la llave del limitador vive en el cache y no
     * tiene por que guardar el correo en claro.
     */
    private function llave(string $accion, string $correo, string $ip): string
    {
        return "solicitante-{$accion}|".hash('sha256', mb_strtolower(trim($correo))).'|'.$ip;
    }
}
