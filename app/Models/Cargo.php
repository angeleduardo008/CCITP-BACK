<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Cargo extends Model
{
    protected $table = 'cargos';
    protected $primaryKey = 'cargo_id';
    public $timestamps = true;
    protected $fillable = ['nombre','descripcion','estado'];
}
