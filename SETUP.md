# Cómo probar este proyecto

Guía para quien recibe el repositorio y solo quiere levantarlo y probarlo — sin
necesitar el contexto completo de arquitectura (eso está en
[ARCHITECTURE.md](ARCHITECTURE.md)).

## Requisito único

**Docker Desktop** (incluye `docker compose`). No se necesita PHP, Composer ni
PostgreSQL instalados localmente: todo corre dentro de contenedores.

## 1. Clonar y levantar

```bash
git clone <url-del-repo>
cd <carpeta-del-repo>
docker compose up --build -d
```

La primera vez tarda 1-3 minutos (descarga imágenes base + instala dependencias
de Composer dentro del contenedor). Las siguientes veces es casi instantáneo.

## 2. Verificar que todo esté arriba

```bash
docker compose ps
```

Deberías ver **6 contenedores** en estado `Up` (los de `postgres`, `rabbitmq` y
`app` deben decir además `(healthy)`):

```
gym_app        ... Up (healthy)
gym_nginx      ... Up
gym_postgres   ... Up (healthy)
gym_rabbitmq   ... Up (healthy)
gym_relay      ... Up
gym_worker     ... Up
```

Si `gym_app` tarda en pasar a `healthy`, dale unos segundos más — está esperando
a PostgreSQL y corriendo las migraciones.

## 3. Probar el flujo completo

```bash
# Check-in (responde al instante, sin esperar a ninguna API externa)
curl -X POST http://localhost:8080/api/check-in \
  -H "Content-Type: application/json" \
  -d '{"user_id":"11111111-1111-1111-1111-111111111111","branch_id":"22222222-2222-2222-2222-222222222222"}'
```

Respuesta esperada (HTTP 201):
```json
{"access_log_id":"...", "status":"checked_in"}
```

**Opcional — reintento seguro:** si agregas el header `Idempotency-Key: <uuid>`
y repites exactamente el mismo request con la misma clave, el servidor devuelve
el mismo `access_log_id` en vez de crear un acceso duplicado:

```bash
curl -X POST http://localhost:8080/api/check-in \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: 44444444-4444-4444-4444-444444444444" \
  -d '{"user_id":"11111111-1111-1111-1111-111111111111","branch_id":"22222222-2222-2222-2222-222222222222"}'
# repite el mismo curl exacto: el access_log_id de la respuesta será IDÉNTICO
```

Espera 2-3 segundos (tiempo para que el evento se publique y el worker consulte
la API externa) y luego:

```bash
curl http://localhost:8080/api/dashboard/11111111-1111-1111-1111-111111111111
```

Respuesta esperada (HTTP 200), ya con la frase asignada:
```json
{
  "user_id": "11111111-1111-1111-1111-111111111111",
  "history": [
    {
      "access_log_id": "...",
      "checked_in_at": "...",
      "quote": { "text": "...", "author": "..." }
    }
  ]
}
```

## 4. Ejecutar los tests automatizados

```bash
docker compose exec app ./vendor/bin/phpunit --testdox
```

Deberías ver 11 tests en verde: prueban el adaptador de la API externa (timeouts,
errores 500, cambios de contrato JSON), la lógica del caso de uso de Engagement,
y la idempotencia del check-in (incluyendo condiciones de carrera) — todo sin
red ni base de datos reales.

## 5. (Opcional) Ver el broker de mensajería en vivo

Panel de administración de RabbitMQ: **http://localhost:15672**
(usuario `guest`, contraseña `guest`). Ahí se ven las colas
`engagement.assign_phrase` y `engagement.assign_phrase.dlq` (mensajes muertos)
en tiempo real.

## 6. Apagar todo

```bash
docker compose down          # detiene y elimina los contenedores
docker compose down -v       # además borra el volumen de PostgreSQL (reset total)
```

---

## Qué archivos van en el repositorio (y por qué)

Todo el código fuente, configuración, migraciones, tests y Dockerfiles se
suben. **Se excluyen deliberadamente** (ver [.gitignore](.gitignore)) los
artefactos que cada máquina genera por sí sola y que no deben viajar en el repo:

| Excluido | Por qué |
|---|---|
| `/vendor` | Dependencias de Composer — se reinstalan solas al levantar el contenedor (`composer.lock` sí se sube, para fijar versiones exactas). |
| `.env` | Contiene configuración/secretos de entorno local. Se sube `.env.example` como plantilla; el `entrypoint.sh` genera el `.env` real automáticamente si falta. |
| `/storage/framework/cache`, `/sessions`, `/views`, `/logs` | Cachés y logs generados en runtime, no código fuente. |
| `/bootstrap/cache/*.php` | Caché de configuración/rutas que Laravel regenera solo. |

Si quien evalúa el repo hace `git clone` y sigue los pasos 1-3 de arriba, no
necesita crear ni copiar ningún archivo a mano — el `entrypoint.sh` se encarga
de `.env`, `APP_KEY` y las migraciones automáticamente.

## Problemas comunes

- **Puerto ocupado (8080, 5432, 5672, 15672):** si alguno de esos puertos ya
  está en uso en tu máquina, cambia el mapeo en `docker-compose.yml` (por
  ejemplo `"8081:80"` en vez de `"8080:80"`).
- **`gym_app` no llega a `healthy`:** revisa sus logs con
  `docker compose logs app --tail=50` — casi siempre es que Postgres tardó más
  de lo esperado en aceptar conexiones; suele resolverse solo tras el segundo
  intento del healthcheck.
- **La frase no aparece en el dashboard tras varios segundos:** revisa
  `docker compose logs worker --tail=30` — si la API externa
  (`dummyjson.com`) está lenta o caída, el sistema reintenta y finalmente manda
  el evento a la Dead Letter Queue (comportamiento esperado: reintentos con
  backoff y luego Dead Letter Exchange, ver [ARCHITECTURE.md](ARCHITECTURE.md)
  sección 6).
