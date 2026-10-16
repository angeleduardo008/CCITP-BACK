<?php
use App\Http\Controllers\AlumnoController;
use App\Http\Controllers\AsistenciaPersonalController;
use App\Http\Controllers\AuditoriaController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CargoController;
use App\Http\Controllers\CatalogoController;
use App\Http\Controllers\CobranzaController;
use App\Http\Controllers\HorarioPersonalController;
use App\Http\Controllers\InstitucionController;
use App\Http\Controllers\JustificacionController;
use App\Http\Controllers\PersonalController;
use App\Http\Controllers\PlanillaController;
use App\Http\Controllers\RemuneracionController;
use App\Http\Controllers\ReporteController;
use App\Http\Controllers\UsuarioController;
use Illuminate\Support\Facades\Route;

Route::post('/auth/login',[AuthController::class,'login'])->middleware('throttle:10,1');
Route::get('/public/servidor/estado',[AsistenciaPersonalController::class,'estadoServidor'])->middleware('throttle:120,1');
Route::get('/public/personal/buscar',[AsistenciaPersonalController::class,'buscarPersonal'])->middleware('throttle:60,1');
Route::get('/public/personal/{personal}/horarios-hoy',[AsistenciaPersonalController::class,'horariosHoy'])->middleware('throttle:60,1');
Route::post('/public/asistencias/marcar',[AsistenciaPersonalController::class,'marcar'])->middleware('throttle:30,1');

Route::middleware('jwt')->group(function(){
    Route::get('/auth/me',[AuthController::class,'me']);
    Route::post('/auth/logout',[AuthController::class,'logout']);

    Route::get('/asistencias',[AsistenciaPersonalController::class,'index']);
    Route::get('/asistencias/stats',[AsistenciaPersonalController::class,'stats']);

    Route::get('/justificaciones',[JustificacionController::class,'index']);
    Route::get('/justificaciones/candidatas',[JustificacionController::class,'candidatas']);
    Route::post('/justificaciones',[JustificacionController::class,'store']);

    Route::get('/planillas',[PlanillaController::class,'index']);

    Route::middleware('role:ADMINISTRADOR')->group(function(){
        Route::post('/asistencias/procesar',[AsistenciaPersonalController::class,'procesarIncidencias']);
        Route::apiResource('instituciones',InstitucionController::class)->parameters(['instituciones'=>'item']);
        Route::apiResource('cargos',CargoController::class)->parameters(['cargos'=>'item']);
        Route::apiResource('alumnos',AlumnoController::class)->parameters(['alumnos'=>'alumno']);

        Route::get('/personal',[PersonalController::class,'index']);
        Route::post('/personal',[PersonalController::class,'store']);
        Route::get('/personal/{personal}',[PersonalController::class,'show']);
        Route::put('/personal/{personal}',[PersonalController::class,'update']);
        Route::patch('/personal/{personal}/cesar',[PersonalController::class,'cesar']);

        Route::post('/horarios/semana',[HorarioPersonalController::class,'storeWeekly']);
        Route::apiResource('horarios',HorarioPersonalController::class)->parameters(['horarios'=>'item']);

        Route::patch('/justificaciones/{justificacion}/revisar',[JustificacionController::class,'revisar']);

        Route::get('/remuneraciones',[RemuneracionController::class,'index']);
        Route::post('/remuneraciones',[RemuneracionController::class,'store']);

        Route::post('/planillas/generar',[PlanillaController::class,'generar']);
        Route::patch('/planillas/{planilla}/estado',[PlanillaController::class,'cambiarEstado']);
        Route::post('/planillas/{planilla}/descuentos',[PlanillaController::class,'agregarDescuento']);
        Route::post('/planillas/{planilla}/pagos',[PlanillaController::class,'registrarPago']);
        Route::get('/planillas/{planilla}/pagos',[PlanillaController::class,'pagos']);

        Route::get('/cobranza',[CobranzaController::class,'index']);
        Route::get('/cobranza/stats',[CobranzaController::class,'stats']);
        Route::post('/cobranza/{cobranza}/pago',[CobranzaController::class,'registrarPago']);
        Route::get('/cobranza/{cobranza}/pagos',[CobranzaController::class,'pagos']);
        Route::apiResource('cobranza',CobranzaController::class)->parameters(['cobranza'=>'cobranza'])->except(['index']);

        Route::apiResource('usuarios',UsuarioController::class)->parameters(['usuarios'=>'usuario']);
        Route::get('/auditoria',[AuditoriaController::class,'index']);

        Route::get('/reportes/resumen',[ReporteController::class,'resumen']);
        Route::get('/reportes/{tipo}',[ReporteController::class,'datos'])->whereIn('tipo',['asistencias','planillas','cobranza']);
        Route::get('/reportes/{tipo}/csv',[ReporteController::class,'csv'])->whereIn('tipo',['asistencias','planillas','cobranza']);

        Route::get('/catalogos/roles',[CatalogoController::class,'roles']);
        Route::get('/catalogos/tipos-pago',[CatalogoController::class,'tiposPago']);
        Route::get('/catalogos/personal',[CatalogoController::class,'personal']);
        Route::get('/catalogos/alumnos',[CatalogoController::class,'alumnos']);
    });
});
