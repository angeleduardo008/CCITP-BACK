<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Descuento extends Model
{
    protected $table = 'descuentos';
    protected $primaryKey = 'descuento_id';
    public $timestamps = true;
    protected $fillable = ['planilla_id','asistencia_personal_id','tipo_descuento','minutos_afectados','horas_afectadas','monto_referencia','monto_descuento','motivo','estado','fecha_generacion','observacion'];
    protected $casts = ['monto_referencia'=>'decimal:2','monto_descuento'=>'decimal:2'];
}
