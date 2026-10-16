<?php
namespace App\Services;

use App\Models\Auditoria;
use Illuminate\Http\Request;

class AuditoriaService
{
    public static function registrar(Request $request, string $accion, string $tabla, $registroId = null, $anterior = null, $nuevo = null, ?string $descripcion = null): void
    {
        try {
            Auditoria::create([
                'usuario_id' => optional($request->user())->usuario_id,
                'accion' => $accion,
                'tabla_afectada' => $tabla,
                'registro_id' => $registroId !== null ? (string)$registroId : null,
                'valor_anterior' => $anterior,
                'valor_nuevo' => $nuevo,
                'descripcion' => $descripcion,
                'direccion_ip' => $request->ip(),
                'user_agent' => substr((string)$request->userAgent(), 0, 500),
                'fecha_hora' => now(),
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
