<?php
namespace App\Http\Controllers;

use App\Models\AsistenciaPersonal;
use App\Models\CuentaCobranza;
use App\Models\Personal;
use App\Models\Planilla;
use App\Support\InstitutionContext;
use Carbon\Carbon;
use Illuminate\Http\Request;

class ReporteController extends Controller
{
    public function resumen(Request $r)
    {
        $slug=InstitutionContext::fromRequest($r);
        $fecha=$r->get('fecha',now()->toDateString());
        try{$periodo=Carbon::parse($fecha);}catch(\Throwable){$periodo=now();}

        $personal=Personal::query(); InstitutionContext::applyRelation($personal,'institucion',$slug);
        $as=AsistenciaPersonal::whereDate('fecha',$fecha); InstitutionContext::applyRelation($as,'personal.institucion',$slug);
        $c=CuentaCobranza::query(); InstitutionContext::applyRelation($c,'alumno.institucion',$slug);
        $p=Planilla::query(); InstitutionContext::applyRelation($p,'personal.institucion',$slug);

        return response()->json([
            'fecha'=>$fecha,
            'personal_activo'=>(clone $personal)->where('estado',1)->count(),
            'presentes'=>(clone $as)->where('estado_asistencia','PRESENTE')->count(),
            'tardanzas'=>(clone $as)->where('estado_asistencia','TARDANZA')->count(),
            'faltas'=>(clone $as)->where('estado_asistencia','FALTA')->count(),
            'permisos'=>(clone $as)->where('estado_asistencia','PERMISO')->count(),
            'salidas_anticipadas'=>(clone $as)->where('minutos_salida_anticipada','>',0)->count(),
            'saldo_cobranza'=>(float)(clone $c)->sum('saldo'),
            'cuentas_morosas'=>(clone $c)->where('estado','MOROSO')->count(),
            'neto_planillas'=>(float)(clone $p)->where('anio',$periodo->year)->where('mes',$periodo->month)->sum('monto_neto'),
            'pagado_planillas'=>(float)(clone $p)->where('anio',$periodo->year)->where('mes',$periodo->month)->sum('monto_pagado'),
            'saldo_planillas'=>(float)(clone $p)->where('anio',$periodo->year)->where('mes',$periodo->month)->sum('saldo_pago'),
        ]);
    }

    public function datos(Request $r,string $tipo)
    {
        return response()->json($this->dataset($r,$tipo));
    }

    public function csv(Request $r,string $tipo)
    {
        $rows=$this->dataset($r,$tipo);
        $name=$tipo.'-'.now()->format('Ymd-His').'.csv';
        return response()->streamDownload(function() use($rows){
            $out=fopen('php://output','w'); fwrite($out,"\xEF\xBB\xBF");
            if(!$rows){fputcsv($out,['Sin registros'],';');fclose($out);return;}
            fputcsv($out,array_keys($rows[0]),';'); foreach($rows as $row) fputcsv($out,array_values($row),';'); fclose($out);
        },$name,['Content-Type'=>'text/csv; charset=UTF-8']);
    }

    private function dataset(Request $r,string $tipo): array
    {
        $slug=InstitutionContext::fromRequest($r);
        if($tipo==='asistencias'){
            $q=AsistenciaPersonal::with(['personal.institucion','horario']); InstitutionContext::applyRelation($q,'personal.institucion',$slug);
            if($r->filled('desde'))$q->whereDate('fecha','>=',$r->desde); if($r->filled('hasta'))$q->whereDate('fecha','<=',$r->hasta);
            return $q->orderByDesc('fecha')->get()->map(fn($a)=>[
                'Fecha'=>$a->fecha?->format('Y-m-d'),'Personal'=>trim($a->personal?->apellidos.', '.$a->personal?->nombres),'Concepto'=>$a->horario?->concepto,
                'Entrada programada'=>$a->hora_entrada_programada,'Entrada real'=>$a->fecha_hora_entrada?->format('Y-m-d H:i:s'),'Tardanza min'=>$a->minutos_tardanza,
                'Salida programada'=>$a->hora_salida_programada,'Salida real'=>$a->fecha_hora_salida?->format('Y-m-d H:i:s'),'Salida anticipada min'=>$a->minutos_salida_anticipada,
                'Estado'=>$a->estado_asistencia,'Incidencias'=>$a->incidencias_resumen
            ])->all();
        }
        if($tipo==='planillas'){
            $q=Planilla::with(['personal.institucion','remuneracion.tipoPago']); InstitutionContext::applyRelation($q,'personal.institucion',$slug);
            if($r->filled('anio'))$q->where('anio',$r->anio); if($r->filled('mes'))$q->where('mes',$r->mes);
            return $q->orderByDesc('anio')->orderByDesc('mes')->get()->map(fn($p)=>[
                'Año'=>$p->anio,'Mes'=>$p->mes,'Personal'=>trim($p->personal?->apellidos.', '.$p->personal?->nombres),'Tipo pago'=>$p->remuneracion?->tipoPago?->nombre,
                'Horas'=>$p->horas_trabajadas,'Bruto'=>$p->monto_bruto,'Descuentos'=>$p->total_descuentos,'Neto'=>$p->monto_neto,
                'Pagado'=>$p->monto_pagado ?? 0,'Saldo'=>$p->saldo_pago ?? $p->monto_neto,'Estado'=>$p->estado
            ])->all();
        }
        if($tipo==='cobranza'){
            $q=CuentaCobranza::with('alumno.institucion'); InstitutionContext::applyRelation($q,'alumno.institucion',$slug);
            return $q->orderBy('estado')->orderBy('fecha_vencimiento')->get()->map(fn($c)=>[
                'Alumno'=>trim($c->alumno?->apellidos.', '.$c->alumno?->nombres),'Apoderado'=>$c->alumno?->apoderado,'Programa/Turno'=>$c->alumno?->programa_turno,
                'Concepto'=>$c->concepto,'Periodo'=>$c->periodo,'Total'=>$c->monto_total,'Pagado'=>$c->monto_pagado,'Saldo'=>$c->saldo,
                'Vencimiento'=>$c->fecha_vencimiento?->format('Y-m-d'),'Estado'=>$c->estado,'Observación'=>$c->observacion
            ])->all();
        }
        abort(404,'Reporte no válido.');
    }
}
