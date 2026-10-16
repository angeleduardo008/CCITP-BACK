<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TipoPago extends Model
{
    protected $table = 'tipos_pago';
    protected $primaryKey = 'tipo_pago_id';
    public $timestamps = true;
    protected $fillable = ['nombre','descripcion','estado'];
}
