<?php
namespace App\Console\Commands;

use App\Models\Cargo;
use App\Models\HorarioPersonal;
use App\Models\Institucion;
use App\Models\Personal;
use App\Models\RemuneracionPersonal;
use App\Models\TipoPago;
use App\Models\Usuario;
use App\Support\InstitutionContext;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class Fase3Personal extends Command
{
    protected $signature = 'app:fase3-personal
        {--aplicar : Aplica los cambios; sin esta opción solo muestra diagnóstico}
        {--fecha=2026-08-20 : Fecha efectiva para ceses/ingresos/remuneración (AAAA-MM-DD)}
        {--cargo-adriana= : ID de cargo para crear/activar a Adriana Ramirez Ramirez}
        {--cargo-estefany= : ID de cargo para crear/activar a Estefany Madai Cachin Tafur}
        {--dias-adriana= : Días para Adriana, ej. 1,2,3,4,5. Si se omite, no se crean horarios}
        {--tolerancia=0 : Minutos de tolerancia para horarios creados por este comando}';

    protected $description = 'Diagnostica y aplica la actualización operativa de personal de la Fase 3 sin borrar históricos.';

    private array $ceses = [
        'ccitp' => ['Gabriela','Lastenia','Erika','Julio','Charles','Judit','Vicente','Hugo Lomas'],
        'divertimundo' => ['Ana Flavia','Miluska'],
    ];

    public function handle(): int
    {
        $fecha=(string)$this->option('fecha');
        try { Carbon::createFromFormat('Y-m-d',$fecha); }
        catch(\Throwable $e){ $this->error('La fecha debe tener formato AAAA-MM-DD.'); return self::FAILURE; }

        $apply=(bool)$this->option('aplicar');
        $this->newLine();
        $this->info('FASE 3 · Actualización de personal, horarios y remuneración');
        $this->line('Modo: '.($apply ? '<fg=yellow>APLICAR CAMBIOS</>' : '<fg=cyan>DIAGNÓSTICO / SIN CAMBIOS</>'));
        $this->line('Fecha efectiva: '.$fecha);
        $this->newLine();

        $this->showInstitutions();
        $this->showCargos();
        $this->showPaymentTypes();

        foreach($this->ceses as $slug=>$names){
            $this->newLine();
            $this->info('Revisión de ceses · '.strtoupper($slug));
            foreach($names as $name){
                $result=$this->findUniquePersonal($slug,$name);
                $this->printMatch($name,$result);
                if($apply && $result instanceof Personal){
                    $this->cesar($result,$fecha);
                }
            }
        }

        $this->newLine();
        $this->info('Nuevos ingresos');
        $adriana=$this->ensureNewStaff(
            'ccitp','Adriana','Ramirez Ramirez',$this->option('cargo-adriana'),$fecha,$apply
        );
        $estefany=$this->ensureNewStaff(
            'divertimundo','Estefany Madai','Cachin Tafur',$this->option('cargo-estefany'),$fecha,$apply
        );

        if($apply && $adriana){
            $days=$this->parseDays((string)$this->option('dias-adriana'));
            if($days){
                $this->replaceWeeklySchedule($adriana,$days,[
                    ['bloque'=>1,'hora_entrada'=>'07:00','hora_salida'=>'12:30'],
                ],$fecha,(int)$this->option('tolerancia'));
                $this->info('  ✓ Horario de Adriana: 07:00–12:30 en días '.implode(',',$days));
            } else {
                $this->warn('  ! Adriana quedó sin horario automático porque no se indicó --dias-adriana. Así evitamos asumir días de trabajo.');
            }
        }

        $this->newLine();
        $this->info('Actualización de Leticia · Divertimundo');
        $leticia=$this->findUniquePersonal('divertimundo','Leticia');
        $this->printMatch('Leticia',$leticia);
        if($apply && $leticia instanceof Personal){
            $this->updateLeticia($leticia,$fecha);
        }

        $this->newLine();
        $this->warn('CCITP: NO se cambió en bloque a todo el personal a 07:00–13:00.');
        $this->line('Motivo: la planilla CCITP contiene docentes por hora; aplicar ese horario a todos podría dañar jornadas reales.');
        $this->line('Use la nueva pantalla Horarios semanales para asignar 07:00–13:00 solamente a quienes corresponda.');

        $this->newLine();
        if(!$apply){
            $this->comment('No se modificó la base de datos. Cuando revise las coincidencias, puede ejecutar nuevamente con --aplicar.');
        } else {
            $this->info('Proceso Fase 3 finalizado. Revise Personal, Horarios y Remuneraciones desde Angular.');
        }

        return self::SUCCESS;
    }

    private function showInstitutions(): void
    {
        $rows=Institucion::orderBy('institucion_id')->get(['institucion_id','nombre','estado'])
            ->map(fn($i)=>[$i->institucion_id,$i->nombre,$i->estado?'ACTIVA':'INACTIVA'])->all();
        $this->table(['ID','Institución','Estado'],$rows);
    }

    private function showCargos(): void
    {
        $rows=Cargo::where('estado',1)->orderBy('cargo_id')->get(['cargo_id','nombre'])
            ->map(fn($c)=>[$c->cargo_id,$c->nombre])->all();
        $this->info('Cargos activos (use estos IDs para los nuevos ingresos):');
        $this->table(['ID','Cargo'],$rows);
    }

    private function showPaymentTypes(): void
    {
        $rows=TipoPago::where('estado',1)->orderBy('tipo_pago_id')->get(['tipo_pago_id','nombre'])
            ->map(fn($t)=>[$t->tipo_pago_id,$t->nombre])->all();
        $this->info('Tipos de pago activos:');
        $this->table(['ID','Tipo'],$rows);
    }

    private function findUniquePersonal(string $slug,string $needle): Personal|array|null
    {
        $ids=InstitutionContext::institutionIds($slug);
        if(!$ids) return null;
        $tokens=array_values(array_filter(explode(' ',$this->norm($needle))));
        $matches=Personal::with(['institucion','cargo'])
            ->whereIn('institucion_id',$ids)
            ->get()
            ->filter(function(Personal $p) use($tokens){
                $full=$this->norm(trim($p->nombres.' '.$p->apellidos));
                foreach($tokens as $token){ if(!str_contains($full,$token)) return false; }
                return true;
            })->values();
        if($matches->count()===1) return $matches->first();
        if($matches->count()>1) return $matches->all();
        return null;
    }

    private function printMatch(string $label, Personal|array|null $result): void
    {
        if($result instanceof Personal){
            $this->line(sprintf(
                '  ✓ %-24s → #%d %s %s · %s · %s',
                $label,$result->personal_id,$result->nombres,$result->apellidos,
                $result->cargo?->nombre ?? 'sin cargo',$result->estado?'ACTIVO':'CESADO'
            ));
            return;
        }
        if(is_array($result)){
            $this->warn('  ! '.$label.' → coincidencia ambigua ('.count($result).'). No se tocará automáticamente.');
            foreach($result as $p){ $this->line("      #{$p->personal_id} {$p->nombres} {$p->apellidos}"); }
            return;
        }
        $this->warn('  ! '.$label.' → no encontrado.');
    }

    private function cesar(Personal $personal,string $fecha): void
    {
        if(!$personal->estado){
            $this->line("      ↳ Ya estaba cesado; se conserva historial.");
            return;
        }
        DB::transaction(function() use($personal,$fecha){
            $personal->update(['estado'=>0,'fecha_cese'=>$fecha]);
            HorarioPersonal::where('personal_id',$personal->personal_id)->where('estado',1)->get()->each(function($h) use($fecha){
                $desde=$h->fecha_desde ? (string)$h->fecha_desde : null;
                $h->update(['estado'=>0,'fecha_hasta'=>$desde && $fecha<$desde ? $desde : $fecha]);
            });
            RemuneracionPersonal::where('personal_id',$personal->personal_id)->where('estado',1)->get()->each(function($r) use($fecha){
                $desde=$r->fecha_inicio?->format('Y-m-d');
                $r->update(['estado'=>0,'fecha_fin'=>$desde && $fecha<$desde ? $desde : $fecha]);
            });
            Usuario::where('personal_id',$personal->personal_id)->where('estado',1)->update(['estado'=>0]);
        });
        $this->info("      ↳ Cesado con fecha {$fecha}; horarios/remuneración/acceso cerrados.");
    }

    private function ensureNewStaff(string $slug,string $nombres,string $apellidos,$cargoId,string $fecha,bool $apply): ?Personal
    {
        $search=trim($nombres.' '.$apellidos);
        $found=$this->findUniquePersonal($slug,$search);
        if($found instanceof Personal){
            $this->line("  ✓ {$search} ya existe como #{$found->personal_id} · ".($found->estado?'ACTIVO':'CESADO'));
            if($apply && !$found->estado){
                $found->update(['estado'=>1,'fecha_cese'=>null,'fecha_ingreso'=>$found->fecha_ingreso ?: $fecha]);
                $this->info('      ↳ Reactivado.');
            }
            return $found->fresh();
        }
        if(is_array($found)){
            $this->warn("  ! {$search}: hay coincidencias ambiguas; no se creará.");
            return null;
        }
        $this->warn("  ! {$search}: todavía no existe.");
        if(!$apply) return null;
        if(!$cargoId){
            $this->warn("      ↳ No se creó porque falta el ID de cargo. Use --cargo-".($slug==='ccitp'?'adriana':'estefany')."=ID.");
            return null;
        }
        $cargo=Cargo::where('cargo_id',(int)$cargoId)->where('estado',1)->first();
        if(!$cargo){
            $this->error("      ↳ Cargo #{$cargoId} no existe o está inactivo.");
            return null;
        }
        $ids=InstitutionContext::institutionIds($slug);
        if(count($ids)!==1){
            $this->error("      ↳ No se pudo determinar una única institución para {$slug}.");
            return null;
        }
        $row=Personal::create([
            'institucion_id'=>$ids[0],
            'cargo_id'=>$cargo->cargo_id,
            'nombres'=>$nombres,
            'apellidos'=>$apellidos,
            'fecha_ingreso'=>$fecha,
            'fecha_cese'=>null,
            'estado'=>1,
        ]);
        $this->info("      ↳ Creado como #{$row->personal_id}, cargo {$cargo->nombre}.");
        return $row;
    }

    private function updateLeticia(Personal $leticia,string $fecha): void
    {
        $tipo=TipoPago::where('estado',1)->get()->first(fn($t)=>str_contains($this->norm($t->nombre),'sueldo mensual'))
            ?? TipoPago::where('estado',1)->get()->first(fn($t)=>str_contains($this->norm($t->nombre),'mensual'));
        if(!$tipo){
            $this->warn('      ↳ No se encontró tipo de pago SUELDO_MENSUAL/MENSUAL. No se cambió remuneración.');
        } else {
            $actual=RemuneracionPersonal::where('personal_id',$leticia->personal_id)->where('estado',1)->first();
            if($actual && (float)$actual->monto===1600.0){
                $this->line('      ↳ Sueldo vigente ya es S/ 1,600.00.');
            } else {
                $fin=Carbon::parse($fecha)->subDay()->toDateString();
                RemuneracionPersonal::where('personal_id',$leticia->personal_id)->where('estado',1)->get()->each(function($r) use($fin){
                    $desde=$r->fecha_inicio?->format('Y-m-d');
                    $r->update(['estado'=>0,'fecha_fin'=>$desde && $fin<$desde ? $desde : $fin]);
                });
                RemuneracionPersonal::create([
                    'personal_id'=>$leticia->personal_id,
                    'tipo_pago_id'=>$tipo->tipo_pago_id,
                    'monto'=>1600,
                    'fecha_inicio'=>$fecha,
                    'fecha_fin'=>null,
                    'estado'=>1,
                    'observacion'=>'Actualización Fase 3: sueldo vigente informado S/ 1,600.',
                ]);
                $this->info('      ↳ Nueva remuneración vigente: S/ 1,600.00.');
            }
        }

        $days=HorarioPersonal::where('personal_id',$leticia->personal_id)
            ->where('estado',1)->pluck('dia_semana')->unique()->sort()->values()->all();
        if(!$days){
            $this->warn('      ↳ Leticia no tiene días activos registrados; no se asumieron días. Asigne los días desde Horarios semanales.');
            return;
        }
        $this->replaceWeeklySchedule($leticia,$days,[
            ['bloque'=>1,'hora_entrada'=>'07:00','hora_salida'=>'14:00'],
            ['bloque'=>2,'hora_entrada'=>'14:00','hora_salida'=>'17:30'],
        ],$fecha,(int)$this->option('tolerancia'));
        $this->info('      ↳ Horarios actualizados en los mismos días que ya tenía: 07:00–14:00 y 14:00–17:30.');
    }

    private function replaceWeeklySchedule(Personal $personal,array $days,array $blocks,string $fecha,int $tolerance): void
    {
        DB::transaction(function() use($personal,$days,$blocks,$fecha,$tolerance){
            $fin=Carbon::parse($fecha)->subDay()->toDateString();
            HorarioPersonal::where('personal_id',$personal->personal_id)->whereIn('dia_semana',$days)->where('estado',1)->get()->each(function($h) use($fin){
                $desde=$h->fecha_desde ? (string)$h->fecha_desde : null;
                $h->update(['estado'=>0,'fecha_hasta'=>$desde && $fin<$desde ? $desde : $fin]);
            });
            foreach($days as $day){
                foreach($blocks as $block){
                    HorarioPersonal::create([
                        'personal_id'=>$personal->personal_id,
                        'dia_semana'=>(int)$day,
                        'bloque'=>$block['bloque'],
                        'hora_entrada'=>$block['hora_entrada'],
                        'hora_salida'=>$block['hora_salida'],
                        'tolerancia_minutos'=>$tolerance,
                        'fecha_desde'=>$fecha,
                        'fecha_hasta'=>null,
                        'estado'=>1,
                    ]);
                }
            }
        });
    }

    private function parseDays(string $value): array
    {
        if(trim($value)==='') return [];
        $days=array_values(array_unique(array_filter(array_map('intval',explode(',',$value)),fn($d)=>$d>=1&&$d<=7)));
        sort($days);
        return $days;
    }

    private function norm(string $value): string
    {
        return strtolower(trim(preg_replace('/\s+/',' ',Str::ascii($value))));
    }
}
