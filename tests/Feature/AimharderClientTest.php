<?php
use App\Services\Aimharder\AimharderClient;
use App\Services\Aimharder\AuthException;
use Illuminate\Support\Facades\Http;

it('login OK cuando llega la cookie amhrdrauth', function () {
    Http::fake([
        'login.aimharder.com/api/login' => Http::response('{}', 200, [
            'Set-Cookie' => 'amhrdrauth=442015%7C123%7Cabc; Domain=aimharder.com; Path=/; HttpOnly',
        ]),
    ]);

    $c = new AimharderClient('hybridboxgrau', 8244);
    $c->login('a@b.com', 'pw', 'fp');   // no lanza

    Http::assertSent(fn ($r) => str_contains($r->url(), 'login.aimharder.com/api/login')
        && $r['username'] === 'a@b.com' && $r['password'] === 'pw'
        && $r['fingerprint'] === 'fp' && $r['iniframe'] === 0);
});

it('login falla (AuthException) sin amhrdrauth', function () {
    Http::fake(['login.aimharder.com/api/login' => Http::response('{}', 200)]);
    $c = new AimharderClient('hybridboxgrau', 8244);
    $c->login('a@b.com', 'bad', 'fp');
})->throws(AuthException::class);

it('listClasses devuelve el JSON de bookings con day y box', function () {
    Http::fake([
        'login.aimharder.com/api/login' => Http::response('{}', 200, ['Set-Cookie' => 'amhrdrauth=x; Domain=aimharder.com']),
        'hybridboxgrau.aimharder.com/api/bookings*' => Http::response(['bookings' => [['id' => 9]]], 200),
    ]);
    $c = new AimharderClient('hybridboxgrau', 8244);
    $c->login('a@b.com', 'pw', 'fp');
    $out = $c->listClasses('20260622');
    expect($out['bookings'][0]['id'])->toBe(9);
    Http::assertSent(fn ($r) => str_contains($r->url(), 'day=20260622') && str_contains($r->url(), 'box=8244'));
});

it('book envía form id/day/insist/familyId y devuelve el JSON', function () {
    Http::fake([
        'login.aimharder.com/api/login' => Http::response('{}', 200, ['Set-Cookie' => 'amhrdrauth=x; Domain=aimharder.com']),
        'hybridboxgrau.aimharder.com/api/book' => Http::response(['bookState' => 0, 'id' => '555'], 200),
    ]);
    $c = new AimharderClient('hybridboxgrau', 8244);
    $c->login('a@b.com', 'pw', 'fp');
    $out = $c->book('222', '20260622', false);
    expect($out['bookState'])->toBe(0);
    Http::assertSent(fn ($r) => str_contains($r->url(), '/api/book')
        && $r['id'] === '222' && $r['day'] === '20260622' && $r['insist'] === 0 && $r['familyId'] === '');
});
