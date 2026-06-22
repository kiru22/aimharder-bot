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
