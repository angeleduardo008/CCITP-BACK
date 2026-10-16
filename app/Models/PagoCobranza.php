<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PagoCobranza extends Model
{
    protected $table='pagos_cobranza';
    protected $primaryKey='pago_cobranza_id';
    protected $fillable=['cuenta_cobranza_id','monto','fecha_pago','metodo','referencia','observacion','usuario_id'];
    protected $casts=['monto'=>'decimal:2','fecha_pago'=>'datetime'];
    public function cuenta(){ return $this->belongsTo(CuentaCobranza::class,'cuenta_cobranza_id','cuenta_cobranza_id'); }
    public function usuario(){ return $this->belongsTo(Usuario::class,'usuario_id','usuario_id'); }
}
