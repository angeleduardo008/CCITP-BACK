<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Role extends Model
{
    protected $table='roles'; protected $primaryKey='rol_id';
    protected $fillable=['nombre','descripcion','estado'];
    public function usuarios(){ return $this->hasMany(Usuario::class,'rol_id','rol_id'); }
}
