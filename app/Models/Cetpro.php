<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Cetpro extends Model
{
    protected $table = 'cetpros';
    protected $primaryKey = 'institucion_id';
    public $timestamps = true;
    protected $fillable = ['codigo_modular','modalidad','tipo_gestion','descripcion','estado'];
    public $incrementing = false;
}
