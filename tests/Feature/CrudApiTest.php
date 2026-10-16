<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CrudApiTest extends TestCase
{
    use RefreshDatabase;

    // Adapta: API, tabla, campos y datos requeridos.
    private string $ruta = '/api/productos';
    private string $tabla = 'productos';

    private function datos(): array
    {
        return [
            'nombre' => 'Mouse de prueba',
            'precio' => 45,
        ];
    }

    public function test_puedo_listar_registros(): void
    {
        $this->getJson($this->ruta)
            ->assertOk();
    }

    public function test_puedo_crear_registro(): void
    {
        $this->postJson($this->ruta, $this->datos())
            ->assertStatus(201);

        $this->assertDatabaseHas($this->tabla, [
            'nombre' => 'Mouse de prueba',
        ]);
    }

    public function test_rechaza_nombre_vacio(): void
    {
        $datos = $this->datos();
        $datos['nombre'] = '';

        $this->postJson($this->ruta, $datos)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['nombre']);
    }

    public function test_rechaza_precio_negativo(): void
    {
        $datos = $this->datos();
        $datos['precio'] = -10;

        $this->postJson($this->ruta, $datos)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['precio']);
    }

    public function test_puedo_actualizar_registro(): void
    {
        $id = DB::table($this->tabla)->insertGetId([
            'nombre' => 'Producto inicial',
            'precio' => 20,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $datos = [
            'nombre' => 'Producto editado',
            'precio' => 25,
        ];

        $this->putJson("{$this->ruta}/{$id}", $datos)
            ->assertSuccessful();

        $this->assertDatabaseHas($this->tabla, [
            'id' => $id,
            'nombre' => 'Producto editado',
        ]);
    }

    public function test_puedo_eliminar_registro(): void
    {
        $id = DB::table($this->tabla)->insertGetId([
            'nombre' => 'Producto temporal',
            'precio' => 10,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->deleteJson("{$this->ruta}/{$id}")
            ->assertSuccessful();

        // Solo para borrado físico; cambia si hay soft delete.
        $this->assertDatabaseMissing($this->tabla, [
            'id' => $id,
        ]);
    }
}