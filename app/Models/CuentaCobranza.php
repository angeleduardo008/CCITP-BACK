<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CuentaCobranza extends Model
{
    protected $table='cuentas_cobranza';
    protected $primaryKey='cuenta_cobranza_id';
    protected $fillable=['alumno_id','concepto','periodo','monto_total','monto_pagado','saldo','fecha_vencimiento','estado','observacion'];
    protected $casts=['monto_total'=>'decimal:2','monto_pagado'=>'decimal:2','saldo'=>'decimal:2','fecha_vencimiento'=>'date'];
    public function alumno(){ return $this->belongsTo(Alumno::class,'alumno_id','alumno_id'); }
    public function pagos(){ return $this->hasMany(PagoCobranza::class,'cuenta_cobranza_id','cuenta_cobranza_id')->orderByDesc('fecha_pago'); }
}
