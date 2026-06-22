<?php

use App\Models\Account;
use App\Models\BookingRule;

it('cifra la contraseña y la descifra al leer', function () {
    $a = Account::create([
        'label' => 'Yo', 'email' => 'test@example.com', 'password' => 'secreta123',
    ]);
    expect($a->password)->toBe('secreta123');
    $raw = \DB::table('accounts')->where('id', $a->id)->value('password');
    expect($raw)->not->toBe('secreta123'); // cifrada en BD
});

it('autogenera un fingerprint estable de 50 chars', function () {
    $a = Account::create(['label' => 'Yo', 'email' => 'test@example.com', 'password' => 'x']);
    expect(strlen($a->fingerprint))->toBe(50)
        ->and($a->fingerprint)->toBe(substr(hash('sha256', 'aimharder-bot-test@example.com'), 0, 50));
});

it('castea weekdays a array y enlaza con la cuenta', function () {
    $a = Account::create(['label' => 'Yo', 'email' => 'test@example.com', 'password' => 'x']);
    $r = $a->rules()->create(['weekdays' => [1, 2, 3], 'time' => '18:00', 'class_name' => 'CrossFit']);
    expect($r->fresh()->weekdays)->toBe([1, 2, 3])
        ->and($r->account->id)->toBe($a->id);
});
