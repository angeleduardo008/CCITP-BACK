<?php
namespace App\Http\Controllers;

use App\Models\Personal;
use App\Models\RemuneracionPersonal;
use App\Services\AuditoriaService;
use App\Support\InstitutionContext;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RemuneracionController extends Controller
{
    public function index(Request $request)
    {
        $q=RemuneracionPersonal::with(['personal.institucion','tipoPago'])
            ->when($request->personal_id,fn($q,$id)=>$q->where('personal_id',$id));

        InstitutionContext::applyRelation(
            $q,
            'personal.institucion',
            InstitutionContext::fromRequest($request)
        );

        return $q->orderByDesc('fecha_inicio')
            ->paginate((int)$request->get('per_page',25));
    }

    public function store(Request $request)
    {
        $data=$request->validate([
            'personal_id'=>'required|exists:personal,personal_id',
            'tipo_pago_id'=>'required|exists:tipos_pago,tipo_pago_id',
            'concepto'=>'nullable|string|max:150',
            'monto'=>'required|numeric|min:0.01',
            'fecha_inicio'=>'required|date',
            'observacion'=>'nullable|string|max:250'
        ]);

        $slug=InstitutionContext::fromRequest($request);
        $personal=Personal::with('institucion')->findOrFail((int)$data['personal_id']);
        if(!$personal->estado){
            abort(422,'No se puede asignar una nueva remuneración a personal cesado.');
        }
        if($slug && !InstitutionContext::matchesName($personal->institucion?->nombre,$slug)){
            abort(403,'El personal no pertenece a la institución seleccionada.');
        }

        $inicio=Carbon::parse($data['fecha_inicio'])->startOfDay();
        $fechaFinAnterior=$inicio->copy()->subDay()->toDateString();

        $row=DB::transaction(function() use($data,$fechaFinAnterior){
            RemuneracionPersonal::where('personal_id',$data['personal_id'])
                ->where('estado',1)
                ->where(function($q) use($data){
                    $concepto=trim((string)($data['concepto']??''));
                    if($concepto==='') $q->whereNull('concepto')->orWhere('concepto','');
                    else $q->where('concepto',$concepto);
                })
                ->get()
                ->each(function(RemuneracionPersonal $anterior) use($fechaFinAnterior){
                    $fechaInicioAnterior=$anterior->fecha_inicio?->format('Y-m-d');
                    $fin=$fechaInicioAnterior && $fechaFinAnterior < $fechaInicioAnterior
                        ? $fechaInicioAnterior
                        : $fechaFinAnterior;
                    $anterior->update(['estado'=>0,'fecha_fin'=>$fin]);
                });

            return RemuneracionPersonal::create($data+['estado'=>1,'fecha_fin'=>null]);
        });

        AuditoriaService::registrar(
            $request,'INSERT','remuneraciones_personal',
            $row->remuneracion_id,null,$row->toArray(),
            'Nueva remuneración vigente. La vigencia anterior se cerró respecto a la fecha de inicio indicada.'
        );

        return response()->json(
            $row->load(['personal.institucion','tipoPago']),
            201
        );
    }
}
