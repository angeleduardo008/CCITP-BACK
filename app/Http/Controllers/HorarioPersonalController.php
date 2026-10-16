<?php
namespace App\Http\Controllers;

use App\Models\HorarioPersonal;
use App\Models\Personal;
use App\Services\AuditoriaService;
use App\Support\InstitutionContext;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class HorarioPersonalController extends Controller
{
    public function index(Request $request)
    {
        $q=HorarioPersonal::with(['personal.institucion']);

        if($request->filled('estado')){
            $q->where('estado',(int)$request->estado);
        }
        if($request->filled('personal_id')){
            $q->where('personal_id',(int)$request->personal_id);
        }

        InstitutionContext::applyRelation(
            $q,
            'personal.institucion',
            InstitutionContext::fromRequest($request)
        );

        return $q->orderBy('personal_id')
            ->orderBy('dia_semana')
            ->orderBy('bloque')
            ->paginate((int)$request->get('per_page',200));
    }

    public function store(Request $request)
    {
        $data=$request->validate($this->rules());
        $this->assertPersonalContext($request,(int)$data['personal_id']);
        $this->assertPersonalActivo((int)$data['personal_id']);

        $row=HorarioPersonal::create($data);

        AuditoriaService::registrar(
            $request,'INSERT','horarios_personal',
            $row->horario_personal_id,null,$row->toArray()
        );

        return response()->json($row->load('personal.institucion'),201);
    }

    /**
     * Asigna una jornada semanal completa preservando el histórico.
     * Cierra horarios vigentes de los días seleccionados al día anterior
     * de la nueva vigencia y crea uno o más bloques por día.
     */
    public function storeWeekly(Request $request)
    {
        $data=$request->validate([
            'personal_id'=>'required|integer|exists:personal,personal_id',
            'dias'=>'required|array|min:1',
            'dias.*'=>'required|integer|between:1,7',
            'bloques'=>'required|array|min:1|max:3',
            'bloques.*.bloque'=>'required|integer|min:1|max:3',
            'bloques.*.concepto'=>'nullable|string|max:150',
            'bloques.*.hora_entrada'=>'required|date_format:H:i',
            'bloques.*.hora_salida'=>'required|date_format:H:i',
            'tolerancia_minutos'=>'nullable|integer|min:0|max:180',
            'fecha_desde'=>'required|date',
            'fecha_hasta'=>'nullable|date|after_or_equal:fecha_desde',
        ]);

        $this->assertPersonalContext($request,(int)$data['personal_id']);
        $this->assertPersonalActivo((int)$data['personal_id']);
        $this->validateBlocks($data['bloques']);

        $dias=array_values(array_unique(array_map('intval',$data['dias'])));
        sort($dias);
        $inicio=Carbon::parse($data['fecha_desde'])->startOfDay();
        $finAnterior=$inicio->copy()->subDay()->toDateString();
        $tolerancia=(int)($data['tolerancia_minutos'] ?? 0);

        [$cerrados,$creados]=DB::transaction(function() use($data,$dias,$finAnterior,$tolerancia){
            $vigentes=HorarioPersonal::where('personal_id',$data['personal_id'])
                ->whereIn('dia_semana',$dias)
                ->where('estado',1)
                ->get();

            $cerrados=[];
            foreach($vigentes as $horario){
                $desde=$horario->fecha_desde ? (string)$horario->fecha_desde : null;
                $fechaHasta=$desde && $finAnterior < $desde ? $desde : $finAnterior;
                $horario->update(['estado'=>0,'fecha_hasta'=>$fechaHasta]);
                $cerrados[]=$horario->fresh()->toArray();
            }

            $creados=[];
            foreach($dias as $dia){
                foreach($data['bloques'] as $bloque){
                    $creados[]=HorarioPersonal::create([
                        'personal_id'=>$data['personal_id'],
                        'dia_semana'=>$dia,
                        'bloque'=>(int)$bloque['bloque'],
                        'concepto'=>$bloque['concepto'] ?? null,
                        'hora_entrada'=>$bloque['hora_entrada'],
                        'hora_salida'=>$bloque['hora_salida'],
                        'tolerancia_minutos'=>$tolerancia,
                        'fecha_desde'=>$data['fecha_desde'],
                        'fecha_hasta'=>$data['fecha_hasta'] ?? null,
                        'estado'=>1,
                    ]);
                }
            }
            return [$cerrados,$creados];
        });

        $ids=array_map(fn($h)=>$h->horario_personal_id,$creados);
        AuditoriaService::registrar(
            $request,'UPDATE','horarios_personal',implode(',',$ids),
            $cerrados,
            array_map(fn($h)=>$h->toArray(),$creados),
            'Asignación semanal de horario preservando histórico.'
        );

        return response()->json([
            'message'=>'Horario semanal asignado correctamente.',
            'cerrados'=>count($cerrados),
            'creados'=>HorarioPersonal::with('personal.institucion')->whereIn('horario_personal_id',$ids)->get(),
        ],201);
    }

    public function show(Request $request, HorarioPersonal $item)
    {
        $item->load('personal.institucion');
        $this->assertItemContext($request,$item);
        return $item;
    }

    public function update(Request $request, HorarioPersonal $item)
    {
        $item->load('personal.institucion');
        $this->assertItemContext($request,$item);

        $before=$item->toArray();
        $data=$request->validate($this->rules());
        $this->assertPersonalContext($request,(int)$data['personal_id']);
        $this->assertPersonalActivo((int)$data['personal_id']);

        if($data['hora_salida'] <= $data['hora_entrada']){
            throw ValidationException::withMessages(['hora_salida'=>['La hora de salida debe ser posterior a la hora de entrada.']]);
        }

        $item->update($data);

        AuditoriaService::registrar(
            $request,'UPDATE','horarios_personal',
            $item->horario_personal_id,$before,$item->fresh()->toArray()
        );

        return $item->fresh()->load('personal.institucion');
    }

    public function destroy(Request $request, HorarioPersonal $item)
    {
        $item->load('personal.institucion');
        $this->assertItemContext($request,$item);

        $before=$item->toArray();
        $item->update(['estado'=>0]);

        AuditoriaService::registrar(
            $request,'UPDATE','horarios_personal',
            $item->horario_personal_id,$before,$item->fresh()->toArray(),
            'Horario desactivado conservando el histórico.'
        );

        return response()->json(['message'=>'Horario desactivado']);
    }

    private function assertPersonalContext(Request $request, int $personalId): void
    {
        $slug=InstitutionContext::fromRequest($request);
        if(!$slug) return;

        $personal=Personal::with('institucion')->findOrFail($personalId);

        if(!InstitutionContext::matchesName($personal->institucion?->nombre,$slug)){
            abort(403,'El personal no pertenece a la institución seleccionada.');
        }
    }

    private function assertPersonalActivo(int $personalId): void
    {
        $personal=Personal::findOrFail($personalId);
        if(!$personal->estado){
            abort(422,'No se puede asignar horario a personal cesado.');
        }
    }

    private function assertItemContext(Request $request, HorarioPersonal $item): void
    {
        $slug=InstitutionContext::fromRequest($request);
        if(!$slug) return;

        if(!InstitutionContext::matchesName($item->personal?->institucion?->nombre,$slug)){
            abort(403,'El horario no pertenece a la institución seleccionada.');
        }
    }

    private function validateBlocks(array $bloques): void
    {
        $seen=[];
        usort($bloques,fn($a,$b)=>(int)$a['bloque']<=>(int)$b['bloque']);
        $previousEnd=null;
        foreach($bloques as $bloque){
            $n=(int)$bloque['bloque'];
            if(isset($seen[$n])){
                throw ValidationException::withMessages(['bloques'=>['No se puede repetir el número de bloque.']]);
            }
            $seen[$n]=true;
            if($bloque['hora_salida'] <= $bloque['hora_entrada']){
                throw ValidationException::withMessages(['bloques'=>["En el bloque {$n}, la salida debe ser posterior a la entrada."]]);
            }
            if($previousEnd && $bloque['hora_entrada'] < $previousEnd){
                throw ValidationException::withMessages(['bloques'=>['Los bloques no pueden solaparse.']]);
            }
            $previousEnd=$bloque['hora_salida'];
        }
    }

    private function rules(): array
    {
        return [
            'personal_id'=>'required|integer|exists:personal,personal_id',
            'dia_semana'=>'required|integer|between:1,7',
            'bloque'=>'required|integer|min:1',
            'concepto'=>'nullable|string|max:150',
            'hora_entrada'=>'required|date_format:H:i',
            'hora_salida'=>'required|date_format:H:i',
            'tolerancia_minutos'=>'nullable|integer|min:0',
            'fecha_desde'=>'nullable|date',
            'fecha_hasta'=>'nullable|date|after_or_equal:fecha_desde',
            'estado'=>'boolean'
        ];
    }
}
