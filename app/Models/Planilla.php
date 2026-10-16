<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Planilla extends Model
{
    protected $table='planillas';
    protected $primaryKey='planilla_id';
    protected $fillable=['personal_id','remuneracion_id','anio','mes','monto_referencia','horas_trabajadas','monto_bruto','total_descuentos','monto_neto','monto_pagado','saldo_pago','fecha_ultimo_pago','estado','fecha_calculo','observacion'];
    protected $casts=['monto_referencia'=>'decimal:2','horas_trabajadas'=>'decimal:2','monto_bruto'=>'decimal:2','total_descuentos'=>'decimal:2','monto_neto'=>'decimal:2','monto_pagado'=>'decimal:2','saldo_pago'=>'decimal:2','fecha_ultimo_pago'=>'datetime','fecha_calculo'=>'datetime'];
    public function personal(){ return $this->belongsTo(Personal::class,'personal_id','personal_id'); }
    public function remuneracion(){ return $this->belongsTo(RemuneracionPersonal::class,'remuneracion_id','remuneracion_id'); }
    public function descuentos(){ return $this->hasMany(Descuento::class,'planilla_id','planilla_id'); }
    public function pagos(){ return $this->hasMany(PagoPlanilla::class,'planilla_id','planilla_id')->orderByDesc('fecha_pago'); }
}
