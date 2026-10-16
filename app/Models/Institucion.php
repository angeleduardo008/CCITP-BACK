<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Institucion extends Model
{
    protected $table = 'instituciones';
    protected $primaryKey = 'institucion_id';
    public $timestamps = true;
    protected $fillable = ['nombre','razon_social','ruc','direccion','telefono','correo','estado'];
}
