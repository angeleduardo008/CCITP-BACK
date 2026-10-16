<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Auditoria extends Model
{
    protected $table = 'auditoria';
    protected $primaryKey = 'auditoria_id';
    public $timestamps = false;
    protected $fillable = ['usuario_id','accion','tabla_afectada','registro_id','valor_anterior','valor_nuevo','descripcion','direccion_ip','user_agent','fecha_hora'];
    protected $casts = ['valor_anterior'=>'array','valor_nuevo'=>'array','fecha_hora'=>'datetime'];
    public function usuario(){ return $this->belongsTo(Usuario::class,'usuario_id','usuario_id'); }
}
