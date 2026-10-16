<?php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RoleMiddleware
{
    public function handle(Request $request, Closure $next, ...$roles): Response
    {
        $nombre = strtoupper((string) optional(optional($request->user())->rol)->nombre);
        $permitidos = array_map('strtoupper', $roles);
        if (!$nombre || !in_array($nombre, $permitidos, true)) {
            return response()->json(['message'=>'No tiene permisos para realizar esta acción'], 403);
        }
        return $next($request);
    }
}
