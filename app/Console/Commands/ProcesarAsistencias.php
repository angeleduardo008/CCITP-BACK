<?php

namespace App\Console\Commands;

use App\Services\AsistenciaAutomaticaService;
use App\Support\InstitutionContext;
use Carbon\Carbon;
use Illuminate\Console\Command;

class ProcesarAsistencias extends Command
{
    protected $signature = 'app:asistencias-procesar
        {--fecha= : Fecha AAAA-MM-DD. Si se omite, procesa ayer y hoy}
        {--institucion= : ccitp, divertimundo o pitagoras}';

    protected $description = 'Genera faltas automáticas para bloques finalizados sin entrada y normaliza incidencias de asistencia.';

    public function handle(AsistenciaAutomaticaService $service): int
    {
        $slug = null;
        if ($this->option('institucion')) {
            $slug = InstitutionContext::normalizeSlug((string) $this->option('institucion'));
            if (!$slug) {
                $this->error('Institución inválida. Use: ccitp, divertimundo o pitagoras.');
                return self::FAILURE;
            }
        }

        if ($this->option('fecha')) {
            try {
                $fecha = Carbon::createFromFormat('Y-m-d', (string) $this->option('fecha'))->startOfDay();
            } catch (\Throwable) {
                $this->error('La fecha debe tener formato AAAA-MM-DD.');
                return self::FAILURE;
            }

            $resultado = $service->procesarFecha($fecha, $slug);
            $this->mostrar($resultado);
            return self::SUCCESS;
        }

        $resultado = $service->procesarAyerYHoy($slug);
        $this->info('Procesando AYER');
        $this->mostrar($resultado['ayer']);
        $this->newLine();
        $this->info('Procesando HOY');
        $this->mostrar($resultado['hoy']);

        return self::SUCCESS;
    }

    private function mostrar(array $r): void
    {
        $this->table(
            ['Fecha','Institución','Horarios','En curso','Nuevas filas','Faltas','Sin salida','Normalizados','Permisos'],
            [[
                $r['fecha'],
                $r['institucion'] ?: 'TODAS',
                $r['horarios_evaluados'],
                $r['bloques_en_curso'],
                $r['asistencias_creadas'],
                $r['faltas_generadas'],
                $r['salidas_no_registradas'],
                $r['estados_normalizados'],
                $r['permisos_respetados'],
            ]]
        );
    }
}
