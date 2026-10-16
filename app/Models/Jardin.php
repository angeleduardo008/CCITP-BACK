<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Jardin extends Model
{
    protected $table = 'jardines';
    protected $primaryKey = 'institucion_id';
    public $timestamps = true;
    protected $fillable = ['edad_minima','edad_maxima','descripcion','estado'];
    public $incrementing = false;
}
