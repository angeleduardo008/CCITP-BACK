<?php
namespace App\Http\Middleware;

use App\Models\Usuario;
use Closure;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class JwtAuthMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $header = $request->header('Authorization', '');
        if (!preg_match('/Bearer\s+(\S+)/', $header, $m)) {
            return response()->json(['message'=>'Token no proporcionado'], 401);
        }
        try {
            $secret = (string) config('jwt.secret');
            if (!$secret) return response()->json(['message'=>'JWT_SECRET no configurado'], 500);
            $payload = JWT::decode($m[1], new Key($secret, 'HS256'));
            $usuario = Usuario::with(['rol','personal'])->where('usuario_id', $payload->sub)->where('estado',1)->first();
            if (!$usuario) return response()->json(['message'=>'Usuario no autorizado'], 401);
            $request->setUserResolver(fn() => $usuario);
        } catch (\Throwable $e) {
            return response()->json(['message'=>'Token inválido o vencido'], 401);
        }
        return $next($request);
    }
}
