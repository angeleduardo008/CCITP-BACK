<?php
namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class AsistenciaPersonal extends Model
{
    use SoftDeletes;

    protected $table='asistencias_personal';
    protected $primaryKey='asistencia_personal_id';

    protected $fillable=[
        'personal_id','horario_personal_id','fecha','hora_entrada_programada','hora_salida_programada',
        'fecha_hora_entrada','fecha_hora_salida','estado_asistencia','minutos_tardanza',
        'minutos_salida_anticipada','foto_entrada','foto_salida','latitud_entrada','longitud_entrada',
        'latitud_salida','longitud_salida','observacion'
    ];

    protected $casts=[
        'fecha'=>'date',
        'fecha_hora_entrada'=>'datetime',
        'fecha_hora_salida'=>'datetime',
        'minutos_tardanza'=>'integer',
        'minutos_salida_anticipada'=>'integer',
    ];

    protected $appends=['incidencias_resumen'];

    public function personal(){ return $this->belongsTo(Personal::class,'personal_id','personal_id'); }
    public function horario(){ return $this->belongsTo(HorarioPersonal::class,'horario_personal_id','horario_personal_id'); }
    public function justificaciones(){ return $this->hasMany(Justificacion::class,'asistencia_personal_id','asistencia_personal_id'); }

    public function getIncidenciasResumenAttribute(): string
    {
        $incidencias=[];

        if($this->estado_asistencia==='FALTA'){
            $incidencias[]='FALTA';
        }

        if((int)$this->minutos_tardanza>0){
            $incidencias[]='TARDANZA '.(int)$this->minutos_tardanza.' min';
        }

        if((int)$this->minutos_salida_anticipada>0){
            $incidencias[]='SALIDA ANTICIPADA '.(int)$this->minutos_salida_anticipada.' min';
        }

        if($this->fecha_hora_entrada && !$this->fecha_hora_salida && $this->bloqueYaFinalizo()){
            $incidencias[]='SALIDA NO REGISTRADA';
        }

        if($this->estado_asistencia==='PERMISO'){
            $incidencias[]='PERMISO';
        }

        return $incidencias ? implode(' · ',$incidencias) : 'SIN INCIDENCIAS';
    }

    private function bloqueYaFinalizo(): bool
    {
        if(!$this->fecha || !$this->hora_salida_programada){
            return false;
        }

        try{
            $fin=Carbon::parse($this->fecha->format('Y-m-d').' '.$this->hora_salida_programada,config('app.timezone'));
            return now()->gte($fin);
        }catch(\Throwable){
            return false;
        }
    }
}
