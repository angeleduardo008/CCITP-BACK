<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Alumno extends Model
{
    use SoftDeletes;
    protected $table='alumnos';
    protected $primaryKey='alumno_id';
    protected $fillable=[
        'institucion_id','codigo_alumno','id_externo','tipo_documento','numero_documento','nombres','apellidos',
        'fecha_nacimiento','telefono','correo','apoderado','telefono_apoderado','programa_turno','monto_matricula',
        'monto_mensualidad','observacion','estado'
    ];
    protected $casts=['fecha_nacimiento'=>'date','estado'=>'boolean','monto_matricula'=>'decimal:2','monto_mensualidad'=>'decimal:2'];
    public function institucion(){ return $this->belongsTo(Institucion::class,'institucion_id','institucion_id'); }
    public function cuentas(){ return $this->hasMany(CuentaCobranza::class,'alumno_id','alumno_id'); }
}
