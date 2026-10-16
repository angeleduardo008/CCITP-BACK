<?php
namespace App\Services;

use App\Models\AsistenciaPersonal;
use App\Models\HorarioPersonal;
use App\Support\InstitutionContext;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class AsistenciaAutomaticaService
{
    public function procesarFecha(Carbon $fecha, ?string $institucion=null, ?Carbon $ahora=null): array
    {
        $fecha=$fecha->copy()->startOfDay(); $ahora=($ahora?:now())->copy(); $hoy=$ahora->copy()->startOfDay();
        if($fecha->greaterThan($hoy)){ $r=$this->resultadoBase($fecha,$institucion);$r['omitida']=true;return $r; }

        $q=HorarioPersonal::query()->with(['personal.institucion'])
            ->where('dia_semana',$fecha->isoWeekday())
            ->where(fn($x)=>$x->where('estado',1)->orWhereNotNull('fecha_hasta'))
            ->where(fn($x)=>$x->whereNull('fecha_desde')->orWhereDate('fecha_desde','<=',$fecha->toDateString()))
            ->where(fn($x)=>$x->whereNull('fecha_hasta')->orWhereDate('fecha_hasta','>=',$fecha->toDateString()))
            ->whereHas('personal',function($x) use($fecha){
                $x->whereNull('deleted_at')
                  ->where(fn($y)=>$y->whereNull('fecha_ingreso')->orWhereDate('fecha_ingreso','<=',$fecha->toDateString()))
                  ->where(fn($y)=>$y->whereNull('fecha_cese')->orWhereDate('fecha_cese','>=',$fecha->toDateString()));
            });
        InstitutionContext::applyRelation($q,'personal.institucion',$institucion);
        $horarios=$q->orderBy('personal_id')->orderBy('bloque')->get();
        $r=$this->resultadoBase($fecha,$institucion); $r['horarios_evaluados']=$horarios->count();

        DB::transaction(function() use($horarios,$fecha,$ahora,&$r){
            foreach($horarios as $h){
                if(!$h->personal) continue;
                $fin=Carbon::parse($fecha->toDateString().' '.$h->hora_salida,config('app.timezone'));
                if($fecha->isSameDay($ahora)&&$ahora->lt($fin)){ $r['bloques_en_curso']++; continue; }

                $a=AsistenciaPersonal::firstOrCreate([
                    'personal_id'=>$h->personal_id,'horario_personal_id'=>$h->horario_personal_id,'fecha'=>$fecha->toDateString()
                ],[
                    'hora_entrada_programada'=>$h->hora_entrada,'hora_salida_programada'=>$h->hora_salida,
                    'estado_asistencia'=>'PENDIENTE','minutos_tardanza'=>0,'minutos_salida_anticipada'=>0,
                ]);
                if($a->wasRecentlyCreated)$r['asistencias_creadas']++;
                if($a->estado_asistencia==='PERMISO'){ $r['permisos_respetados']++; continue; }

                if(!$a->fecha_hora_entrada){
                    $ya=$a->estado_asistencia==='FALTA';
                    $nota='FALTA automática: finalizó el bloque sin marcación de entrada.';
                    $a->update(['estado_asistencia'=>'FALTA','minutos_tardanza'=>0,'minutos_salida_anticipada'=>0,'observacion'=>$this->agregarObservacion($a->observacion,$nota)]);
                    if(!$ya)$r['faltas_generadas']++;
                    continue;
                }

                if($a->estado_asistencia==='PENDIENTE'){
                    $a->update(['estado_asistencia'=>(int)$a->minutos_tardanza>0?'TARDANZA':'PRESENTE']); $r['estados_normalizados']++;
                }
                if(!$a->fecha_hora_salida)$r['salidas_no_registradas']++;
            }
        });
        return $r;
    }

    public function procesarAyerYHoy(?string $institucion=null): array
    { $ahora=now(); return ['ayer'=>$this->procesarFecha($ahora->copy()->subDay(),$institucion,$ahora),'hoy'=>$this->procesarFecha($ahora->copy(),$institucion,$ahora)]; }

    private function resultadoBase(Carbon $fecha,?string $institucion): array
    { return ['fecha'=>$fecha->toDateString(),'institucion'=>$institucion,'horarios_evaluados'=>0,'bloques_en_curso'=>0,'asistencias_creadas'=>0,'faltas_generadas'=>0,'estados_normalizados'=>0,'salidas_no_registradas'=>0,'permisos_respetados'=>0,'omitida'=>false]; }

    private function agregarObservacion(?string $actual,string $nueva): string
    { $actual=trim((string)$actual);if($actual!==''&&str_contains($actual,$nueva))return $actual;return $actual===''?$nueva:$actual.' | '.$nueva; }
}
