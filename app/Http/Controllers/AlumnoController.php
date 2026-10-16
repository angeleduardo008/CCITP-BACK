<?php
namespace App\Http\Controllers;

use App\Models\Alumno;
use App\Services\AuditoriaService;
use App\Support\InstitutionContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AlumnoController extends Controller
{
    public function index(Request $r)
    {
        $q=Alumno::with('institucion');
        InstitutionContext::applyRelation($q,'institucion',InstitutionContext::fromRequest($r));
        if($r->filled('estado')) $q->where('estado',(int)$r->estado);
        if($r->filled('q')){
            $term='%'.trim((string)$r->q).'%';
            $q->where(fn($x)=>$x->where('nombres','like',$term)->orWhere('apellidos','like',$term)->orWhere('numero_documento','like',$term)->orWhere('codigo_alumno','like',$term)->orWhere('apoderado','like',$term));
        }
        return $q->orderBy('apellidos')->orderBy('nombres')->paginate((int)$r->get('per_page',50));
    }

    public function store(Request $r)
    {
        $d=$r->validate($this->rules());
        InstitutionContext::assertInstitutionId((int)$d['institucion_id'],InstitutionContext::fromRequest($r));
        $row=Alumno::create($d);
        AuditoriaService::registrar($r,'INSERT','alumnos',$row->alumno_id,null,$row->toArray());
        return response()->json($row->load('institucion'),201);
    }

    public function show(Request $r, Alumno $alumno)
    {
        $alumno->load('institucion'); $this->assertContext($r,$alumno); return $alumno;
    }

    public function update(Request $r, Alumno $alumno)
    {
        $alumno->load('institucion'); $this->assertContext($r,$alumno);
        $before=$alumno->toArray();
        $d=$r->validate($this->rules($alumno->alumno_id));
        InstitutionContext::assertInstitutionId((int)$d['institucion_id'],InstitutionContext::fromRequest($r));
        $alumno->update($d);
        AuditoriaService::registrar($r,'UPDATE','alumnos',$alumno->alumno_id,$before,$alumno->fresh()->toArray());
        return $alumno->fresh()->load('institucion');
    }

    public function destroy(Request $r, Alumno $alumno)
    {
        $alumno->load('institucion'); $this->assertContext($r,$alumno);
        $before=$alumno->toArray(); $alumno->update(['estado'=>0]);
        AuditoriaService::registrar($r,'UPDATE','alumnos',$alumno->alumno_id,$before,$alumno->fresh()->toArray(),'Alumno desactivado conservando historial de cobranza.');
        return response()->json(['message'=>'Alumno desactivado']);
    }

    private function assertContext(Request $r, Alumno $alumno): void
    {
        $slug=InstitutionContext::fromRequest($r);
        if($slug && !InstitutionContext::matchesName($alumno->institucion?->nombre,$slug)) abort(403,'El alumno no pertenece a la institución seleccionada.');
    }

    private function rules(?int $id=null): array
    {
        return [
            'institucion_id'=>'required|integer|exists:instituciones,institucion_id',
            'codigo_alumno'=>['nullable','string','max:50',Rule::unique('alumnos','codigo_alumno')->ignore($id,'alumno_id')],
            'id_externo'=>'nullable|string|max:100','tipo_documento'=>'nullable|string|max:20',
            'numero_documento'=>['nullable','string','max:20',Rule::unique('alumnos','numero_documento')->ignore($id,'alumno_id')],
            'nombres'=>'required|string|max:150','apellidos'=>'required|string|max:150','fecha_nacimiento'=>'nullable|date',
            'telefono'=>'nullable|string|max:20','correo'=>'nullable|email|max:150','apoderado'=>'nullable|string|max:180',
            'telefono_apoderado'=>'nullable|string|max:30','programa_turno'=>'nullable|string|max:150',
            'monto_matricula'=>'nullable|numeric|min:0','monto_mensualidad'=>'nullable|numeric|min:0','observacion'=>'nullable|string|max:500','estado'=>'nullable|boolean'
        ];
    }
}
