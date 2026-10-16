<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Escuela extends Model
{
    protected $table = 'escuelas';
    protected $primaryKey = 'institucion_id';
    public $timestamps = true;
    protected $fillable = ['codigo_modular','nivel_educativo','modalidad','descripcion','estado'];
    public $incrementing = false;
}
