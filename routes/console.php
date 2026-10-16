<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('about-project', function(){
    $this->info('Registro Asistencia Institucional - Laravel 12');
});

// FASE 6: mientras exista un scheduler activo, revisa ayer y hoy cada 5 minutos.
// Procesar ayer evita perder faltas si la PC/servidor estuvo apagado al cierre del día.
Schedule::command('app:asistencias-procesar')
    ->everyFiveMinutes()
    ->withoutOverlapping();
