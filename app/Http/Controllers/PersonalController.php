<?php
namespace App\Http\Controllers;

use App\Models\Personal;
use App\Models\HorarioPersonal;
use App\Models\RemuneracionPersonal;
use App\Models\Usuario;
use App\Services\AuditoriaService;
use App\Support\InstitutionContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PersonalController extends Controller
{
    public function index(Request $request)
    {
        $q=Personal::with(['institucion','cargo']);

        if($request->filled('institucion_id')){
            $q->where('institucion_id',(int)$request->institucion_id);
        }else{
            InstitutionContext::applyRelation(
                $q,
                'institucion',
                InstitutionContext::fromRequest($request)
            );
        }

        if($request->filled('estado')){
            $q->where('estado',(int)$request->estado);
        }

        if($request->filled('q')){
            $term='%'.$request->q.'%';
            $q->where(function($x) use($term){
                $x->where('nombres','like',$term)
                  ->orWhere('apellidos','like',$term)
                  ->orWhere('numero_documento','like',$term)
                  ->orWhere('codigo_personal','like',$term);
            });
        }

        return $q->orderBy('apellidos')
            ->orderBy('nombres')
            ->paginate((int)$request->get('per_page',20));
    }

    public function store(Request $request)
    {
        $data=$request->validate($this->rules());
        InstitutionContext::assertInstitutionId(
            (int)$data['institucion_id'],
            InstitutionContext::fromRequest($request)
        );

        $row=Personal::create($data);
        AuditoriaService::registrar(
            $request,'INSERT','personal',$row->personal_id,null,$row->toArray()
        );

        return response()->json(
            $row->load(['institucion','cargo']),
            201
        );
    }

    public function show(Request $request, Personal $personal)
    {
        $personal->load(['institucion','cargo','horarios','remuneraciones.tipoPago','usuario.rol']);
        $this->assertContext($request,$personal);
        return $personal;
    }

    public function update(Request $request, Personal $personal)
    {
        $this->assertContext($request,$personal);

        $before=$personal->toArray();
        $data=$request->validate($this->rules($personal->personal_id));
        InstitutionContext::assertInstitutionId(
            (int)$data['institucion_id'],
            InstitutionContext::fromRequest($request)
        );

        $personal->update($data);
        AuditoriaService::registrar(
            $request,'UPDATE','personal',$personal->personal_id,
            $before,$personal->fresh()->toArray()
        );

        return $personal->fresh()->load(['institucion','cargo']);
    }

    public function cesar(Request $request, Personal $personal)
    {
        $this->assertContext($request,$personal);

        $data=$request->validate(['fecha_cese'=>'required|date']);
        $before=$personal->load(['horarios','remuneraciones','usuario'])->toArray();
        $fechaCese=$data['fecha_cese'];

        DB::transaction(function() use($personal,$fechaCese){
            $personal->update([
                'fecha_cese'=>$fechaCese,
                'estado'=>0
            ]);

            HorarioPersonal::where('personal_id',$personal->personal_id)
                ->where('estado',1)
                ->get()
                ->each(function(HorarioPersonal $horario) use($fechaCese){
                    $horario->update([
                        'estado'=>0,
                        'fecha_hasta'=>$this->safeEndDate($horario->fecha_desde,$fechaCese)
                    ]);
                });

            RemuneracionPersonal::where('personal_id',$personal->personal_id)
                ->where('estado',1)
                ->get()
                ->each(function(RemuneracionPersonal $remuneracion) use($fechaCese){
                    $remuneracion->update([
                        'estado'=>0,
                        'fecha_fin'=>$this->safeEndDate($remuneracion->fecha_inicio?->format('Y-m-d'),$fechaCese)
                    ]);
                });

            Usuario::where('personal_id',$personal->personal_id)
                ->where('estado',1)
                ->update(['estado'=>0]);
        });

        $after=$personal->fresh()->load(['horarios','remuneraciones','usuario'])->toArray();
        AuditoriaService::registrar(
            $request,'UPDATE','personal',$personal->personal_id,
            $before,$after,'Cese de personal: se cerraron horarios, remuneración vigente y acceso de usuario.'
        );

        return $personal->fresh()->load(['institucion','cargo']);
    }

    private function safeEndDate($startDate, string $requestedEnd): string
    {
        if(!$startDate) return $requestedEnd;
        return $requestedEnd < (string)$startDate ? (string)$startDate : $requestedEnd;
    }

    private function assertContext(Request $request, Personal $personal): void
    {
        $personal->loadMissing('institucion');
        $slug=InstitutionContext::fromRequest($request);
        if($slug && !InstitutionContext::matchesName($personal->institucion?->nombre,$slug)){
            abort(403,'El personal no pertenece a la institución seleccionada.');
        }
    }

    private function rules(?int $id=null): array
    {
        return [
            'institucion_id'=>'required|integer|exists:instituciones,institucion_id',
            'cargo_id'=>'required|integer|exists:cargos,cargo_id',
            'codigo_personal'=>[
                'nullable','string','max:30',
                Rule::unique('personal','codigo_personal')->ignore($id,'personal_id')
            ],
            'tipo_documento'=>'nullable|string|max:20',
            'numero_documento'=>[
                'nullable','string','max:20',
                Rule::unique('personal','numero_documento')->ignore($id,'personal_id')
            ],
            'nombres'=>'required|string|max:150',
            'apellidos'=>'required|string|max:150',
            'telefono'=>'nullable|string|max:20',
            'correo'=>'nullable|email|max:150',
            'fecha_nacimiento'=>'nullable|date',
            'fecha_ingreso'=>'nullable|date',
            'fecha_cese'=>'nullable|date',
            'estado'=>'boolean'
        ];
    }
}
