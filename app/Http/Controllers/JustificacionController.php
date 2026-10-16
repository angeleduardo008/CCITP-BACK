<?php
namespace App\Http\Controllers;

use App\Models\AsistenciaPersonal;
use App\Models\Justificacion;
use App\Services\AuditoriaService;
use App\Support\InstitutionContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class JustificacionController extends Controller
{
    public function index(Request $r)
    {
        $q=Justificacion::with(['asistencia.personal.institucion','asistencia.horario']);
        InstitutionContext::applyRelation($q,'asistencia.personal.institucion',InstitutionContext::fromRequest($r));
        if(!$this->isAdmin($r)) $q->whereHas('asistencia',fn($x)=>$x->where('personal_id',$r->user()?->personal_id));
        if($r->filled('estado')) $q->where('estado',$r->estado);
        return $q->orderByDesc('fecha_solicitud')->paginate((int)$r->get('per_page',50));
    }

    public function candidatas(Request $r)
    {
        $q=AsistenciaPersonal::with(['personal.institucion','horario'])
            ->where(function($x){ $x->where('estado_asistencia','FALTA')->orWhere('estado_asistencia','TARDANZA')->orWhere('minutos_salida_anticipada','>',0); })
            ->whereDoesntHave('justificaciones',fn($j)=>$j->whereIn('estado',['PENDIENTE','APROBADA']));
        InstitutionContext::applyRelation($q,'personal.institucion',InstitutionContext::fromRequest($r));
        if(!$this->isAdmin($r)) $q->where('personal_id',$r->user()?->personal_id);
        return $q->orderByDesc('fecha')->limit(200)->get();
    }

    public function store(Request $r)
    {
        if($r->user()?->rol?->nombre==='CONSULTA') abort(403,'El rol CONSULTA no puede registrar justificaciones.');
        $d=$r->validate([
            'asistencia_personal_id'=>'required|exists:asistencias_personal,asistencia_personal_id',
            'tipo_justificacion'=>'required|string|max:100','motivo'=>'required|string|max:250','descripcion'=>'nullable|string','archivo'=>'nullable|file|max:5120|mimes:pdf,jpg,jpeg,png'
        ]);
        $asistencia=$this->assertAsistenciaContext($r,(int)$d['asistencia_personal_id']);
        if(!$this->isAdmin($r) && (int)$asistencia->personal_id!==(int)$r->user()?->personal_id) abort(403,'Solo puede justificar sus propias incidencias.');
        if(Justificacion::where('asistencia_personal_id',$asistencia->asistencia_personal_id)->whereIn('estado',['PENDIENTE','APROBADA'])->exists()) return response()->json(['message'=>'Esta incidencia ya tiene una justificación pendiente o aprobada.'],409);
        if($r->hasFile('archivo')) $d['archivo_evidencia']=$r->file('archivo')->store('justificaciones','public');
        unset($d['archivo']);
        $row=Justificacion::create($d+['estado'=>'PENDIENTE','fecha_solicitud'=>now()]);
        AuditoriaService::registrar($r,'INSERT','justificaciones',$row->justificacion_id,null,$row->toArray(),'Solicitud de justificación registrada.');
        return response()->json($row->load(['asistencia.personal.institucion','asistencia.horario']),201);
    }

    public function revisar(Request $r, Justificacion $justificacion)
    {
        if(!$this->isAdmin($r)) abort(403,'Solo un administrador puede revisar justificaciones.');
        $justificacion->load('asistencia.personal.institucion'); $this->assertJustificacionContext($r,$justificacion);
        if($justificacion->estado!=='PENDIENTE') return response()->json(['message'=>'La justificación ya fue revisada.'],409);
        $d=$r->validate(['estado'=>'required|in:APROBADA,RECHAZADA','observacion_revision'=>'nullable|string|max:500']);
        $before=$justificacion->toArray();

        DB::transaction(function() use($r,$d,$justificacion){
            $justificacion->update(['estado'=>$d['estado'],'observacion_revision'=>$d['observacion_revision']??null,'fecha_revision'=>now(),'revisado_por_personal_id'=>$r->user()?->personal_id]);
            if($d['estado']==='APROBADA'){
                $a=$justificacion->asistencia;
                $nota='Justificación aprobada #'.$justificacion->justificacion_id.'; estado anterior: '.$a->estado_asistencia.'.';
                $a->update(['estado_asistencia'=>'PERMISO','observacion'=>trim(($a->observacion?$a->observacion.' | ':'').$nota)]);
            }
        });

        AuditoriaService::registrar($r,$d['estado']==='APROBADA'?'APROBAR':'RECHAZAR','justificaciones',$justificacion->justificacion_id,$before,$justificacion->fresh()->toArray(),$d['observacion_revision']??null);
        return $justificacion->fresh()->load(['asistencia.personal.institucion','asistencia.horario']);
    }

    private function isAdmin(Request $r): bool { return $r->user()?->rol?->nombre==='ADMINISTRADOR'; }
    private function assertAsistenciaContext(Request $r,int $id): AsistenciaPersonal
    {
        $a=AsistenciaPersonal::with('personal.institucion')->findOrFail($id); $slug=InstitutionContext::fromRequest($r);
        if($slug && !InstitutionContext::matchesName($a->personal?->institucion?->nombre,$slug)) abort(403,'La asistencia no pertenece a la institución seleccionada.');
        return $a;
    }
    private function assertJustificacionContext(Request $r,Justificacion $j): void
    { $slug=InstitutionContext::fromRequest($r); if($slug && !InstitutionContext::matchesName($j->asistencia?->personal?->institucion?->nombre,$slug)) abort(403,'La justificación no pertenece a la institución seleccionada.'); }
}
