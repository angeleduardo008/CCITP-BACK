<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PagoPlanilla extends Model
{
    protected $table='pagos_planilla';
    protected $primaryKey='pago_planilla_id';
    protected $fillable=['planilla_id','monto','fecha_pago','metodo','referencia','observacion','usuario_id'];
    protected $casts=['monto'=>'decimal:2','fecha_pago'=>'datetime'];
    public function planilla(){ return $this->belongsTo(Planilla::class,'planilla_id','planilla_id'); }
    public function usuario(){ return $this->belongsTo(Usuario::class,'usuario_id','usuario_id'); }
}
