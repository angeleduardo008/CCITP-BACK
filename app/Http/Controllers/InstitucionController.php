<?php
namespace App\Http\Controllers;
use App\Models\Institucion;
use App\Services\AuditoriaService;
use App\Support\InstitutionContext;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class InstitucionController extends Controller
{
    private const OFICIALES = ['CCITP','DIVERTIMUNDO','PITÁGORAS SCHOOL','PITAGORAS SCHOOL'];

    public function index(Request $request){
        $q=Institucion::query();
        if($request->filled('estado')) $q->where('estado',(int)$request->estado);
        $slug=InstitutionContext::fromRequest($request);
        if($slug){
            $aliases=InstitutionContext::aliases($slug);
            $q->where(function($x) use($aliases){ foreach($aliases as $i=>$alias){ $i===0?$x->where('nombre','like','%'.$alias.'%'):$x->orWhere('nombre','like','%'.$alias.'%'); } });
        }
        return $q->orderByDesc('estado')->orderBy('nombre')->paginate((int)$request->get('per_page',20));
    }
    public function store(Request $request){
        $data=$request->validate(['nombre'=>'required|string|max:150','razon_social'=>'nullable|string|max:200','ruc'=>'nullable|string|max:20','direccion'=>'nullable|string|max:250','telefono'=>'nullable|string|max:20','correo'=>'nullable|email|max:150','estado'=>'boolean']);
        $row=Institucion::create($data); AuditoriaService::registrar($request,'INSERT','instituciones',$row->institucion_id,null,$row->toArray());
        return response()->json($row,201);
    }
    public function show(Institucion $item){ return $item; }
    public function update(Request $request, Institucion $item){
        $before=$item->toArray();
        $data=$request->validate(['nombre'=>'required|string|max:150','razon_social'=>'nullable|string|max:200','ruc'=>'nullable|string|max:20','direccion'=>'nullable|string|max:250','telefono'=>'nullable|string|max:20','correo'=>'nullable|email|max:150','estado'=>'boolean']);
        if($this->esOficial($item)){
            if(array_key_exists('estado',$data) && !$data['estado']){
                throw ValidationException::withMessages(['estado'=>'Las instituciones oficiales del sistema no se pueden desactivar.']);
            }
            if(mb_strtoupper(trim((string)$data['nombre']),'UTF-8') !== mb_strtoupper(trim((string)$item->nombre),'UTF-8')){
                throw ValidationException::withMessages(['nombre'=>'El nombre técnico de una institución oficial no se puede cambiar. Puede editar razón social, RUC, dirección, teléfono y correo.']);
            }
        }
        $item->update($data);
        AuditoriaService::registrar($request,'UPDATE','instituciones',$item->institucion_id,$before,$item->fresh()->toArray()); return $item->fresh();
    }
    public function destroy(Request $request, Institucion $item){
        if($this->esOficial($item)){
            throw ValidationException::withMessages(['institucion'=>'CCITP, Divertimundo y Pitágoras School son instituciones oficiales y no se pueden desactivar.']);
        }
        $before=$item->toArray(); $item->update(['estado'=>0]); AuditoriaService::registrar($request,'UPDATE','instituciones',$item->institucion_id,$before,$item->fresh()->toArray(),'Registro desactivado'); return response()->json(['message'=>'Registro desactivado']);
    }
    private function esOficial(Institucion $item): bool
    {
        $n=mb_strtoupper(trim((string)$item->nombre),'UTF-8');
        return in_array($n,self::OFICIALES,true);
    }
}
