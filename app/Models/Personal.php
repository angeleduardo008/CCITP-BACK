<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Personal extends Model
{
    use SoftDeletes;
    protected $table = 'personal';
    protected $primaryKey = 'personal_id';
    protected $fillable = ['institucion_id','cargo_id','codigo_personal','tipo_documento','numero_documento','nombres','apellidos','telefono','correo','fecha_nacimiento','fecha_ingreso','fecha_cese','estado'];
    protected $casts = ['fecha_nacimiento'=>'date','fecha_ingreso'=>'date','fecha_cese'=>'date','estado'=>'boolean'];

    public function institucion(){ return $this->belongsTo(Institucion::class,'institucion_id','institucion_id'); }
    public function cargo(){ return $this->belongsTo(Cargo::class,'cargo_id','cargo_id'); }
    public function horarios(){ return $this->hasMany(HorarioPersonal::class,'personal_id','personal_id'); }
    public function remuneraciones(){ return $this->hasMany(RemuneracionPersonal::class,'personal_id','personal_id'); }
    public function usuario(){ return $this->hasOne(Usuario::class,'personal_id','personal_id'); }
}
