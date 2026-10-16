<?php
namespace App\Http\Controllers;

use App\Models\Alumno;
use App\Models\CuentaCobranza;
use App\Models\PagoCobranza;
use App\Services\AuditoriaService;
use App\Support\InstitutionContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CobranzaController extends Controller
{
    public function index(Request $r)
    {
        $this->actualizarMorosidad();
        $q=CuentaCobranza::with(['alumno.institucion','pagos']);
        if($r->filled('estado')) $q->where('estado',$r->estado);
        if($r->filled('periodo')) $q->where('periodo',$r->periodo);
        InstitutionContext::applyRelation($q,'alumno.institucion',InstitutionContext::fromRequest($r));
        return $q->orderByRaw("FIELD(estado,'MOROSO','PENDIENTE','PARCIAL','PAGADO')")->orderBy('fecha_vencimiento')->paginate((int)$r->get('per_page',50));
    }

    public function stats(Request $r)
    {
        $this->actualizarMorosidad();
        $base=CuentaCobranza::query();
        InstitutionContext::applyRelation($base,'alumno.institucion',InstitutionContext::fromRequest($r));
        return response()->json([
            'total_cuentas'=>(clone $base)->count(),
            'pendientes'=>(clone $base)->where('estado','PENDIENTE')->count(),
            'parciales'=>(clone $base)->where('estado','PARCIAL')->count(),
            'morosos'=>(clone $base)->where('estado','MOROSO')->count(),
            'pagados'=>(clone $base)->where('estado','PAGADO')->count(),
            'por_cobrar'=>(float)(clone $base)->sum('saldo'),
            'cobrado'=>(float)(clone $base)->sum('monto_pagado'),
        ]);
    }

    public function store(Request $r)
    {
        $d=$r->validate($this->rules()); $this->assertAlumnoContext($r,(int)$d['alumno_id']);
        $d=$this->normalizar($d); $row=CuentaCobranza::create($d);
        AuditoriaService::registrar($r,'INSERT','cuentas_cobranza',$row->cuenta_cobranza_id,null,$row->toArray());
        return response()->json($row->load(['alumno.institucion','pagos']),201);
    }

    public function show(Request $r, CuentaCobranza $cobranza)
    { $cobranza->load(['alumno.institucion','pagos.usuario']); $this->assertCobranzaContext($r,$cobranza); return $cobranza; }

    public function update(Request $r, CuentaCobranza $cobranza)
    {
        $cobranza->load('alumno.institucion'); $this->assertCobranzaContext($r,$cobranza); $before=$cobranza->toArray();
        $d=$r->validate($this->rules()); $this->assertAlumnoContext($r,(int)$d['alumno_id']);
        // Si existe historial de pagos, el total pagado real prevalece.
        $pagadoHist=(float)$cobranza->pagos()->sum('monto');
        if($pagadoHist>0){
            if((float)$d['monto_total']+0.001<$pagadoHist) return response()->json(['message'=>'El monto total no puede quedar por debajo de los pagos ya registrados.'],422);
            $d['monto_pagado']=$pagadoHist;
        }
        $d=$this->normalizar($d); $cobranza->update($d);
        AuditoriaService::registrar($r,'UPDATE','cuentas_cobranza',$cobranza->cuenta_cobranza_id,$before,$cobranza->fresh()->toArray());
        return $cobranza->fresh()->load(['alumno.institucion','pagos']);
    }

    public function registrarPago(Request $r, CuentaCobranza $cobranza)
    {
        $cobranza->load('alumno.institucion'); $this->assertCobranzaContext($r,$cobranza);
        $d=$r->validate(['monto'=>'required|numeric|min:0.01','fecha_pago'=>'nullable|date','metodo'=>'nullable|string|max:50','referencia'=>'nullable|string|max:120','observacion'=>'nullable|string|max:500']);
        if((float)$d['monto']>(float)$cobranza->saldo) return response()->json(['message'=>'El pago no puede superar el saldo pendiente.'],422);

        $pago=DB::transaction(function() use($r,$cobranza,$d){
            // Compatibilidad con cuentas antiguas que ya tenían monto_pagado sin historial detallado.
            if(!$cobranza->pagos()->exists() && (float)$cobranza->monto_pagado>0){
                PagoCobranza::create([
                    'cuenta_cobranza_id'=>$cobranza->cuenta_cobranza_id,
                    'monto'=>$cobranza->monto_pagado,
                    'fecha_pago'=>$cobranza->updated_at ?? now(),
                    'metodo'=>'MIGRADO','referencia'=>'SALDO-HISTORICO','observacion'=>'Pago previo migrado automáticamente.','usuario_id'=>$r->user()?->usuario_id,
                ]);
            }
            $pago=PagoCobranza::create([
                'cuenta_cobranza_id'=>$cobranza->cuenta_cobranza_id,'monto'=>$d['monto'],'fecha_pago'=>$d['fecha_pago']??now(),
                'metodo'=>$d['metodo']??null,'referencia'=>$d['referencia']??null,'observacion'=>$d['observacion']??null,
                'usuario_id'=>$r->user()?->usuario_id,
            ]);
            $pagado=(float)$cobranza->pagos()->sum('monto'); $saldo=max(0,(float)$cobranza->monto_total-$pagado);
            $estado=$saldo<=0?'PAGADO':($pagado>0?'PARCIAL':'PENDIENTE');
            if($saldo>0 && $cobranza->fecha_vencimiento && $cobranza->fecha_vencimiento->lt(now()->startOfDay())) $estado='MOROSO';
            $cobranza->update(['monto_pagado'=>$pagado,'saldo'=>$saldo,'estado'=>$estado]);
            return $pago;
        });
        AuditoriaService::registrar($r,'INSERT','pagos_cobranza',$pago->pago_cobranza_id,null,$pago->toArray(),'Pago registrado en cobranza.');
        return response()->json(['message'=>'Pago registrado','pago'=>$pago,'cuenta'=>$cobranza->fresh()->load('pagos')],201);
    }

    public function pagos(Request $r, CuentaCobranza $cobranza)
    { $cobranza->load('alumno.institucion'); $this->assertCobranzaContext($r,$cobranza); return $cobranza->pagos()->with('usuario')->get(); }

    public function destroy(Request $r, CuentaCobranza $cobranza)
    {
        $cobranza->load('alumno.institucion'); $this->assertCobranzaContext($r,$cobranza); $before=$cobranza->toArray();
        DB::transaction(function() use($r,$cobranza){
            if(!$cobranza->pagos()->exists() && (float)$cobranza->monto_pagado>0){
                PagoCobranza::create(['cuenta_cobranza_id'=>$cobranza->cuenta_cobranza_id,'monto'=>$cobranza->monto_pagado,'fecha_pago'=>$cobranza->updated_at ?? now(),'metodo'=>'MIGRADO','referencia'=>'SALDO-HISTORICO','observacion'=>'Pago previo migrado automáticamente.','usuario_id'=>$r->user()?->usuario_id]);
            }
            $pagado=(float)$cobranza->pagos()->sum('monto');
            $restante=max(0,(float)$cobranza->monto_total-$pagado);
            if($restante>0){
                PagoCobranza::create(['cuenta_cobranza_id'=>$cobranza->cuenta_cobranza_id,'monto'=>$restante,'fecha_pago'=>now(),'metodo'=>'AJUSTE_ADMIN','referencia'=>'CIERRE-MANUAL','observacion'=>'Cancelación manual desde cobranza.','usuario_id'=>$r->user()?->usuario_id]);
            }
            $cobranza->update(['estado'=>'PAGADO','monto_pagado'=>$cobranza->monto_total,'saldo'=>0]);
        });
        AuditoriaService::registrar($r,'UPDATE','cuentas_cobranza',$cobranza->cuenta_cobranza_id,$before,$cobranza->fresh()->toArray(),'Cuenta cerrada manualmente como PAGADO con historial de cancelación.');
        return response()->json(['message'=>'Cuenta marcada como pagada']);
    }

    private function actualizarMorosidad(): void
    { CuentaCobranza::where('saldo','>',0)->whereNotNull('fecha_vencimiento')->whereDate('fecha_vencimiento','<',now()->toDateString())->where('estado','!=','PAGADO')->update(['estado'=>'MOROSO']); }

    private function assertAlumnoContext(Request $r,int $id): void
    { $slug=InstitutionContext::fromRequest($r); if(!$slug)return; $a=Alumno::with('institucion')->findOrFail($id); if(!InstitutionContext::matchesName($a->institucion?->nombre,$slug)) abort(403,'El alumno no pertenece a la institución seleccionada.'); }
    private function assertCobranzaContext(Request $r,CuentaCobranza $c): void
    { $slug=InstitutionContext::fromRequest($r); if($slug && !InstitutionContext::matchesName($c->alumno?->institucion?->nombre,$slug)) abort(403,'La cuenta no pertenece a la institución seleccionada.'); }
    private function rules(): array
    { return ['alumno_id'=>'required|integer|exists:alumnos,alumno_id','concepto'=>'required|string|max:150','periodo'=>'nullable|string|max:50','monto_total'=>'required|numeric|min:0','monto_pagado'=>'nullable|numeric|min:0','fecha_vencimiento'=>'nullable|date','estado'=>'nullable|in:PENDIENTE,PARCIAL,PAGADO,MOROSO','observacion'=>'nullable|string|max:500']; }
    private function normalizar(array $d): array
    {
        $total=(float)$d['monto_total']; $pagado=min($total,(float)($d['monto_pagado']??0)); $saldo=max(0,$total-$pagado);
        $d['monto_pagado']=$pagado; $d['saldo']=$saldo;
        if($saldo<=0)$d['estado']='PAGADO'; elseif(!empty($d['fecha_vencimiento']) && $d['fecha_vencimiento']<now()->toDateString())$d['estado']='MOROSO'; elseif($pagado>0)$d['estado']='PARCIAL'; else $d['estado']='PENDIENTE';
        return $d;
    }
}
