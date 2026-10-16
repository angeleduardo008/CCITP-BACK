<?php
namespace App\Http\Controllers;
use App\Models\Cargo;
use App\Services\AuditoriaService;
use Illuminate\Http\Request;

class CargoController extends Controller
{
    public function index(Request $request){
        $q=Cargo::query();
        if($request->filled('estado')) $q->where('estado',(int)$request->estado);
        return $q->orderBy('cargo_id','desc')->paginate((int)$request->get('per_page',20));
    }
    public function store(Request $request){
        $data=$request->validate(['nombre'=>'required|string|max:100','descripcion'=>'nullable|string|max:250','estado'=>'boolean']);
        $row=Cargo::create($data); AuditoriaService::registrar($request,'INSERT','cargos',$row->cargo_id,null,$row->toArray());
        return response()->json($row,201);
    }
    public function show(Cargo $item){ return $item; }
    public function update(Request $request, Cargo $item){
        $before=$item->toArray(); $data=$request->validate(['nombre'=>'required|string|max:100','descripcion'=>'nullable|string|max:250','estado'=>'boolean']); $item->update($data);
        AuditoriaService::registrar($request,'UPDATE','cargos',$item->cargo_id,$before,$item->fresh()->toArray()); return $item->fresh();
    }
    public function destroy(Request $request, Cargo $item){
        $before=$item->toArray(); $item->update(['estado'=>0]); AuditoriaService::registrar($request,'UPDATE','cargos',$item->cargo_id,$before,$item->fresh()->toArray(),'Registro desactivado'); return response()->json(['message'=>'Registro desactivado']);
    }
}
