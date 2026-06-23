<?php

use App\Models\User;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('carga el listado de cuentas', function () {
    $this->get('/admin/accounts')->assertOk();
});

it('carga el listado de reglas', function () {
    $this->get('/admin/booking-rules')->assertOk();
});

it('carga el listado de logs', function () {
    $this->get('/admin/booking-logs')->assertOk();
});

it('muestra los botones de ejecución manual en logs', function () {
    $this->get('/admin/booking-logs')
        ->assertSee('Ejecutar reservas ahora')
        ->assertSee('Simular');
});

it('carga el dashboard con los tres widgets', function () {
    $account = \App\Models\Account::create(['label' => 'Yo', 'email' => 'a@b.com', 'password' => 'pw']);
    $account->rules()->create(['weekdays' => [1, 3], 'time' => '18:00', 'class_name' => 'CrossFit']);

    $this->get('/admin')
        ->assertOk()
        ->assertSee('Próximas reservas')
        ->assertSee('Reservas recientes')
        ->assertSee('Reglas activas')
        ->assertSee('CrossFit');   // renderiza la fila -> cubre el formato de la columna "Días"
});
