<?php
namespace App\Http\Controllers;

use App\Models\AsistenciaPersonal;
use App\Models\Descuento;
use App\Models\HorarioPersonal;
use App\Models\Personal;
use App\Models\PagoPlanilla;
use App\Models\Planilla;
use App\Models\RemuneracionPersonal;
use App\Services\AuditoriaService;
use App\Support\InstitutionContext;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PlanillaController extends Controller
{
    public function index(Request $r)
    {
        $q=Planilla::with(['personal.institucion','remuneracion.tipoPago','descuentos'])
            ->when($r->anio,fn($q,$v)=>$q->where('anio',$v))
            ->when($r->mes,fn($q,$v)=>$q->where('mes',$v));
        InstitutionContext::applyRelation($q,'personal.institucion',InstitutionContext::fromRequest($r));
        if($r->user()?->rol?->nombre!=='ADMINISTRADOR') $q->where('personal_id',$r->user()?->personal_id);
        return $q->orderByDesc('anio')->orderByDesc('mes')->orderBy('personal_id')->paginate((int)$r->get('per_page',100));
    }

    public function generar(Request $r)
    {
        $d=$r->validate(['anio'=>'required|integer|min:2020|max:2100','mes'=>'required|integer|min:1|max:12','institucion'=>'nullable|string']);
        $slug=InstitutionContext::fromRequest($r);
        $inicio=Carbon::create((int)$d['anio'],(int)$d['mes'],1)->startOfDay();
        $fin=$inicio->copy()->endOfMonth()->endOfDay();

        $personalQ=Personal::with('institucion')->where(function($q) use($inicio,$fin){
            $q->whereNull('fecha_ingreso')->orWhereDate('fecha_ingreso','<=',$fin->toDateString());
        })->where(function($q) use($inicio){
            $q->whereNull('fecha_cese')->orWhereDate('fecha_cese','>=',$inicio->toDateString());
        });
        InstitutionContext::applyRelation($personalQ,'institucion',$slug);
        $personas=$personalQ->get();

        $creadas=0; $actualizadas=0; $omitidas=[];
        DB::transaction(function() use($personas,$inicio,$fin,$d,$r,&$creadas,&$actualizadas,&$omitidas){
            foreach($personas as $p){
                $remus=RemuneracionPersonal::with('tipoPago')
                    ->where('personal_id',$p->personal_id)
                    ->whereDate('fecha_inicio','<=',$fin->toDateString())
                    ->where(fn($q)=>$q->whereNull('fecha_fin')->orWhereDate('fecha_fin','>=',$inicio->toDateString()))
                    ->orderBy('fecha_inicio')->get();
                if($remus->isEmpty()){ $omitidas[]=['personal_id'=>$p->personal_id,'nombre'=>trim($p->nombres.' '.$p->apellidos),'motivo'=>'Sin remuneración vigente']; continue; }

                $principal=$remus->first();
                $asistencias=AsistenciaPersonal::with(['horario','justificaciones'])
                    ->where('personal_id',$p->personal_id)->whereBetween('fecha',[$inicio->toDateString(),$fin->toDateString()])->get();

                $minTrabajados=0;
                foreach($asistencias as $a){
                    if($a->fecha_hora_entrada && $a->fecha_hora_salida){
                        $minTrabajados+=max(0,$a->fecha_hora_entrada->diffInMinutes($a->fecha_hora_salida,false));
                    }
                }
                $horas=round($minTrabajados/60,2);

                $esHora=$remus->contains(fn($x)=>$x->tipoPago?->nombre==='PAGO_POR_HORA');
                if($esHora){
                    $principal=$remus->first(fn($x)=>$x->tipoPago?->nombre==='PAGO_POR_HORA') ?: $principal;
                    $bruto=0.0;
                    foreach($asistencias as $a){
                        if(!$a->fecha_hora_entrada || !$a->fecha_hora_salida) continue;
                        $concepto=trim((string)($a->horario?->concepto ?? ''));
                        $rate=$remus->first(function($x) use($concepto){
                            return $x->tipoPago?->nombre==='PAGO_POR_HORA' && trim((string)$x->concepto)===$concepto;
                        }) ?: $remus->first(fn($x)=>$x->tipoPago?->nombre==='PAGO_POR_HORA' && !$x->concepto) ?: $principal;
                        $mins=max(0,$a->fecha_hora_entrada->diffInMinutes($a->fecha_hora_salida,false));
                        $bruto+=($mins/60)*(float)$rate->monto;
                    }
                    $montoReferencia=(float)$principal->monto;
                } else {
                    $bruto=(float)$principal->monto;
                    $montoReferencia=(float)$principal->monto;
                }

                $planilla=Planilla::firstOrNew(['personal_id'=>$p->personal_id,'anio'=>(int)$d['anio'],'mes'=>(int)$d['mes']]);
                $eraNueva=!$planilla->exists;
                if($planilla->exists && in_array($planilla->estado,['APROBADA','CERRADA','ANULADA'],true)){
                    $omitidas[]=['personal_id'=>$p->personal_id,'nombre'=>trim($p->nombres.' '.$p->apellidos),'motivo'=>'Planilla bloqueada por estado '.$planilla->estado];
                    continue;
                }
                $planilla->fill([
                    'remuneracion_id'=>$principal->remuneracion_id,'monto_referencia'=>round($montoReferencia,2),'horas_trabajadas'=>$horas,
                    'monto_bruto'=>round($bruto,2),'estado'=>$planilla->estado && !in_array($planilla->estado,['BORRADOR','CALCULADA'])?$planilla->estado:'CALCULADA',
                    'fecha_calculo'=>now(),'observacion'=>$esHora?'Cálculo por horas reales trabajadas y tarifa por curso/actividad cuando existe.':'Cálculo mensual con incidencias automáticas.'
                ]);
                $planilla->save();

                // Regenerar solo descuentos automáticos.
                $planilla->descuentos()->where('motivo','like','[AUTO]%')->delete();
                $autoTotal=0.0;
                if(!$esHora){
                    $programados=$this->minutosProgramados($p->personal_id,$inicio,$fin);
                    $valorMin=$programados>0?$bruto/$programados:0;
                    foreach($asistencias as $a){
                        $justificada=$a->estado_asistencia==='PERMISO' || $a->justificaciones->contains(fn($j)=>$j->estado==='APROBADA');
                        if($justificada) continue;
                        $items=[];
                        if($a->estado_asistencia==='FALTA'){
                            $mins=$this->minutosHorario($a->hora_entrada_programada,$a->hora_salida_programada);
                            $items[]=['FALTA',$mins,'[AUTO] Falta del '.$a->fecha->format('d/m/Y')];
                        }
                        if((int)$a->minutos_tardanza>0) $items[]=['TARDANZA',(int)$a->minutos_tardanza,'[AUTO] Tardanza del '.$a->fecha->format('d/m/Y')];
                        if((int)$a->minutos_salida_anticipada>0) $items[]=['SALIDA_ANTICIPADA',(int)$a->minutos_salida_anticipada,'[AUTO] Salida anticipada del '.$a->fecha->format('d/m/Y')];
                        foreach($items as [$tipo,$mins,$motivo]){
                            $monto=round($mins*$valorMin,2); if($monto<=0)continue; $autoTotal+=$monto;
                            Descuento::create(['planilla_id'=>$planilla->planilla_id,'asistencia_personal_id'=>$a->asistencia_personal_id,'tipo_descuento'=>$tipo,'minutos_afectados'=>$mins,'horas_afectadas'=>round($mins/60,2),'monto_referencia'=>round($valorMin,4),'monto_descuento'=>$monto,'motivo'=>$motivo,'estado'=>'APROBADO','fecha_generacion'=>now()]);
                        }
                    }
                }
                $manual=(float)$planilla->descuentos()->where('estado','APROBADO')->where('motivo','not like','[AUTO]%')->sum('monto_descuento');
                $total=round($autoTotal+$manual,2);
                $neto=max(0,round($bruto-$total,2));
                $pagado=(float)$planilla->pagos()->sum('monto');
                // Compatibilidad con una eventual carga histórica previa al detalle de pagos.
                if($pagado<=0 && (float)($planilla->monto_pagado ?? 0)>0) $pagado=(float)$planilla->monto_pagado;
                $planilla->update([
                    'total_descuentos'=>$total,
                    'monto_neto'=>$neto,
                    'monto_pagado'=>round($pagado,2),
                    'saldo_pago'=>max(0,round($neto-$pagado,2)),
                ]);
                $eraNueva?$creadas++:$actualizadas++;
            }
        });

        AuditoriaService::registrar($r,'OTRO','planillas',null,null,['anio'=>$d['anio'],'mes'=>$d['mes'],'institucion'=>$slug,'creadas'=>$creadas,'actualizadas'=>$actualizadas],'Generación/cálculo mensual de planillas.');
        return response()->json(['message'=>'Planillas calculadas','creadas'=>$creadas,'actualizadas'=>$actualizadas,'omitidas'=>$omitidas]);
    }

    public function cambiarEstado(Request $r, Planilla $planilla)
    {
        $d=$r->validate(['estado'=>'required|in:BORRADOR,CALCULADA,APROBADA,CERRADA,ANULADA']);
        $planilla->load('personal.institucion');
        $slug=InstitutionContext::fromRequest($r); if($slug && !InstitutionContext::matchesName($planilla->personal?->institucion?->nombre,$slug)) abort(403);
        if(in_array($planilla->estado,['CERRADA','ANULADA'],true) && $d['estado']!==$planilla->estado) return response()->json(['message'=>'Una planilla cerrada o anulada es un registro final y no puede reabrirse.'],422);
        if($d['estado']==='ANULADA' && (float)($planilla->monto_pagado ?? 0)>0) return response()->json(['message'=>'No se puede anular una planilla que ya tiene pagos registrados.'],422);
        if($d['estado']==='CERRADA' && (float)($planilla->saldo_pago ?? $planilla->monto_neto)>0) return response()->json(['message'=>'No se puede cerrar la planilla mientras exista saldo pendiente. Registre primero la cancelación.'],422);
        $before=$planilla->toArray(); $planilla->update(['estado'=>$d['estado']]);
        AuditoriaService::registrar($r,$d['estado']==='APROBADA'?'APROBAR':($d['estado']==='ANULADA'?'ANULAR':'UPDATE'),'planillas',$planilla->planilla_id,$before,$planilla->fresh()->toArray());
        return $planilla->fresh()->load(['personal.institucion','remuneracion.tipoPago','descuentos']);
    }

    public function agregarDescuento(Request $r, Planilla $planilla)
    {
        $planilla->load('personal.institucion');
        $slug=InstitutionContext::fromRequest($r);
        if($slug && !InstitutionContext::matchesName($planilla->personal?->institucion?->nombre,$slug)) abort(403);
        if(in_array($planilla->estado,['CERRADA','ANULADA'],true)) return response()->json(['message'=>'No se puede modificar una planilla cerrada o anulada.'],422);
        if((float)($planilla->monto_pagado ?? 0)>0) return response()->json(['message'=>'No se pueden agregar descuentos después de registrar pagos en la planilla.'],422);
        $d=$r->validate(['monto_descuento'=>'required|numeric|min:0.01','motivo'=>'required|string|max:250','observacion'=>'nullable|string|max:500']);
        $row=Descuento::create(['planilla_id'=>$planilla->planilla_id,'tipo_descuento'=>'OTRO','monto_descuento'=>$d['monto_descuento'],'monto_referencia'=>$planilla->monto_bruto,'motivo'=>$d['motivo'],'observacion'=>$d['observacion']??null,'estado'=>'APROBADO','fecha_generacion'=>now()]);
        $total=(float)$planilla->descuentos()->where('estado','APROBADO')->sum('monto_descuento');
        $planilla->update(['total_descuentos'=>$total,'monto_neto'=>max(0,(float)$planilla->monto_bruto-$total)]);
        AuditoriaService::registrar($r,'INSERT','descuentos',$row->descuento_id,null,$row->toArray());
        return response()->json(['message'=>'Descuento agregado','planilla'=>$planilla->fresh()->load('descuentos')],201);
    }

    public function registrarPago(Request $r, Planilla $planilla)
    {
        $planilla->load('personal.institucion');
        $slug=InstitutionContext::fromRequest($r);
        if($slug && !InstitutionContext::matchesName($planilla->personal?->institucion?->nombre,$slug)) abort(403);
        if($planilla->estado!=='APROBADA') return response()->json(['message'=>'Solo se pueden registrar pagos en una planilla APROBADA.'],422);
        $d=$r->validate(['monto'=>'required|numeric|min:0.01','fecha_pago'=>'nullable|date','metodo'=>'nullable|string|max:50','referencia'=>'nullable|string|max:120','observacion'=>'nullable|string|max:500']);
        $saldo=(float)($planilla->saldo_pago ?? $planilla->monto_neto);
        if((float)$d['monto']>$saldo+0.001) return response()->json(['message'=>'El pago no puede superar el saldo pendiente.'],422);

        $pago=DB::transaction(function() use($r,$planilla,$d){
            // Si la BD traía un monto pagado sin historial, preservarlo antes del nuevo pago.
            if(!$planilla->pagos()->exists() && (float)($planilla->monto_pagado ?? 0)>0){
                PagoPlanilla::create(['planilla_id'=>$planilla->planilla_id,'monto'=>$planilla->monto_pagado,'fecha_pago'=>$planilla->fecha_ultimo_pago ?? $planilla->updated_at ?? now(),'metodo'=>'MIGRADO','referencia'=>'SALDO-HISTORICO','observacion'=>'Pago previo migrado automáticamente.','usuario_id'=>$r->user()?->usuario_id]);
            }
            $pago=PagoPlanilla::create(['planilla_id'=>$planilla->planilla_id,'monto'=>$d['monto'],'fecha_pago'=>$d['fecha_pago']??now(),'metodo'=>$d['metodo']??null,'referencia'=>$d['referencia']??null,'observacion'=>$d['observacion']??null,'usuario_id'=>$r->user()?->usuario_id]);
            $pagado=(float)$planilla->pagos()->sum('monto');
            $saldo=max(0,round((float)$planilla->monto_neto-$pagado,2));
            $updates=['monto_pagado'=>round($pagado,2),'saldo_pago'=>$saldo,'fecha_ultimo_pago'=>$pago->fecha_pago];
            if($saldo<=0 && $planilla->estado==='APROBADA') $updates['estado']='CERRADA';
            $planilla->update($updates);
            return $pago;
        });
        AuditoriaService::registrar($r,'INSERT','pagos_planilla',$pago->pago_planilla_id,null,$pago->toArray(),'Pago/cancelación de planilla registrado.');
        return response()->json(['message'=>'Pago registrado','pago'=>$pago,'planilla'=>$planilla->fresh()->load(['descuentos','pagos'])],201);
    }

    public function pagos(Request $r, Planilla $planilla)
    {
        $planilla->load('personal.institucion');
        $slug=InstitutionContext::fromRequest($r);
        if($slug && !InstitutionContext::matchesName($planilla->personal?->institucion?->nombre,$slug)) abort(403);
        return $planilla->pagos()->with('usuario')->get();
    }

    private function minutosProgramados(int $personalId,Carbon $inicio,Carbon $fin): int
    {
        $horarios=HorarioPersonal::where('personal_id',$personalId)->where(fn($q)=>$q->whereNull('fecha_desde')->orWhereDate('fecha_desde','<=',$fin->toDateString()))->where(fn($q)=>$q->whereNull('fecha_hasta')->orWhereDate('fecha_hasta','>=',$inicio->toDateString()))->get();
        $mins=0; for($d=$inicio->copy()->startOfDay();$d->lte($fin);$d->addDay()){
            foreach($horarios as $h){ if((int)$h->dia_semana!==$d->isoWeekday())continue; if($h->fecha_desde && $d->lt($h->fecha_desde))continue; if($h->fecha_hasta && $d->gt($h->fecha_hasta))continue; $mins+=$this->minutosHorario($h->hora_entrada,$h->hora_salida); }
        } return $mins;
    }
    private function minutosHorario($entrada,$salida): int
    { try{return max(0,Carbon::parse($entrada)->diffInMinutes(Carbon::parse($salida),false));}catch(\Throwable){return 0;} }
}
