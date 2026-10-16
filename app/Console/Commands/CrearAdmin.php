<?php
namespace App\Console\Commands;
use App\Models\Role;
use App\Models\Usuario;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
class CrearAdmin extends Command
{
    protected $signature='app:crear-admin {usuario=admin} {correo=admin@ccitp.local}';
    protected $description='Crea o actualiza el usuario administrador inicial';
    public function handle(): int
    {
        $role=Role::firstOrCreate(['nombre'=>'ADMINISTRADOR'],['descripcion'=>'Acceso completo a la administración del sistema','estado'=>1]);
        Role::firstOrCreate(['nombre'=>'PERSONAL'],['descripcion'=>'Trabajador con acceso limitado','estado'=>1]);
        Role::firstOrCreate(['nombre'=>'CONSULTA'],['descripcion'=>'Usuario de solo consulta','estado'=>1]);
        $password=$this->secret('Contraseña del administrador (mínimo 8 caracteres)');
        if(strlen((string)$password)<8){ $this->error('La contraseña debe tener al menos 8 caracteres.'); return self::FAILURE; }
        $u=Usuario::updateOrCreate(['nombre_usuario'=>$this->argument('usuario')],[
            'correo'=>$this->argument('correo'),'rol_id'=>$role->rol_id,'password_hash'=>Hash::make($password),'estado'=>1,'intentos_fallidos'=>0,'bloqueado_hasta'=>null
        ]);
        $this->info('Administrador listo: '.$u->nombre_usuario); return self::SUCCESS;
    }
}
