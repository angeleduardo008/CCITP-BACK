<?php
namespace App\Http\Controllers;

use App\Models\Auditoria;
use Illuminate\Http\Request;

class AuditoriaController extends Controller
{
    public function index(Request $r)
    {
        $q=Auditoria::with('usuario:usuario_id,nombre_usuario,correo');
        if($r->filled('accion')) $q->where('accion',$r->accion);
        if($r->filled('tabla')) $q->where('tabla_afectada',$r->tabla);
        if($r->filled('desde')) $q->whereDate('fecha_hora','>=',$r->desde);
        if($r->filled('hasta')) $q->whereDate('fecha_hora','<=',$r->hasta);
        if($r->filled('q')){ $t='%'.$r->q.'%'; $q->where(fn($x)=>$x->where('descripcion','like',$t)->orWhere('registro_id','like',$t)->orWhere('tabla_afectada','like',$t)); }
        return $q->orderByDesc('auditoria_id')->paginate((int)$r->get('per_page',100));
    }
}
