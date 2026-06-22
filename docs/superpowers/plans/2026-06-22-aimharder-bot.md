# AimHarder Bot — Plan de Implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** App Laravel + Filament que reserva clases recurrentes en AimHarder cada día a las 06:00 (Europe/Madrid), gestionable desde un panel web multi-cuenta.

**Architecture:** Una app Laravel. La lógica de negocio (matching de clase, cliente HTTP de AimHarder, comando de reserva) está en `app/Services` y `app/Console`. El panel (Filament) da el CRUD de cuentas/reglas y la vista de logs. El scheduler de Laravel dispara `bookings:run` a diario; un cron del VPS ejecuta `schedule:run`.

**Tech Stack:** PHP 8.2+, Laravel 11, Filament 3, SQLite, cliente HTTP de Laravel (Guzzle). Sin librerías HTTP extra.

## Global Constraints

- Subdominio por defecto: `hybridboxgrau`; box_id por defecto: `8244` (configurables por cuenta).
- Login: `POST https://login.aimharder.com/api/login`, cuerpo **JSON** `{username, password, fingerprint, iniframe:0}`. Éxito = cookie `amhrdrauth` presente en `Set-Cookie`.
- `amhrdrauth` se fija a `Domain=aimharder.com` para que valga en el subdominio del box.
- Listar: `GET https://{sub}.aimharder.com/api/bookings?day=YYYYMMDD&familyId=&box={box}&_={ms}`.
- Reservar: `POST https://{sub}.aimharder.com/api/book`, **form** `{id, day, insist:0|1, familyId:''}`.
- Resultado de reserva: éxito = HTTP 200 sin `errorMssg`/`errorMssgLang`. `errorMssgLang==NOPUEDESRESERVAMISMAHORA` = ya reservada.
- `accounts.password` con cast `encrypted`. Secretos fuera de git.
- Zona horaria de operación: `Europe/Madrid`. Reserva la clase **del mismo día**.
- `fingerprint`: estable por cuenta = `substr(hash('sha256','aimharder-bot-'.$email),0,50)`.

---

### Task 1: Scaffold Laravel + SQLite + Filament

**Files:**
- Create: toda la base de Laravel en `~/aimharder-bot/` (preservando `.git`, `.gitignore`, `docs/`)
- Modify: `.env`

**Interfaces:**
- Produces: app Laravel arrancable; panel Filament en `/admin` con un usuario admin.

- [ ] **Step 1: Scaffold Laravel dentro del repo existente**

El repo ya tiene `.git`, `.gitignore` y `docs/`. Crear Laravel en un temporal y mover encima:

```bash
cd ~
composer create-project laravel/laravel aimharder-tmp
# copiar el esqueleto Laravel dentro del repo (sin pisar .git ni docs)
rsync -a --exclude='.git' --exclude='docs' aimharder-tmp/ aimharder-bot/
rm -rf aimharder-tmp
cd ~/aimharder-bot
```

- [ ] **Step 2: Configurar SQLite**

Editar `.env`: dejar solo `DB_CONNECTION=sqlite` y borrar las otras líneas `DB_*`. Luego:

```bash
touch database/database.sqlite
php artisan migrate
```
Expected: migraciones por defecto corren sin error.

- [ ] **Step 3: Instalar Filament**

```bash
composer require filament/filament:"^3.2"
php artisan filament:install --panels
```
Acepta el panel por defecto (id `admin`, path `/admin`).

- [ ] **Step 4: Crear usuario admin**

```bash
php artisan make:filament-user
```
Introduce nombre, email y contraseña (estos son TUS credenciales del panel, no los de AimHarder).

- [ ] **Step 5: Verificar que arranca**

```bash
php artisan test
php artisan serve &
curl -s -o /dev/null -w "%{http_code}\n" http://127.0.0.1:8000/admin/login
kill %1
```
Expected: tests por defecto en verde; `/admin/login` devuelve `200`.

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "chore: scaffold Laravel + SQLite + Filament"
```

---

### Task 2: Config, migraciones y modelos

**Files:**
- Create: `config/aimharder.php`
- Create: `database/migrations/xxxx_create_accounts_table.php`, `..._create_booking_rules_table.php`, `..._create_booking_logs_table.php`
- Create: `app/Models/Account.php`, `app/Models/BookingRule.php`, `app/Models/BookingLog.php`
- Test: `tests/Unit/AccountModelTest.php`

**Interfaces:**
- Produces:
  - `Account`: props `label, email, password (encrypted), fingerprint, subdomain, box_id (int), active (bool)`; relación `rules()`; `fingerprint` se autogenera al crear si está vacío.
  - `BookingRule`: props `account_id, weekdays (array<int>), time (string "HH:MM"), class_name (string), insist (bool), active (bool)`; relación `account()`.
  - `BookingLog`: props `account_id, booking_rule_id (nullable), target_date (date), class_id (?string), status (string), book_state (?int), message (string)`.

- [ ] **Step 1: Escribir el test de modelo (falla)**

`tests/Unit/AccountModelTest.php`:
```php
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
```

- [ ] **Step 2: Ejecutar el test (debe fallar)**

Run: `php artisan test --filter=AccountModelTest`
Expected: FAIL (tablas/modelos no existen).

- [ ] **Step 3: Crear `config/aimharder.php`**

```php
<?php
return [
    'subdomain'  => env('AIMHARDER_SUBDOMAIN', 'hybridboxgrau'),
    'box_id'     => (int) env('AIMHARDER_BOX_ID', 8244),
    'run_at'     => env('AIMHARDER_RUN_AT', '06:00'),
    'timezone'   => env('AIMHARDER_TZ', 'Europe/Madrid'),
    'retries'    => (int) env('AIMHARDER_RETRIES', 3),
    'user_agent' => env('AIMHARDER_UA', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36'),
];
```

- [ ] **Step 4: Crear las migraciones**

```bash
php artisan make:migration create_accounts_table
php artisan make:migration create_booking_rules_table
php artisan make:migration create_booking_logs_table
```

`create_accounts_table` (dentro de `up()`):
```php
Schema::create('accounts', function (Blueprint $table) {
    $table->id();
    $table->string('label');
    $table->string('email');
    $table->text('password');                 // cifrada por el cast
    $table->string('fingerprint', 64)->nullable();
    $table->string('subdomain')->default('hybridboxgrau');
    $table->unsignedInteger('box_id')->default(8244);
    $table->boolean('active')->default(true);
    $table->timestamps();
});
```

`create_booking_rules_table`:
```php
Schema::create('booking_rules', function (Blueprint $table) {
    $table->id();
    $table->foreignId('account_id')->constrained()->cascadeOnDelete();
    $table->json('weekdays');                  // [1..7] ISO
    $table->string('time');                    // "18:00"
    $table->string('class_name');              // "CrossFit"
    $table->boolean('insist')->default(false);
    $table->boolean('active')->default(true);
    $table->timestamps();
});
```

`create_booking_logs_table`:
```php
Schema::create('booking_logs', function (Blueprint $table) {
    $table->id();
    $table->foreignId('account_id')->constrained()->cascadeOnDelete();
    $table->foreignId('booking_rule_id')->nullable()->constrained()->nullOnDelete();
    $table->date('target_date');
    $table->string('class_id')->nullable();
    $table->string('status');                  // booked | failed | no_match | already
    $table->integer('book_state')->nullable();
    $table->text('message')->nullable();
    $table->timestamp('created_at')->nullable();
});
```

- [ ] **Step 5: Crear los modelos**

`app/Models/Account.php`:
```php
<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Account extends Model
{
    protected $fillable = ['label', 'email', 'password', 'fingerprint', 'subdomain', 'box_id', 'active'];

    protected function casts(): array
    {
        return ['password' => 'encrypted', 'box_id' => 'integer', 'active' => 'boolean'];
    }

    protected $attributes = ['subdomain' => 'hybridboxgrau', 'box_id' => 8244, 'active' => true];

    protected static function booted(): void
    {
        static::creating(function (Account $a) {
            if (empty($a->fingerprint)) {
                $a->fingerprint = substr(hash('sha256', 'aimharder-bot-'.$a->email), 0, 50);
            }
        });
    }

    public function rules(): HasMany
    {
        return $this->hasMany(BookingRule::class);
    }
}
```

`app/Models/BookingRule.php`:
```php
<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BookingRule extends Model
{
    protected $fillable = ['account_id', 'weekdays', 'time', 'class_name', 'insist', 'active'];

    protected function casts(): array
    {
        return ['weekdays' => 'array', 'insist' => 'boolean', 'active' => 'boolean'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
```

`app/Models/BookingLog.php`:
```php
<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BookingLog extends Model
{
    public $timestamps = false;

    protected $fillable = ['account_id', 'booking_rule_id', 'target_date', 'class_id', 'status', 'book_state', 'message', 'created_at'];

    protected function casts(): array
    {
        return ['target_date' => 'date', 'created_at' => 'datetime', 'book_state' => 'integer'];
    }

    protected static function booted(): void
    {
        static::creating(fn (BookingLog $l) => $l->created_at ??= now());
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(BookingRule::class, 'booking_rule_id');
    }
}
```

- [ ] **Step 6: Migrar y ejecutar el test (debe pasar)**

Run: `php artisan migrate && php artisan test --filter=AccountModelTest`
Expected: PASS (3 tests).

- [ ] **Step 7: Commit**

```bash
git add -A
git commit -m "feat: config, migraciones y modelos (Account, BookingRule, BookingLog)"
```

---

### Task 3: ClassMatcher (lógica pura de emparejamiento)

**Files:**
- Create: `app/Services/Aimharder/ClassMatcher.php`
- Test: `tests/Unit/ClassMatcherTest.php`

**Interfaces:**
- Produces: `ClassMatcher::find(array $payload, string $time, string $className): ?array` — `$payload` es el JSON de `/api/bookings` decodificado (`['bookings'=>[...], 'timetable'=>[...]]`). Devuelve el objeto de la clase que casa (con sus claves `id`, `bookState`, …) o `null`.

- [ ] **Step 1: Escribir el test (falla)**

`tests/Unit/ClassMatcherTest.php`:
```php
<?php
use App\Services\Aimharder\ClassMatcher;

function samplePayload(): array
{
    return [
        'timetable' => [
            ['id' => '1800_60', 'time' => '18:00-19:00'],
            ['id' => '1900_60', 'time' => '19:00-20:00'],
        ],
        'bookings' => [
            ['id' => 111, 'timeid' => '1800_60', 'className' => 'B. Jiu-jitsu Principiante', 'bookState' => 0],
            ['id' => 222, 'timeid' => '1800_60', 'className' => 'CrossFit', 'bookState' => 0],
            ['id' => 333, 'timeid' => '1900_60', 'className' => 'CrossFit', 'bookState' => 1],
        ],
    ];
}

it('encuentra la clase por hora + nombre, desempatando clases solapadas', function () {
    $m = ClassMatcher::find(samplePayload(), '18:00', 'CrossFit');
    expect($m['id'])->toBe(222);
});

it('devuelve null si no hay coincidencia', function () {
    expect(ClassMatcher::find(samplePayload(), '18:00', 'HYROX-Endurance'))->toBeNull();
    expect(ClassMatcher::find(samplePayload(), '07:00', 'CrossFit'))->toBeNull();
});

it('conserva bookState para detectar ya-reservada', function () {
    $m = ClassMatcher::find(samplePayload(), '19:00', 'CrossFit');
    expect($m['bookState'])->toBe(1);
});
```

- [ ] **Step 2: Ejecutar el test (debe fallar)**

Run: `php artisan test --filter=ClassMatcherTest`
Expected: FAIL ("Class ... ClassMatcher not found").

- [ ] **Step 3: Implementar `ClassMatcher`**

`app/Services/Aimharder/ClassMatcher.php`:
```php
<?php
namespace App\Services\Aimharder;

class ClassMatcher
{
    /**
     * @param  array  $payload  JSON decodificado de /api/bookings
     * @return array|null  el objeto de clase que casa, o null
     */
    public static function find(array $payload, string $time, string $className): ?array
    {
        $timeById = [];
        foreach ($payload['timetable'] ?? [] as $slot) {
            $timeById[$slot['id']] = $slot['time'] ?? '';
        }

        foreach ($payload['bookings'] ?? [] as $b) {
            $slotTime = $timeById[$b['timeid'] ?? ''] ?? '';
            if (str_starts_with($slotTime, $time) && ($b['className'] ?? '') === $className) {
                return $b;
            }
        }

        return null;
    }
}
```

- [ ] **Step 4: Ejecutar el test (debe pasar)**

Run: `php artisan test --filter=ClassMatcherTest`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "feat: ClassMatcher (empareja clase por hora + nombre)"
```

---

### Task 4: AimharderClient (login + listar + reservar)

**Files:**
- Create: `app/Services/Aimharder/AimharderClient.php`
- Create: `app/Services/Aimharder/AuthException.php`
- Test: `tests/Feature/AimharderClientTest.php`

**Interfaces:**
- Consumes: nada de tareas previas.
- Produces:
  - `new AimharderClient(string $subdomain, int $boxId)`
  - `login(string $email, string $password, string $fingerprint): void` — lanza `AuthException` si no llega `amhrdrauth`.
  - `listClasses(string $day): array` — devuelve el JSON de `/api/bookings`.
  - `book(string $classId, string $day, bool $insist = false): array` — devuelve el JSON de `/api/book`.

- [ ] **Step 1: Escribir el test (falla)**

`tests/Feature/AimharderClientTest.php`:
```php
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
    expect(true)->toBeTrue();
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
    Http::assertSent(fn ($r) => $r['id'] === '222' && $r['day'] === '20260622' && $r['insist'] === 0 && $r['familyId'] === '');
});
```

- [ ] **Step 2: Ejecutar el test (debe fallar)**

Run: `php artisan test --filter=AimharderClientTest`
Expected: FAIL (clases no existen).

- [ ] **Step 3: Crear la excepción**

`app/Services/Aimharder/AuthException.php`:
```php
<?php
namespace App\Services\Aimharder;

class AuthException extends \RuntimeException {}
```

- [ ] **Step 4: Implementar el cliente**

`app/Services/Aimharder/AimharderClient.php`:
```php
<?php
namespace App\Services\Aimharder;

use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Cookie\SetCookie;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class AimharderClient
{
    private CookieJar $jar;

    public function __construct(private string $subdomain, private int $boxId)
    {
        $this->jar = new CookieJar();
    }

    public function login(string $email, string $password, string $fingerprint): void
    {
        $res = $this->http()
            ->withHeaders([
                'Origin'  => 'https://login.aimharder.com',
                'Referer' => 'https://login.aimharder.com/',
            ])
            ->asJson()
            ->post('https://login.aimharder.com/api/login', [
                'username'    => $email,
                'password'    => $password,
                'fingerprint' => $fingerprint,
                'iniframe'    => 0,
            ]);

        $auth = $this->jar->getCookieByName('amhrdrauth')?->getValue()
            ?? $this->extractSetCookie($res, 'amhrdrauth');

        if ($auth === null) {
            throw new AuthException('Login fallido: no se recibió la cookie amhrdrauth (credenciales incorrectas).');
        }

        // Fijar la cookie al dominio padre para que viaje al subdominio del box.
        $this->jar->setCookie(new SetCookie([
            'Name' => 'amhrdrauth', 'Value' => $auth, 'Domain' => 'aimharder.com', 'Path' => '/',
        ]));
    }

    public function listClasses(string $day): array
    {
        return $this->boxHttp()
            ->get($this->boxUrl('/api/bookings'), [
                'day'      => $day,
                'familyId' => '',
                'box'      => $this->boxId,
                '_'        => (int) (microtime(true) * 1000),
            ])
            ->json() ?? [];
    }

    public function book(string $classId, string $day, bool $insist = false): array
    {
        return $this->boxHttp()
            ->asForm()
            ->post($this->boxUrl('/api/book'), [
                'id'       => $classId,
                'day'      => $day,
                'insist'   => $insist ? 1 : 0,
                'familyId' => '',
            ])
            ->json() ?? [];
    }

    private function http(): PendingRequest
    {
        return Http::withOptions(['cookies' => $this->jar])
            ->withHeaders(['User-Agent' => config('aimharder.user_agent')])
            ->retry(config('aimharder.retries'), 250, throw: false);
    }

    private function boxHttp(): PendingRequest
    {
        return $this->http()->withHeaders([
            'Origin'  => $this->boxUrl(''),
            'Referer' => $this->boxUrl('/schedule'),
        ]);
    }

    private function boxUrl(string $path): string
    {
        return "https://{$this->subdomain}.aimharder.com{$path}";
    }

    private function extractSetCookie(Response $res, string $name): ?string
    {
        foreach ($res->toPsrResponse()->getHeader('Set-Cookie') as $line) {
            if (str_contains($line, "$name=")) {
                return explode(';', explode("$name=", $line)[1])[0];
            }
        }

        return null;
    }
}
```

- [ ] **Step 5: Ejecutar el test (debe pasar)**

Run: `php artisan test --filter=AimharderClientTest`
Expected: PASS (4 tests).

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "feat: AimharderClient (login con amhrdrauth, listClasses, book)"
```

---

### Task 5: Comando `bookings:run` (orquestación + dry-run)

**Files:**
- Create: `app/Console/Commands/RunBookings.php`
- Test: `tests/Feature/RunBookingsTest.php`

**Interfaces:**
- Consumes: `Account`, `BookingRule`, `BookingLog` (Task 2); `ClassMatcher::find` (Task 3); `AimharderClient` (Task 4).
- Produces: comando artisan `bookings:run {--dry-run}`. Crea filas en `booking_logs` con `status` ∈ `booked|failed|no_match|already`.

- [ ] **Step 1: Escribir el test (falla)**

`tests/Feature/RunBookingsTest.php`:
```php
<?php
use App\Models\Account;
use App\Models\BookingLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    // 2026-06-22 es lunes (ISO weekday 1)
    Carbon::setTestNow(Carbon::create(2026, 6, 22, 6, 0, 0, 'Europe/Madrid'));
});

afterEach(fn () => Carbon::setTestNow());

function bookingsJson(): array
{
    return [
        'timetable' => [['id' => '1800_60', 'time' => '18:00-19:00']],
        'bookings'  => [['id' => 222, 'timeid' => '1800_60', 'className' => 'CrossFit', 'bookState' => 0]],
    ];
}

it('reserva la clase del día y registra status booked', function () {
    Http::fake([
        'login.aimharder.com/api/login'              => Http::response('{}', 200, ['Set-Cookie' => 'amhrdrauth=x; Domain=aimharder.com']),
        'hybridboxgrau.aimharder.com/api/bookings*'  => Http::response(bookingsJson(), 200),
        'hybridboxgrau.aimharder.com/api/book'       => Http::response(['bookState' => 0, 'id' => '555'], 200),
    ]);

    $a = Account::create(['label' => 'Yo', 'email' => 'a@b.com', 'password' => 'pw']);
    $a->rules()->create(['weekdays' => [1], 'time' => '18:00', 'class_name' => 'CrossFit']);

    $this->artisan('bookings:run')->assertOk();

    expect(BookingLog::where('status', 'booked')->count())->toBe(1);
});

it('en dry-run no llama a /api/book ni registra booked', function () {
    Http::fake([
        'login.aimharder.com/api/login'             => Http::response('{}', 200, ['Set-Cookie' => 'amhrdrauth=x; Domain=aimharder.com']),
        'hybridboxgrau.aimharder.com/api/bookings*' => Http::response(bookingsJson(), 200),
    ]);

    $a = Account::create(['label' => 'Yo', 'email' => 'a@b.com', 'password' => 'pw']);
    $a->rules()->create(['weekdays' => [1], 'time' => '18:00', 'class_name' => 'CrossFit']);

    $this->artisan('bookings:run --dry-run')->assertOk();

    Http::assertNotSent(fn ($r) => str_contains($r->url(), '/api/book'));
    expect(BookingLog::where('status', 'booked')->count())->toBe(0);
});

it('registra no_match cuando la clase no existe', function () {
    Http::fake([
        'login.aimharder.com/api/login'             => Http::response('{}', 200, ['Set-Cookie' => 'amhrdrauth=x; Domain=aimharder.com']),
        'hybridboxgrau.aimharder.com/api/bookings*' => Http::response(bookingsJson(), 200),
    ]);

    $a = Account::create(['label' => 'Yo', 'email' => 'a@b.com', 'password' => 'pw']);
    $a->rules()->create(['weekdays' => [1], 'time' => '18:00', 'class_name' => 'HYROX-Endurance']);

    $this->artisan('bookings:run')->assertOk();

    expect(BookingLog::where('status', 'no_match')->count())->toBe(1);
});
```

- [ ] **Step 2: Ejecutar el test (debe fallar)**

Run: `php artisan test --filter=RunBookingsTest`
Expected: FAIL (comando no existe).

- [ ] **Step 3: Implementar el comando**

`app/Console/Commands/RunBookings.php`:
```php
<?php
namespace App\Console\Commands;

use App\Models\Account;
use App\Models\BookingLog;
use App\Models\BookingRule;
use App\Services\Aimharder\AimharderClient;
use App\Services\Aimharder\ClassMatcher;
use Illuminate\Console\Command;

class RunBookings extends Command
{
    protected $signature = 'bookings:run {--dry-run : Lista y empareja, sin reservar}';

    protected $description = 'Reserva las clases de hoy según las reglas activas';

    public function handle(): int
    {
        $today = now(config('aimharder.timezone'));
        $iso   = $today->dayOfWeekIso;          // 1=lun … 7=dom
        $day   = $today->format('Ymd');
        $dry   = (bool) $this->option('dry-run');

        $rules = BookingRule::with('account')
            ->where('active', true)
            ->get()
            ->filter(fn (BookingRule $r) => in_array($iso, $r->weekdays, true))
            ->filter(fn (BookingRule $r) => $r->account?->active);

        if ($rules->isEmpty()) {
            $this->info("Sin reglas para hoy ($day).");

            return self::SUCCESS;
        }

        foreach ($rules->groupBy('account_id') as $accountRules) {
            $account = $accountRules->first()->account;
            $this->processAccount($account, $accountRules, $day, $dry);
        }

        return self::SUCCESS;
    }

    private function processAccount(Account $account, $rules, string $day, bool $dry): void
    {
        $client = new AimharderClient($account->subdomain, $account->box_id);

        try {
            $client->login($account->email, $account->password, $account->fingerprint);
        } catch (\Throwable $e) {
            foreach ($rules as $rule) {
                $this->log($account, $rule, $day, null, 'failed', null, 'Login: '.$e->getMessage());
            }

            return;
        }

        $payload = $client->listClasses($day);

        foreach ($rules as $rule) {
            $match = ClassMatcher::find($payload, $rule->time, $rule->class_name);

            if ($match === null) {
                $this->log($account, $rule, $day, null, 'no_match', null,
                    "No se encontró {$rule->class_name} a las {$rule->time}.");

                continue;
            }

            $classId = (string) $match['id'];

            if (($match['bookState'] ?? null) === 1) {
                $this->log($account, $rule, $day, $classId, 'already', 1, 'Ya estaba reservada.');

                continue;
            }

            if ($dry) {
                $this->info("[dry-run] {$account->label}: reservaría {$rule->class_name} {$rule->time} (id $classId)");

                continue;
            }

            $res    = $client->book($classId, $day, $rule->insist);
            $state  = $res['bookState'] ?? null;
            $errLang = $res['errorMssgLang'] ?? null;
            $hasError = isset($res['errorMssg']) || isset($res['errorMssgLang']);

            if ($errLang === 'NOPUEDESRESERVAMISMAHORA') {
                $status = 'already';
            } elseif ($hasError) {
                $status = 'failed';
            } else {
                $status = 'booked';
            }

            $this->log($account, $rule, $day, $classId, $status, $state,
                $res['errorMssg'] ?? "bookState=$state");
        }
    }

    private function log(Account $a, BookingRule $r, string $day, ?string $classId, string $status, ?int $state, string $msg): void
    {
        BookingLog::create([
            'account_id'      => $a->id,
            'booking_rule_id' => $r->id,
            'target_date'     => $day,
            'class_id'        => $classId,
            'status'          => $status,
            'book_state'      => $state,
            'message'         => $msg,
        ]);

        $this->line("[$status] {$a->label} · {$r->class_name} {$r->time} · $msg");
    }
}
```

- [ ] **Step 4: Ejecutar el test (debe pasar)**

Run: `php artisan test --filter=RunBookingsTest`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "feat: comando bookings:run con --dry-run y logging de resultados"
```

---

### Task 6: Scheduler (06:00 Europe/Madrid)

**Files:**
- Modify: `routes/console.php`
- Test: `tests/Feature/ScheduleTest.php`

**Interfaces:**
- Consumes: comando `bookings:run` (Task 5).
- Produces: el comando registrado en el scheduler a `config('aimharder.run_at')` en `config('aimharder.timezone')`.

- [ ] **Step 1: Escribir el test (falla)**

`tests/Feature/ScheduleTest.php`:
```php
<?php
use Illuminate\Console\Scheduling\Schedule;

it('agenda bookings:run a las 06:00 en Europe/Madrid', function () {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($e) => str_contains($e->command ?? '', 'bookings:run'));

    expect($events)->toHaveCount(1);
    $event = $events->first();
    expect($event->expression)->toBe('0 6 * * *')
        ->and($event->timezone)->toBe('Europe/Madrid');
});
```

- [ ] **Step 2: Ejecutar el test (debe fallar)**

Run: `php artisan test --filter=ScheduleTest`
Expected: FAIL (no hay evento agendado).

- [ ] **Step 3: Registrar el schedule**

Añadir al final de `routes/console.php`:
```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('bookings:run')
    ->dailyAt(config('aimharder.run_at'))
    ->timezone(config('aimharder.timezone'));
```

- [ ] **Step 4: Ejecutar el test (debe pasar)**

Run: `php artisan test --filter=ScheduleTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "feat: agenda bookings:run a las 06:00 Europe/Madrid"
```

---

### Task 7: Panel Filament (cuentas, reglas, logs)

**Files:**
- Create: `app/Filament/Resources/AccountResource.php` (+ páginas generadas)
- Create: `app/Filament/Resources/BookingRuleResource.php` (+ páginas)
- Create: `app/Filament/Resources/BookingLogResource.php` (+ páginas)
- Test: `tests/Feature/FilamentSmokeTest.php`

**Interfaces:**
- Consumes: modelos de Task 2.
- Produces: CRUD de cuentas y reglas; vista de solo lectura de logs; todo bajo `/admin` con login.

- [ ] **Step 1: Generar los resources**

```bash
php artisan make:filament-resource Account --generate
php artisan make:filament-resource BookingRule --generate
php artisan make:filament-resource BookingLog --generate
```

- [ ] **Step 2: Form de Account (contraseña cifrada, no se muestra)**

En `app/Filament/Resources/AccountResource.php`, método `form()`:
```php
use Filament\Forms;

return $form->schema([
    Forms\Components\TextInput::make('label')->required(),
    Forms\Components\TextInput::make('email')->email()->required(),
    Forms\Components\TextInput::make('password')
        ->password()->revealable()
        ->required(fn (string $context) => $context === 'create')
        ->dehydrated(fn ($state) => filled($state))   // al editar, vacío = no cambiar
        ->helperText('Contraseña de AimHarder. Se guarda cifrada.'),
    Forms\Components\TextInput::make('subdomain')->default('hybridboxgrau')->required(),
    Forms\Components\TextInput::make('box_id')->numeric()->default(8244)->required(),
    Forms\Components\Toggle::make('active')->default(true),
]);
```
En `table()`, no incluir la columna `password`. Mostrar `label`, `email`, `subdomain`, `active`.

- [ ] **Step 3: Form de BookingRule (días como checkbox)**

En `app/Filament/Resources/BookingRuleResource.php`, `form()`:
```php
use Filament\Forms;

return $form->schema([
    Forms\Components\Select::make('account_id')
        ->relationship('account', 'label')->required(),
    Forms\Components\CheckboxList::make('weekdays')
        ->options([1 => 'Lun', 2 => 'Mar', 3 => 'Mié', 4 => 'Jue', 5 => 'Vie', 6 => 'Sáb', 7 => 'Dom'])
        ->columns(7)->required(),
    Forms\Components\TextInput::make('time')->placeholder('18:00')->required(),
    Forms\Components\TextInput::make('class_name')->placeholder('CrossFit')->required(),
    Forms\Components\Toggle::make('insist')->helperText('Insistir / lista de espera'),
    Forms\Components\Toggle::make('active')->default(true),
]);
```

- [ ] **Step 4: BookingLog en solo lectura**

En `app/Filament/Resources/BookingLogResource.php`:
```php
public static function canCreate(): bool { return false; }
```
Borrar las páginas `Create`/`Edit` del array de `getPages()` (dejar solo `index`), y quitar las acciones de edición/borrado de la tabla. En `table()` mostrar `created_at`, `account.label`, `class_id`, `status`, `book_state`, `message`, con filtro por `status`.

- [ ] **Step 5: Escribir el smoke test**

`tests/Feature/FilamentSmokeTest.php`:
```php
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
```

- [ ] **Step 6: Ejecutar el smoke test (debe pasar)**

Run: `php artisan test --filter=FilamentSmokeTest`
Expected: PASS (3 tests). Si una ruta no es `/admin/accounts`, ajustar al slug que Filament generó (ver `php artisan route:list | grep admin`).

- [ ] **Step 7: Commit**

```bash
git add -A
git commit -m "feat: panel Filament (cuentas, reglas, logs)"
```

---

### Task 8: Runbook de despliegue

**Files:**
- Create: `README.md`

**Interfaces:**
- Consumes: todo lo anterior.
- Produces: instrucciones reproducibles para dejarlo corriendo en el VPS.

- [ ] **Step 1: Escribir el README**

`README.md`:
```markdown
# AimHarder Bot

Reserva clases recurrentes en AimHarder. Panel web (Filament) para gestionar
cuentas y reglas; un cron dispara la reserva a las 06:00 (Europe/Madrid).

## Despliegue (VPS Ubuntu, OVH)

1. Clonar y dependencias:
   ```bash
   git clone <repo> aimharder-bot && cd aimharder-bot
   composer install --no-dev
   cp .env.example .env && php artisan key:generate
   ```
2. Editar `.env`: `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://tu-subdominio`,
   `DB_CONNECTION=sqlite`.
3. Base de datos y assets:
   ```bash
   touch database/database.sqlite
   php artisan migrate --force
   php artisan filament:assets
   php artisan make:filament-user   # tu acceso al panel
   ```
4. Nginx + php-fpm apuntando a `public/`, con HTTPS (certbot) en el subdominio.
5. Cron (una línea, como el usuario de la app):
   ```
   * * * * * cd /ruta/aimharder-bot && php artisan schedule:run >> /dev/null 2>&1
   ```

## Uso

- Entra al panel (`/admin`), crea una **cuenta** (email + contraseña de AimHarder).
- Crea **reglas**: días + hora (`18:00`) + nombre de clase (`CrossFit`).
- Revisa **Logs** para ver el resultado de cada ejecución.

## Probar sin reservar

```bash
php artisan bookings:run --dry-run
```

## Notas

- La reserva es de la clase **del mismo día** (las reservas abren a las 00:00).
- Rota la contraseña de AimHarder si se compartió en algún sitio e introdúcela en el panel.
```

- [ ] **Step 2: Verificar dry-run de punta a punta (manual, con una cuenta real)**

Crea una cuenta y una regla reales en el panel para una clase de hoy, y ejecuta:
```bash
php artisan bookings:run --dry-run
```
Expected: imprime `[dry-run] … reservaría <clase> <hora> (id …)` con el id real → confirma que login + listado + matching funcionan contra AimHarder de verdad.

- [ ] **Step 3: Commit**

```bash
git add -A
git commit -m "docs: runbook de despliegue y uso"
```

---

## Self-Review

**Spec coverage:**
- §3 stack → Task 1. §5 modelo de datos → Task 2. §6 API (login/list/book) → Task 4. §6.2 cookie padre + fallback → Task 4 (`login()` fija `Domain=aimharder.com`, fallback vía `extractSetCookie`). §7 matching → Task 3. §8 flujo + reintentos + idempotencia → Task 5 (status `already` por `bookState==1` y por `NOPUEDESRESERVAMISMAHORA`; `retry()` en el cliente). §9 scheduler/cron → Task 6 + Task 8. §10 UI → Task 7. §11 seguridad → Task 2 (cast `encrypted`) + Task 7 (password no se muestra) + Task 8. §12 pruebas (dry-run + matcher) → Task 5 + Task 3. §13 despliegue → Task 8. §14 geo-bloqueo → resuelto, sin tarea.
- **Sin huecos.**

**Placeholder scan:** sin TBD/TODO; todo el código va explícito.

**Type consistency:** `ClassMatcher::find(array,string,string): ?array` usado igual en Task 3 y Task 5. `AimharderClient::login/listClasses/book` con las firmas de Task 4 usadas en Task 5. `BookingLog` campos `status/book_state/class_id` consistentes entre Task 2 y Task 5.
