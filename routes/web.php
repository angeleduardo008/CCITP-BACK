<?php
use Illuminate\Support\Facades\Route;
Route::get('/', fn()=>response()->json(['name'=>'Registro Asistencia Institucional API','status'=>'online','version'=>'2.1']));
