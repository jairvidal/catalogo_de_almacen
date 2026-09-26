<?php

namespace App\Services;

use App\Exceptions\AsignacionContrasenaException;
use App\Mail\ContrasenaSolicitanteMail;
use App\Models\SolicitanteErp;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * El administrador asigna (o restablece) la contrasena de un solicitante del
 * ERP. La contrasena la genera el servidor y SOLO viaja en el correo al
 * solicitante: no se devuelve, no va a la pantalla, al flash ni al log, y en la
 * base queda solo su hash. El administrador nunca la ve.
 *
 * Si el correo no sale, la asignacion se REVIERTE y se informa el error: de lo
 * contrario quedaria activa una contrasena que nadie conoce y la anterior (si
 * la habia) dejaria de funcionar sin que el solicitante supiera por que. El
 * correo no se manda dentro de la transaccion (una llamada SMTP no puede
 * retener bloqueos en la base); por eso la reversion es un UPDATE
 * condicionado a que el hash siga siendo el que se acaba de escribir: si otro
 * administrador lo restablecio entre tanto, no se le pisa.
 */
class AsignacionContrasenaService
{
    /** 12 caracteres de un alfabeto de 55: unos 69 bits de entropia. */
    private const LONGITUD = 12;

    /** Sin caracteres que se confunden al leer el correo (0/O, 1/l/I). */
    private const MINUSCULAS = 'abcdefghjkmnpqrstuvwxyz';

    private const MAYUSCULAS = 'ABCDEFGHJKLMNPQRSTUVWXYZ';

    private const DIGITOS = '23456789';

    /**
     * @return string el correo al que se envio (para el aviso al administrador)
     *
     * @throws AsignacionContrasenaException
     */
    public function asignar(int $solicitanteId, User $administrador): string
    {
        $contrasena = $this->generar();
        $hash = Hash::make($contrasena);

        [$solicitante, $anterior] = DB::transaction(function () use ($solicitanteId, $hash) {
            $solicitante = SolicitanteErp::whereKey($solicitanteId)->lockForUpdate()->firstOrFail();

            $motivo = $solicitante->motivoSinAcceso();

            if ($motivo !== null) {
                throw new AsignacionContrasenaException($motivo);
            }

            $anterior = [
                'col_password' => $solicitante->col_password,
                'col_remember_token' => $solicitante->col_remember_token,
                'col_password_asignada_at' => $solicitante->getRawOriginal('col_password_asignada_at'),
            ];

            // Sin tocar updated_at: es la fecha del ultimo cambio de datos del ERP.
            SolicitanteErp::withoutTimestamps(fn () => $solicitante->forceFill([
                'col_password' => $hash,
                // Un "recordarme" emitido con la clave anterior deja de valer.
                'col_remember_token' => null,
                'col_password_asignada_at' => now(),
            ])->save());

            return [$solicitante, $anterior];
        });

        $correo = (string) $solicitante->correoNormalizado();

        try {
            Mail::to($correo)->send(new ContrasenaSolicitanteMail($solicitante, $contrasena));
        } catch (\Throwable $e) {
            $revertida = $this->revertir($solicitante->id, $hash, $anterior);

            // El mensaje del SMTP puede llevar el destinatario, nunca la clave.
            Log::error('No se pudo enviar la contrasena al solicitante; asignacion revertida.', [
                'solicitante_id' => $solicitante->id,
                'usuario_id' => $administrador->id,
                'revertida' => $revertida,
                'error' => $e->getMessage(),
            ]);

            throw new AsignacionContrasenaException(
                'No se pudo enviar el correo con la contrasena, asi que no se asigno: '
                .($anterior['col_password'] === null
                    ? 'el solicitante sigue sin acceso.'
                    : 'la contrasena anterior sigue vigente.')
                .' Revise la configuracion SMTP e intente de nuevo.'
            );
        } finally {
            unset($contrasena);
        }

        Log::info('Contrasena de solicitante asignada y enviada.', [
            'solicitante_id' => $solicitante->id,
            'usuario_id' => $administrador->id,
            'restablecida' => $anterior['col_password'] !== null,
        ]);

        return $correo;
    }

    /**
     * Devuelve las columnas a como estaban, solo si nadie cambio el hash
     * despues de escribirlo.
     *
     * @param  array{col_password: ?string, col_remember_token: ?string, col_password_asignada_at: mixed}  $anterior
     */
    private function revertir(int $solicitanteId, string $hash, array $anterior): bool
    {
        try {
            return DB::update(
                'update [tbl_solicitante_erp]
                    set [col_password] = ?, [col_remember_token] = ?, [col_password_asignada_at] = ?
                  where [id] = ? and [col_password] = ?',
                [
                    $anterior['col_password'],
                    $anterior['col_remember_token'],
                    $anterior['col_password_asignada_at'],
                    $solicitanteId,
                    $hash,
                ]
            ) === 1;
        } catch (\Throwable $e) {
            Log::critical('No se pudo revertir la contrasena de un solicitante tras fallar el correo.', [
                'solicitante_id' => $solicitanteId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Al menos una minuscula, una mayuscula y un digito, en posiciones al azar.
     * random_int es criptograficamente seguro.
     */
    private function generar(): string
    {
        $todos = self::MINUSCULAS.self::MAYUSCULAS.self::DIGITOS;

        $caracteres = [
            $this->alAzar(self::MINUSCULAS),
            $this->alAzar(self::MAYUSCULAS),
            $this->alAzar(self::DIGITOS),
        ];

        while (count($caracteres) < self::LONGITUD) {
            $caracteres[] = $this->alAzar($todos);
        }

        // Fisher-Yates con random_int: shuffle() no es seguro.
        for ($i = count($caracteres) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$caracteres[$i], $caracteres[$j]] = [$caracteres[$j], $caracteres[$i]];
        }

        return implode('', $caracteres);
    }

    private function alAzar(string $alfabeto): string
    {
        return $alfabeto[random_int(0, strlen($alfabeto) - 1)];
    }
}
