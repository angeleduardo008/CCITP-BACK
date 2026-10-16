<?php
namespace App\Http\Controllers;

use App\Models\Usuario;
use App\Services\AuditoriaService;
use App\Support\InstitutionContext;
use Firebase\JWT\JWT;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $data = $request->validate([
            'login'=>'required|string|max:150',
            'password'=>'required|string|max:255',
            'institucion'=>['nullable','string',Rule::in(InstitutionContext::slugs())],
        ]);

        $usuario = Usuario::with(['rol','personal.institucion'])
            ->where(function($q) use ($data){
                $q->where('nombre_usuario',$data['login'])
                  ->orWhere('correo',$data['login']);
            })
            ->first();

        $requestedSlug = InstitutionContext::normalizeSlug($data['institucion'] ?? null);

        if (
            !$usuario ||
            !$usuario->estado ||
            ($usuario->bloqueado_hasta && $usuario->bloqueado_hasta->isFuture()) ||
            !Hash::check($data['password'], $usuario->password_hash)
        ) {
            if ($usuario) {
                $usuario->increment('intentos_fallidos');
                if ($usuario->intentos_fallidos >= 5) {
                    $usuario->update(['bloqueado_hasta'=>now()->addMinutes(15)]);
                }
            }
            throw ValidationException::withMessages([
                'login'=>['Credenciales inválidas o cuenta temporalmente bloqueada.']
            ]);
        }

        $usuario->update([
            'intentos_fallidos'=>0,
            'bloqueado_hasta'=>null,
            'ultimo_acceso'=>now()
        ]);

        // La institución ya no se elige en el login.
        // Para usuarios normales se deriva de su ficha de personal.
        // El administrador entra al panel general multiinstitucional.
        $isAdmin = $usuario->rol?->nombre === 'ADMINISTRADOR';
        $slug = null;

        if (!$isAdmin) {
            $institutionName = $usuario->personal?->institucion?->nombre;
            $slug = InstitutionContext::slugForName($institutionName);

            if (!$slug) {
                throw ValidationException::withMessages([
                    'login'=>['El usuario no tiene una institución válida asignada. Contacte al administrador.']
                ]);
            }

            // Compatibilidad con clientes anteriores que todavía envían institución.
            if ($requestedSlug && $requestedSlug !== $slug) {
                throw ValidationException::withMessages([
                    'login'=>['El usuario pertenece a otra institución.']
                ]);
            }
        }

        $now = now()->timestamp;
        $token = JWT::encode([
            'iss'=>config('app.url'),
            'sub'=>$usuario->usuario_id,
            'rol'=>$usuario->rol?->nombre,
            'institucion'=>$slug,
            'iat'=>$now,
            'exp'=>now()->addMinutes(config('jwt.ttl_minutes',480))->timestamp,
        ], config('jwt.secret'), 'HS256');

        $request->setUserResolver(fn()=> $usuario);
        AuditoriaService::registrar(
            $request,
            'LOGIN',
            'usuarios',
            $usuario->usuario_id,
            null,
            null,
            'Inicio de sesión'.($slug ? ' · institución: '.$slug : '')
        );

        return response()->json([
            'token'=>$token,
            'user'=>$this->userPayload($usuario),
            'institucion'=>$slug,
        ]);
    }

    public function me(Request $request)
    {
        return response()->json([
            'user'=>$this->userPayload(
                $request->user()->loadMissing(['rol','personal.institucion'])
            )
        ]);
    }

    public function logout(Request $request)
    {
        AuditoriaService::registrar(
            $request,'LOGOUT','usuarios',$request->user()->usuario_id,null,null,'Cierre de sesión'
        );
        return response()->json([
            'message'=>'Sesión cerrada. El cliente debe descartar el token.'
        ]);
    }

    private function userPayload(Usuario $u): array
    {
        return [
            'usuario_id'=>$u->usuario_id,
            'nombre_usuario'=>$u->nombre_usuario,
            'correo'=>$u->correo,
            'rol'=>$u->rol?->nombre,
            'personal'=>$u->personal,
            'institucion_slug'=>InstitutionContext::slugForName($u->personal?->institucion?->nombre),
        ];
    }
}
