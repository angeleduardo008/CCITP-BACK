<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Justificacion extends Model
{
    protected $table = 'justificaciones';
    protected $primaryKey = 'justificacion_id';
    public $timestamps = true;
    protected $fillable = ['asistencia_personal_id','tipo_justificacion','motivo','descripcion','archivo_evidencia','fecha_solicitud','estado','revisado_por_personal_id','fecha_revision','observacion_revision'];
    public function asistencia(){ return $this->belongsTo(AsistenciaPersonal::class,'asistencia_personal_id','asistencia_personal_id'); }
}
