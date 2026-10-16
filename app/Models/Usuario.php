<?php
namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\SoftDeletes;

class Usuario extends Authenticatable
{
    use SoftDeletes;
    protected $table = 'usuarios';
    protected $primaryKey = 'usuario_id';
    protected $fillable = ['personal_id','rol_id','nombre_usuario','correo','password_hash','estado','ultimo_acceso','intentos_fallidos','bloqueado_hasta'];
    protected $hidden = ['password_hash'];
    protected $casts = ['estado'=>'boolean','ultimo_acceso'=>'datetime','bloqueado_hasta'=>'datetime'];

    public function getAuthPassword(){ return $this->password_hash; }
    public function rol(){ return $this->belongsTo(Role::class,'rol_id','rol_id'); }
    public function personal(){ return $this->belongsTo(Personal::class,'personal_id','personal_id'); }
}
