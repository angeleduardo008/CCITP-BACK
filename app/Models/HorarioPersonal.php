<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HorarioPersonal extends Model
{
    protected $table='horarios_personal';
    protected $primaryKey='horario_personal_id';
    protected $fillable=['personal_id','dia_semana','bloque','concepto','hora_entrada','hora_salida','tolerancia_minutos','fecha_desde','fecha_hasta','estado'];
    protected $casts=['estado'=>'boolean','fecha_desde'=>'date','fecha_hasta'=>'date'];
    public function personal(){ return $this->belongsTo(Personal::class,'personal_id','personal_id'); }
}
