<?php
namespace App\Http\Controllers;

use App\Models\Personal;
use App\Models\Usuario;
use App\Services\AuditoriaService;
use App\Support\InstitutionContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class UsuarioController extends Controller
{
    public function index(Request $request)
    {
        $q=Usuario::with(['rol','personal.institucion']);

        $slug=InstitutionContext::fromRequest($request);
        if($slug){
            $q->where(function($userQuery) use($slug){
                $userQuery->whereNull('personal_id')
                    ->orWhere(function($linkedQuery) use($slug){
                        InstitutionContext::applyRelation(
                            $linkedQuery,
                            'personal.institucion',
                            $slug
                        );
                    });
            });
        }

        if($request->filled('estado')){
            $q->where('estado',(int)$request->estado);
        }

        if($request->filled('q')){
            $term='%'.$request->q.'%';
            $q->where(fn($x)=>
                $x->where('nombre_usuario','like',$term)
                  ->orWhere('correo','like',$term)
            );
        }

        return $q->orderBy('nombre_usuario')
            ->paginate((int)$request->get('per_page',25));
    }

    public function store(Request $r)
    {
        $d=$r->validate($this->rules());
        $this->assertPersonalContext($r,$d['personal_id']??null);

        $row=Usuario::create([
            'personal_id'=>$d['personal_id']??null,
            'rol_id'=>$d['rol_id'],
            'nombre_usuario'=>$d['nombre_usuario'],
            'correo'=>$d['correo'],
            'password_hash'=>Hash::make($d['password']),
            'estado'=>$d['estado']??1
        ]);

        AuditoriaService::registrar(
            $r,'INSERT','usuarios',$row->usuario_id,null,
            $row->only([
                'usuario_id','nombre_usuario','correo','rol_id','estado'
            ])
        );

        return response()->json(
            $row->load(['rol','personal.institucion']),
            201
        );
    }

    public function show(Request $r, Usuario $usuario)
    {
        $usuario->load(['rol','personal.institucion']);
        $this->assertUsuarioContext($r,$usuario);
        return $usuario;
    }

    public function update(Request $r, Usuario $usuario)
    {
        $usuario->load(['rol','personal.institucion']);
        $this->assertUsuarioContext($r,$usuario);

        $before=$usuario->only([
            'usuario_id','personal_id','rol_id',
            'nombre_usuario','correo','estado'
        ]);

        $d=$r->validate($this->rules($usuario->usuario_id));
        $this->assertPersonalContext($r,$d['personal_id']??null);
        if((int)$r->user()?->usuario_id===(int)$usuario->usuario_id){
            if(array_key_exists('estado',$d) && !$d['estado']) return response()->json(['message'=>'No puede desactivar la cuenta con la que inició sesión.'],422);
            if((int)$d['rol_id']!==(int)$usuario->rol_id) return response()->json(['message'=>'No puede cambiar su propio rol mientras está autenticado.'],422);
        }

        $data=[
            'personal_id'=>$d['personal_id']??null,
            'rol_id'=>$d['rol_id'],
            'nombre_usuario'=>$d['nombre_usuario'],
            'correo'=>$d['correo'],
            'estado'=>$d['estado']??$usuario->estado,
        ];

        if(!empty($d['password'])){
            $data['password_hash']=Hash::make($d['password']);
        }

        $usuario->update($data);

        AuditoriaService::registrar(
            $r,'UPDATE','usuarios',$usuario->usuario_id,
            $before,
            $usuario->fresh()->only([
                'usuario_id','personal_id','rol_id',
                'nombre_usuario','correo','estado'
            ])
        );

        return $usuario->fresh()->load(['rol','personal.institucion']);
    }

    public function destroy(Request $r, Usuario $usuario)
    {
        if((int)$r->user()?->usuario_id===(int)$usuario->usuario_id){
            return response()->json(['message'=>'No puede desactivar la cuenta con la que inició sesión.'],422);
        }
        $usuario->load(['rol','personal.institucion']);
        $this->assertUsuarioContext($r,$usuario);

        $before=$usuario->only([
            'usuario_id','nombre_usuario','correo','rol_id','estado'
        ]);

        $usuario->update(['estado'=>0]);

        AuditoriaService::registrar(
            $r,'UPDATE','usuarios',$usuario->usuario_id,
            $before,
            $usuario->fresh()->only([
                'usuario_id','nombre_usuario','correo','rol_id','estado'
            ]),
            'Usuario desactivado'
        );

        return response()->json(['message'=>'Usuario desactivado']);
    }

    private function assertPersonalContext(Request $request, ?int $personalId): void
    {
        $slug=InstitutionContext::fromRequest($request);
        if(!$slug || !$personalId) return;

        $personal=Personal::with('institucion')->findOrFail($personalId);

        if(!InstitutionContext::matchesName($personal->institucion?->nombre,$slug)){
            abort(403,'El personal no pertenece a la institución seleccionada.');
        }
    }

    private function assertUsuarioContext(Request $request, Usuario $usuario): void
    {
        $slug=InstitutionContext::fromRequest($request);
        if(!$slug || !$usuario->personal_id) return;

        if(!InstitutionContext::matchesName(
            $usuario->personal?->institucion?->nombre,
            $slug
        )){
            abort(403,'El usuario no pertenece a la institución seleccionada.');
        }
    }

    private function rules(?int $id=null): array
    {
        return [
            'personal_id'=>[
                'nullable','integer','exists:personal,personal_id',
                Rule::unique('usuarios','personal_id')->ignore($id,'usuario_id')
            ],
            'rol_id'=>'required|integer|exists:roles,rol_id',
            'nombre_usuario'=>[
                'required','string','max:100',
                Rule::unique('usuarios','nombre_usuario')->ignore($id,'usuario_id')
            ],
            'correo'=>[
                'required','email','max:150',
                Rule::unique('usuarios','correo')->ignore($id,'usuario_id')
            ],
            'password'=>$id ? 'nullable|string|min:8' : 'required|string|min:8',
            'estado'=>'nullable|boolean',
        ];
    }
}
