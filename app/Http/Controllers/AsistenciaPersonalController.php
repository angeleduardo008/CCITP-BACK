<?php
namespace App\Http\Controllers;

use App\Models\AsistenciaPersonal;
use App\Models\HorarioPersonal;
use App\Models\Personal;
use App\Support\InstitutionContext;
use App\Services\AsistenciaAutomaticaService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class AsistenciaPersonalController extends Controller
{
    public function estadoServidor()
    {
        $now=now();
        return response()->json([
            'fecha'=>$now->toDateString(),
            'hora'=>$now->format('H:i:s'),
            'fecha_hora'=>$now->toDateTimeString(),
            'zona_horaria'=>config('app.timezone'),
        ]);
    }

    public function buscarPersonal(Request $request)
    {
        $term=trim((string)$request->get('q',''));
        if(strlen($term)<2) return response()->json([]);

        $slug=InstitutionContext::fromRequest($request);

        $q=Personal::with(['institucion','cargo'])
            ->where('estado',1)
            ->where(function($q) use($term){
                $like='%'.$term.'%';
                $q->where('numero_documento','like',$like)
                  ->orWhere('codigo_personal','like',$like)
                  ->orWhere('nombres','like',$like)
                  ->orWhere('apellidos','like',$like);
            });

        InstitutionContext::applyRelation($q,'institucion',$slug);

        return $q->limit(10)->get();
    }

    public function horariosHoy(Request $request, Personal $personal)
    {
        $slug=InstitutionContext::fromRequest($request);
        $personal->loadMissing('institucion');

        if(
            !$personal->estado ||
            ($slug && !InstitutionContext::matchesName($personal->institucion?->nombre,$slug))
        ){
            abort(404,'Personal no disponible para la institución seleccionada.');
        }

        $now=now();

        return HorarioPersonal::where('personal_id',$personal->personal_id)
            ->where('estado',1)
            ->where('dia_semana',$now->isoWeekday())
            ->where(fn($q)=>
                $q->whereNull('fecha_desde')
                  ->orWhereDate('fecha_desde','<=',$now->toDateString())
            )
            ->where(fn($q)=>
                $q->whereNull('fecha_hasta')
                  ->orWhereDate('fecha_hasta','>=',$now->toDateString())
            )
            ->orderBy('bloque')
            ->get();
    }

    public function marcar(Request $request)
    {
        $data=$request->validate([
            'personal_id'=>'required|integer|exists:personal,personal_id',
            'horario_personal_id'=>'required|integer|exists:horarios_personal,horario_personal_id',
            'tipo'=>'required|in:ENTRADA,SALIDA',
            'institucion'=>['required','string',Rule::in(InstitutionContext::slugs())],
            'foto'=>'required|image|mimes:jpg,jpeg,png|max:5120|dimensions:min_width=480,min_height=360',
            'latitud'=>'required|numeric|between:-90,90',
            'longitud'=>'required|numeric|between:-180,180',
            'precision_gps'=>'nullable|numeric|min:0|max:10000',
            'rostro_confianza'=>'required|numeric|min:0.45|max:1',
        ],[
            'latitud.required'=>'La ubicación GPS es obligatoria para registrar asistencia.',
            'longitud.required'=>'La ubicación GPS es obligatoria para registrar asistencia.',
            'rostro_confianza.min'=>'La fotografía no tiene un rostro suficientemente claro.',
            'foto.dimensions'=>'La fotografía debe tener al menos 480×360 píxeles.',
        ]);

        $slug=InstitutionContext::normalizeSlug($data['institucion']);

        $personal=Personal::with('institucion')
            ->where('personal_id',$data['personal_id'])
            ->where('estado',1)
            ->firstOrFail();

        if(!InstitutionContext::matchesName($personal->institucion?->nombre,$slug)){
            abort(403,'El personal no pertenece a la institución seleccionada.');
        }

        $horario=HorarioPersonal::where('horario_personal_id',$data['horario_personal_id'])
            ->where('personal_id',$personal->personal_id)
            ->where('estado',1)
            ->firstOrFail();

        $now=now();
        $today=$now->toDateString();

        if((int)$horario->dia_semana !== $now->isoWeekday()){
            return response()->json(['message'=>'El horario seleccionado no corresponde al día de hoy'],422);
        }
        if($horario->fecha_desde && Carbon::parse($horario->fecha_desde)->toDateString() > $today){
            return response()->json(['message'=>'El horario seleccionado todavía no está vigente'],422);
        }
        if($horario->fecha_hasta && Carbon::parse($horario->fecha_hasta)->toDateString() < $today){
            return response()->json(['message'=>'El horario seleccionado ya no está vigente'],422);
        }

        $asistencia=AsistenciaPersonal::firstOrCreate([
            'personal_id'=>$personal->personal_id,
            'horario_personal_id'=>$horario->horario_personal_id,
            'fecha'=>$today,
        ],[
            'hora_entrada_programada'=>$horario->hora_entrada,
            'hora_salida_programada'=>$horario->hora_salida,
            'estado_asistencia'=>'PENDIENTE'
        ]);

        if($data['tipo']==='ENTRADA' && $asistencia->fecha_hora_entrada){
            return response()->json(['message'=>'La entrada de este bloque ya fue registrada'],409);
        }
        if($data['tipo']==='SALIDA'){
            if(!$asistencia->fecha_hora_entrada){
                return response()->json(['message'=>'Debe registrar primero la entrada'],422);
            }
            if($asistencia->fecha_hora_salida){
                return response()->json(['message'=>'La salida de este bloque ya fue registrada'],409);
            }
        }

        // Solo guardamos la evidencia después de superar todas las validaciones.
        $path=$request->file('foto')->store('asistencias','public');

        try {
            $qualityNote=sprintf(
                'GPS precisión: %sm; rostro detectado: %.2f',
                isset($data['precision_gps']) ? round((float)$data['precision_gps'],1) : 'n/d',
                (float)$data['rostro_confianza']
            );

            if($data['tipo']==='ENTRADA'){
                $programada=Carbon::parse($today.' '.$horario->hora_entrada)
                    ->addMinutes((int)$horario->tolerancia_minutos);
                $min=max(0,$programada->diffInMinutes($now,false));

                $asistencia->update([
                    'fecha_hora_entrada'=>$now,
                    'minutos_tardanza'=>$min,
                    'estado_asistencia'=>$min>0?'TARDANZA':'PRESENTE',
                    'foto_entrada'=>$path,
                    'latitud_entrada'=>$data['latitud'],
                    'longitud_entrada'=>$data['longitud'],
                    'observacion'=>$qualityNote,
                ]);
            } else {
                $salida=Carbon::parse($today.' '.$horario->hora_salida);
                $min=max(0,$now->diffInMinutes($salida,false));

                $asistencia->update([
                    'fecha_hora_salida'=>$now,
                    'minutos_salida_anticipada'=>$min,
                    'foto_salida'=>$path,
                    'latitud_salida'=>$data['latitud'],
                    'longitud_salida'=>$data['longitud'],
                    'observacion'=>trim(($asistencia->observacion ? $asistencia->observacion.' | ' : '').$qualityNote),
                ]);
            }
        } catch (\Throwable $e) {
            Storage::disk('public')->delete($path);
            throw $e;
        }

        return response()->json([
            'message'=>'Marcación registrada',
            'hora_servidor'=>$now->toDateTimeString(),
            'institucion'=>$slug,
            'asistencia'=>$asistencia->fresh()
        ]);
    }

    public function procesarIncidencias(Request $request, AsistenciaAutomaticaService $service)
    {
        $data=$request->validate([
            'fecha'=>'nullable|date|before_or_equal:today',
            'institucion'=>'nullable|string',
        ]);

        $slug=InstitutionContext::fromRequest($request);
        if($request->user()?->rol?->nombre!=='ADMINISTRADOR'){
            $request->user()?->loadMissing('personal.institucion');
            $propia=InstitutionContext::slugForName($request->user()?->personal?->institucion?->nombre);
            if(!$propia) abort(403,'El usuario no tiene una institución válida asignada.');
            $slug=$propia;
        }

        if(!empty($data['fecha'])){
            $resultado=$service->procesarFecha(Carbon::parse($data['fecha']),$slug);
        } else {
            $resultado=$service->procesarAyerYHoy($slug);
        }

        return response()->json([
            'message'=>'Incidencias de asistencia actualizadas.',
            'resultado'=>$resultado,
        ]);
    }

    public function index(Request $request)
    {
        $q=AsistenciaPersonal::query()->with(['personal.institucion','horario']);
        if($request->filled('fecha')) $q->whereDate('fecha',$request->fecha);
        if($request->filled('personal_id')) $q->where('personal_id',$request->personal_id);
        if($request->filled('estado')) $q->where('estado_asistencia',$request->estado);

        InstitutionContext::applyRelation($q,'personal.institucion',InstitutionContext::fromRequest($request));
        if($request->user()?->rol?->nombre!=='ADMINISTRADOR') $q->where('personal_id',$request->user()?->personal_id);

        return $q->orderByDesc('fecha')->orderByDesc('asistencia_personal_id')
            ->paginate((int)$request->get('per_page',30));
    }

    public function stats(Request $request)
    {
        $hoy=now()->toDateString();
        $base=AsistenciaPersonal::whereDate('fecha',$hoy);
        InstitutionContext::applyRelation($base,'personal.institucion',InstitutionContext::fromRequest($request));
        if($request->user()?->rol?->nombre!=='ADMINISTRADOR') $base->where('personal_id',$request->user()?->personal_id);

        $sinSalida=(clone $base)
            ->whereNotNull('fecha_hora_entrada')
            ->whereNull('fecha_hora_salida')
            ->where(function($q) use($hoy){
                $q->whereDate('fecha','<',$hoy)
                  ->orWhere(function($x) use($hoy){
                      $x->whereDate('fecha',$hoy)
                        ->where('hora_salida_programada','<=',now()->format('H:i:s'));
                  });
            })
            ->count();

        return response()->json([
            'fecha'=>$hoy,
            'marcaciones'=>(clone $base)->whereNotNull('fecha_hora_entrada')->count(),
            'presentes'=>(clone $base)->where('estado_asistencia','PRESENTE')->count(),
            'tardanzas'=>(clone $base)->where('estado_asistencia','TARDANZA')->count(),
            'faltas'=>(clone $base)->where('estado_asistencia','FALTA')->count(),
            'salidas_anticipadas'=>(clone $base)->where('minutos_salida_anticipada','>',0)->count(),
            'sin_salida'=>$sinSalida,
        ]);
    }
}
