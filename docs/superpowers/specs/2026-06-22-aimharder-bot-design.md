# AimHarder Bot — Diseño

**Fecha:** 2026-06-22
**Estado:** aprobado el enfoque; pendiente revisión de la spec.

## 1. Objetivo

Automatizar reservas recurrentes de clases en AimHarder (box `hybridboxgrau`).
Las reservas se abren a las 00:00; el bot reserva **a las 06:00 la clase del mismo
día**. Todo gestionable desde un **panel web con URL pública protegida**: varias
cuentas y reglas (día + hora + clase).

## 2. Alcance

**Incluye:**
- Login server-side en AimHarder, por cuenta.
- Reserva automática diaria según reglas (día de semana + hora + nombre de clase).
- Panel web (Filament) para CRUD de cuentas y reglas + ver el log de ejecuciones.
- Multi-cuenta.

**No incluye (YAGNI):**
- Cancelaciones (el endpoint queda documentado por si acaso, sin construirlo).
- Re-intento al liberarse plaza más allá del flag `insist` de la API.
- Notificaciones externas (Telegram/email): el estado se ve en el panel.
- Reservar clases de días distintos de "hoy".

## 3. Stack

- **Laravel** (estable actual) + **Filament 3** (panel admin).
- **DB:** SQLite (suficiente para este volumen; sin servidor de BD aparte).
- **HTTP:** cliente HTTP de Laravel (Guzzle), sin librerías extra.
- **Scheduler** de Laravel + un cron en el VPS.

## 4. Estructura

```
app/
  Models/Account.php
  Models/BookingRule.php
  Models/BookingLog.php
  Services/Aimharder/AimharderClient.php   # login, listClasses, book
  Services/Aimharder/ClassMatcher.php      # fn pura: regla + clases -> class_id
  Console/Commands/RunBookings.php         # artisan bookings:run [--dry-run]
  Filament/Resources/AccountResource.php
  Filament/Resources/BookingRuleResource.php
  Filament/Resources/BookingLogResource.php   # solo lectura
database/migrations/
config/aimharder.php   # defaults: subdomain, box_id, hora disparo, tz, reintentos
tests/Unit/ClassMatcherTest.php
```

## 5. Modelo de datos

**accounts**
| campo | tipo | nota |
|---|---|---|
| id | pk | |
| label | string | alias para identificarla en el panel |
| email | string | |
| password | text | **cast `encrypted`** (cifrado en reposo con APP_KEY) |
| fingerprint | string(50) | estable por cuenta; ver §6 |
| subdomain | string | default `hybridboxgrau` |
| box_id | integer | default `8244` |
| active | bool | |

**booking_rules**
| campo | tipo | nota |
|---|---|---|
| id | pk | |
| account_id | fk | |
| weekdays | json | p.ej. `[1,2,3]` (ISO: 1=lun … 7=dom) |
| time | string | `"18:00"` |
| class_name | string | `"CrossFit"` (casa por nombre; ver §7) |
| insist | bool | default `false` → `insist=0` |
| active | bool | |

**booking_logs**
| campo | tipo | nota |
|---|---|---|
| id | pk | |
| account_id | fk | |
| booking_rule_id | fk nullable | |
| target_date | date | |
| class_id | string nullable | id de la clase resuelto |
| status | string | `booked` / `failed` / `no_match` / `already` |
| book_state | integer nullable | `bookState` devuelto |
| message | text | detalle legible |
| created_at | timestamp | |

## 6. API de AimHarder (capturada + verificada con 4 bots open-source, confianza alta)

### 6.1 Login
```
POST https://login.aimharder.com/api/login
Content-Type: application/json
Body: { "username": <email>, "password": <pass>, "fingerprint": <hex/alfanum 50 chars>, "iniframe": 0 }
```
- **`fingerprint`**: identificador de cliente de ~50 chars `[a-z0-9]`. No lo emite el
  servidor ni lo valida más allá de que exista. **Lo generamos estable por cuenta**
  (p.ej. `substr(hash('sha256', "aimharder-bot-{email}"), 0, 50)`) y lo guardamos.
- **Éxito = aparece la cookie `amhrdrauth` en las cabeceras `Set-Cookie`.** No se
  depende del cuerpo JSON (su forma exacta no está verificada). Si no aparece
  `amhrdrauth` → credenciales incorrectas → log `failed`.

### 6.2 Auth entre subdominios (la clave)
La respuesta del login hace `Set-Cookie: amhrdrauth=…` con **`Domain=.aimharder.com`**
(dominio padre), no `login.aimharder.com`. Por eso vale en **todos** los subdominios,
incluido `hybridboxgrau.aimharder.com`. Es cookie pura: ni token en query, ni Bearer,
ni redirect. Caduca a los ~30 min → **login fresco en cada ejecución** (encaja con el
disparo a las 06:00).

> **Gotcha de cookies entre subdominios.** El cliente HTTP debe guardar una cookie cuyo
> `Domain` (`.aimharder.com`) es el padre del host que responde (`login.aimharder.com`).
> Es válido por RFC y Guzzle **debería** guardarla (`login.aimharder.com` es subdominio
> de `aimharder.com`). En Python `requests` esto falla y hay que extraer la cookie a
> mano; en PHP/Guzzle hay que **verificarlo**. **Fallback** si Guzzle la descarta: leer
> el header `Set-Cookie` crudo, extraer el valor de `amhrdrauth` e inyectarlo en el
> `CookieJar` con `Domain=aimharder.com` antes de llamar al subdominio del box.

### 6.3 Listar clases de un día
```
GET https://{subdomain}.aimharder.com/api/bookings?day=YYYYMMDD&familyId=&box={box_id}&_={ts_ms}
Cookie: amhrdrauth
```
Respuesta JSON: `{ bookings: [ {id, timeid, className, bookState, ...} ], timetable: [{id, time}], clasesDisp, day }`.
- Cada clase trae `id` (el que se reserva), `timeid` (p.ej. `"1800_60"`), `className`,
  y `bookState` (`==1` → ya reservada).
- `_` es un cache-buster (timestamp en ms).

### 6.4 Reservar
```
POST https://{subdomain}.aimharder.com/api/book
Cookie: amhrdrauth
Content-Type: application/x-www-form-urlencoded
Body: id={class_id}&day=YYYYMMDD&insist={0|1}&familyId=
```
Respuesta JSON: `{ bookState, id (id de la reserva creada), clasesContratadas, hasPublicMemberships }`.

**Interpretación del resultado:**
| condición | significado | status log |
|---|---|---|
| HTTP 200 y sin `errorMssg`/`errorMssgLang` (en captura real `bookState: 0`) | reservado OK | `booked` |
| `bookState == -1` | lista de espera llena | `failed` |
| `bookState == -2` | sin créditos / máximo de sesiones | `failed` |
| `bookState == -12` + `errorMssgLang=ERROR_ANTELACION_CLIENTE_HORAS` | demasiado pronto | `failed` |
| `errorMssgLang=NOPUEDESRESERVAMISMAHORA` | ya reservada / misma hora | `already` |

### 6.5 (Fuera de alcance) Cancelar
`POST https://{subdomain}.aimharder.com/api/cancelBooking` form `{id, late:0, familyId:''}`.

## 7. Matching de clase (`ClassMatcher`, función pura)

Entrada: una regla (`time`, `class_name`) + la respuesta de `bookings`.
1. En `timetable`, encontrar la entrada cuyo `time` empieza por `time` de la regla
   (`"18:00"` → entrada con `time: "18:00-19:00"`) → obtener su `id` (el `timeid`).
2. En `bookings`, encontrar la clase con ese `timeid` **y** `className == class_name`.
3. Devolver su `id`. Si no hay match → `null` (→ log `no_match`).

Esto resuelve el caso real de **varias clases a la misma hora** (a las 18:00 hay
Jiu-jitsu y HYROX además de CrossFit): se desempata por nombre.

## 8. Flujo de `bookings:run`

1. Calcular **hoy** en `Europe/Madrid` y su día de semana ISO.
2. Cargar reglas activas cuyo `weekdays` incluya hoy. Si ninguna → salir.
3. Agrupar reglas por cuenta.
4. Por cuenta: `login()`. Si falla → log `failed` de sus reglas + siguiente cuenta.
5. Por cuenta: `listClasses(hoy)`. Para cada regla → `ClassMatcher` → `class_id`.
   - Sin match → log `no_match`.
   - Clase ya reservada (`bookState==1`) → log `already`, no re-reservar.
6. `book(class_id, insist)` → leer resultado (§6.4) → log.
7. **Reintentos:** ante error transitorio (red, 5xx, timeout) reintentar hasta
   `config(aimharder.retries)` veces con backoff corto. Errores de negocio (lleno, sin
   créditos) **no** se reintentan.

## 9. Scheduler / cron

`routes/console.php`:
```php
Schedule::command('bookings:run')->dailyAt('06:00')->timezone('Europe/Madrid');
```
Cron del VPS (una línea):
```
* * * * * cd /ruta/app && php artisan schedule:run >> /dev/null 2>&1
```

## 10. UI (Filament)

- Panel detrás del login de Filament (un usuario admin = tú) → **URL pública protegida**.
- **AccountResource:** CRUD de cuentas; `password` como campo password (cifrado).
- **BookingRuleResource:** CRUD de reglas; `weekdays` como checkbox múltiple, `time`,
  `class_name`, toggles `insist` y `active`.
- **BookingLogResource:** solo lectura, con filtros por fecha/estado/cuenta = el
  **"aviso en la web"** cuando algo falla.

## 11. Seguridad

- Panel detrás de auth de Filament, sobre **HTTPS**.
- `accounts.password` con cast **`encrypted`** (APP_KEY).
- Secretos fuera de git (`.env`, `*.sqlite`, `config.json` ya en `.gitignore`).
- **Acción recomendada:** rotar la contraseña de AimHarder que se compartió en el chat
  una vez el bot funcione, e introducir la nueva en el panel.

## 12. Pruebas (mínimas)

- `php artisan bookings:run --dry-run`: login + listar + match, **sin reservar**.
  Verifica el matching contra el horario real sin gastar reservas.
- `ClassMatcherTest`: casos — match exacto; dos clases a la misma hora (desempata por
  nombre); sin match.

## 13. Despliegue

- App Laravel en el VPS (nginx vhost + php-fpm), subdominio con HTTPS (certbot).
- `php artisan migrate`, crear usuario admin de Filament, configurar el cron.

## 14. Riesgos y cuestiones abiertas

1. **✓ Geo-bloqueo: RESUELTO.** Comprobado el 2026-06-22 desde el propio VPS (OVH
   Francia, IP `51.75.121.100`): `GET login.aimharder.com/` → 200 y
   `GET hybridboxgrau.aimharder.com/schedule` → 200. **No hay bloqueo**; el VPS vale
   tal cual y **no se necesita proxy**.
2. **Cookie en Guzzle:** confirmar que Guzzle guarda `amhrdrauth` (Domain padre);
   si no, aplicar el fallback de §6.2.
3. **`familyId`:** vacío en la captura; confirmar que es siempre vacío para esta cuenta
   (no una cuenta familiar con varios miembros).
4. **`box_id=8244` ↔ `hybridboxgrau`:** confirmado en la captura de `bookings`.
5. **User-Agent realista** en las peticiones (un UA implausible puede disparar 403).
