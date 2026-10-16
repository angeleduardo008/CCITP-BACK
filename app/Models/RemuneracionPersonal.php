<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class RemuneracionPersonal extends Model
{
    protected $table='remuneraciones_personal'; protected $primaryKey='remuneracion_id';
    protected $fillable=['personal_id','tipo_pago_id','concepto','monto','fecha_inicio','fecha_fin','estado','observacion'];
    protected $casts=['monto'=>'decimal:2','fecha_inicio'=>'date','fecha_fin'=>'date','estado'=>'boolean'];
    public function personal(){ return $this->belongsTo(Personal::class,'personal_id','personal_id'); }
    public function tipoPago(){ return $this->belongsTo(TipoPago::class,'tipo_pago_id','tipo_pago_id'); }
}
