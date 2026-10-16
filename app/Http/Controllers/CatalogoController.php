<?php
namespace App\Http\Controllers;

use App\Models\Alumno;
use App\Models\Personal;
use App\Models\Role;
use App\Models\TipoPago;
use App\Support\InstitutionContext;
use Illuminate\Http\Request;

class CatalogoController extends Controller
{
    public function roles()
    {
        return Role::where('estado',1)
            ->orderBy('nombre')
            ->get(['rol_id','nombre']);
    }

    public function tiposPago()
    {
        return TipoPago::where('estado',1)
            ->orderBy('nombre')
            ->get(['tipo_pago_id','nombre']);
    }

    public function personal(Request $request)
    {
        $q=Personal::with(['institucion','cargo'])
            ->where('estado',1);

        InstitutionContext::applyRelation(
            $q,
            'institucion',
            InstitutionContext::fromRequest($request)
        );

        return $q->orderBy('apellidos')
            ->orderBy('nombres')
            ->get()
            ->map(fn($p)=>[
                'personal_id'=>$p->personal_id,
                'nombre_completo'=>trim($p->apellidos.', '.$p->nombres),
                'numero_documento'=>$p->numero_documento,
                'institucion'=>$p->institucion?->nombre,
                'cargo'=>$p->cargo?->nombre,
            ]);
    }

    public function alumnos(Request $request)
    {
        $q=Alumno::with('institucion')
            ->where('estado',1);

        InstitutionContext::applyRelation(
            $q,
            'institucion',
            InstitutionContext::fromRequest($request)
        );

        return $q->orderBy('apellidos')
            ->orderBy('nombres')
            ->get()
            ->map(fn($a)=>[
                'alumno_id'=>$a->alumno_id,
                'nombre_completo'=>trim($a->apellidos.', '.$a->nombres),
                'codigo_alumno'=>$a->codigo_alumno,
                'institucion'=>$a->institucion?->nombre,
            ]);
    }
}
