# AimHarder Bot

Reserva clases recurrentes en AimHarder. Panel web (Filament) para gestionar
cuentas y reglas; un worker dispara la reserva a las 06:00 (Europe/Madrid).

## Despliegue en Dokploy (Docker)

Dos servicios desde este mismo repo/imagen, compartiendo un volumen:

1. **Aplicación:** Dokploy → New Application → fuente = este repo, build type = Dockerfile.
2. **Variables de entorno** (en AMBOS servicios):
   - `APP_KEY` = clave fija (genérala una vez con `php artisan key:generate --show`).
     ⚠️ NO la cambies entre despliegues: cifra las contraseñas de AimHarder; si cambia,
     dejan de descifrarse y el login del bot se rompe.
   - `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://tu-subdominio`
   - `DB_CONNECTION=sqlite`, `DB_DATABASE=/app/persistent/database.sqlite`
     (el Dockerfile ya trae este valor por defecto)
3. **Volumen persistente:** monta un volumen en **`/app/persistent`** (web y worker). Ahí vive
   `database.sqlite` (cuentas, reglas, logs). Sin volumen se borra en cada redeploy.
   ⚠️ NO montes en `/app/database`: esa carpeta contiene las migraciones del código y el
   volumen las taparía.
4. **Servicio web:** usa el `CMD` por defecto (migra y sirve el panel en el puerto 8080).
   Expón el dominio con HTTPS (Dokploy/Traefik lo gestiona).
5. **Servicio worker:** mismo repo/imagen, **command** override = `php artisan schedule:work`.
   Comparte el mismo volumen. Es quien ejecuta `bookings:run` a las 06:00 Madrid.
6. **Usuario admin** (una vez, en una shell del contenedor web): `php artisan make:filament-user`.

## Uso

- Panel `/admin` → crea una **cuenta** (email + contraseña de AimHarder) y **reglas**
  (días + hora `18:00` + nombre de clase `CrossFit`).
- **Logs** muestra el resultado de cada ejecución (tu aviso si algo falla).

## Probar sin reservar

```bash
php artisan bookings:run --dry-run   # en una shell del contenedor
```

## Notas

- Reserva la clase **del mismo día** (las reservas abren a las 00:00).
- Rota la contraseña de AimHarder compartida en el chat e introdúcela en el panel.
