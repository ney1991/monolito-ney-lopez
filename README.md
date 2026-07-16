# Monolito Modular — Control de Acceso y Engagement

> Documento de estudio. Léelo de corrido una vez y luego úsalo como referencia:
> cada sección tiene enlaces directos al código real. El diseño formal (el
> entregable que pide la prueba) está en [ARCHITECTURE.md](ARCHITECTURE.md); este
> README es el "por qué" explicado con más calma, pensado para que puedas
> defenderlo oralmente sin experiencia previa en Laravel.

---

## 1. El problema de negocio, en una frase

Una cadena de gimnasios necesita registrar el paso físico de un usuario por el
torno (**check-in**) y, después, regalarle una frase motivacional obtenida de una
API externa. El **check-in no puede esperar nunca** a esa API de terceros: si
está lenta o caída, el torno tiene que seguir funcionando igual.

Esa única frase — *"el check-in no puede esperar a la frase"* — es la que
explica el 90% de las decisiones de este proyecto. Todo lo demás (eventos,
Outbox, colas, DLQ, CQRS) son herramientas para sostener esa frase bajo estrés.

---

## 2. Los dos dominios (Bounded Contexts)

```
┌─────────────────────────┐        evento         ┌─────────────────────────┐
│      AccessControl      │  ───────────────────►  │       Engagement        │
│  "registrar el acceso"  │   CheckedIn (AMQP)      │  "premiar con una frase"│
└─────────────────────────┘                        └─────────────────────────┘
        dueño de:                                          dueño de:
        - access_logs                                      - motivational_phrases
        - outbox                                           - dashboard_read_model
```

**Regla de oro, la que más pesa en la evaluación:** ningún archivo de
`AccessControl` importa una clase de `Engagement`, y viceversa. Se hablan
*exclusivamente* dejando un mensaje en RabbitMQ. Si en algún punto ves un
`use App\Engagement\...` dentro de `src/AccessControl/`, algo está mal diseñado.

Puedes comprobarlo tú mismo:

```bash
grep -r "App\\\\Engagement" src/AccessControl   # no debe devolver nada
grep -r "App\\\\AccessControl" src/Engagement    # no debe devolver nada
```

---

## 3. El flujo completo, paso a paso

```
① POST /api/check-in
     │
     ▼
② CheckInController         (src/AccessControl/Infrastructure/Http)
     │  valida el request, arma un Command, lo pasa al handler
     ▼
③ RegisterCheckInHandler     (src/AccessControl/Application/RegisterCheckIn)
     │  DB::transaction {
     │      INSERT access_logs        ← el hecho de negocio
     │      INSERT outbox (evento)    ← la intención de avisar a los demás
     │  }
     │  responde 201 AL INSTANTE. No llama a Engagement ni a ninguna API.
     ▼
④ (proceso aparte) OutboxRelayCommand   (src/Shared/Infrastructure/Outbox)
     │  cada segundo: lee outbox WHERE published_at IS NULL
     │  publica cada uno en RabbitMQ (exchange domain_events, routing key access.checked_in)
     │  marca published_at
     ▼
⑤ (proceso aparte) ConsumeEngagementCommand   (src/Engagement/Infrastructure/Console)
     │  está escuchando la cola engagement.assign_phrase
     │  al llegar un mensaje:
     │     - si ya lo procesó (mismo access_log_id), lo ignora (idempotencia)
     │     - si no: llama a AssignPhraseOnCheckInHandler
     ▼
⑥ AssignPhraseOnCheckInHandler   (src/Engagement/Application/AssignPhraseOnCheckIn)
     │  pide una frase a través del PUERTO QuoteProviderPort
     │  (la implementación real llama a dummyjson.com, pero el handler no lo sabe)
     │  guarda motivational_phrases
     │  proyecta dashboard_read_model  ← aquí se "aplana" el dato para lectura
     ▼
⑦ GET /api/dashboard/{userId}    (src/Engagement/Infrastructure/Http)
     lee directo de dashboard_read_model. Sin JOINs.
```

Este es el guion que debes poder repetir de memoria en la entrevista.

---

## 4. Por qué cada patrón está ahí (y qué pasaría sin él)

### 4.1 Eventos de dominio en vez de llamada directa

**Sin esto:** `RegisterCheckInHandler` llamaría directamente a algo de
Engagement, que a su vez llamaría a la API externa. Si esa API tarda 5
segundos, el usuario espera 5 segundos parado frente al torno. Inaceptable.

**Con esto:** `AccessControl` termina su trabajo publicando un evento y no le
importa quién lo escucha ni cuánto tarde en reaccionar. El acoplamiento pasa de
ser "en el tiempo" (sincrónico) a ser "en el contrato del mensaje" (asíncrono).

Archivo clave: [`CheckedIn.php`](src/AccessControl/Domain/CheckedIn.php) — el
evento viaja como JSON con campos primitivos (`userId`, `branchId`,
`checkedInAt`), nunca como un objeto PHP de `AccessControl`. Así Engagement no
necesita conocer ninguna clase del otro módulo, solo el **contrato** del mensaje.

### 4.2 Outbox — atomicidad del Command

**El problema que resuelve:** imagina que en vez de una transacción hubiera dos
pasos separados:
```php
$repository->save($checkIn);        // paso 1: éxito
$rabbitMq->publish($event);         // paso 2: el broker está caído, falla
```
El acceso queda guardado, pero el evento nunca sale. Es un **"acceso fantasma"**:
existe en la base de datos pero Engagement jamás se enterará. El usuario nunca
tendrá su frase y nadie sabrá por qué.

**La solución (Transactional Outbox):** en vez de publicar directo al broker, el
`EventBus` solo hace un `INSERT` en la tabla `outbox`, **dentro de la misma
transacción SQL** que el `INSERT` del acceso. PostgreSQL garantiza que ambos
`INSERT` se confirman juntos o ninguno lo hace (atomicidad ACID). Ya no hay forma
de que exista un acceso sin su evento pendiente.

Un proceso totalmente aparte —el `relay`— barre la tabla `outbox` cada segundo y
publica al broker lo que encuentra pendiente. Si el broker está caído, el relay
simplemente no logra publicar esa vuelta y **lo reintenta en la siguiente
iteración**; el evento no se pierde porque sigue ahí, guardado en la tabla.

Archivos: [`RegisterCheckInHandler.php`](src/AccessControl/Application/RegisterCheckIn/RegisterCheckInHandler.php) · [`OutboxEventBus.php`](src/Shared/Infrastructure/Outbox/OutboxEventBus.php) · [`OutboxRelayCommand.php`](src/Shared/Infrastructure/Outbox/OutboxRelayCommand.php)

**Lo verificamos en vivo:** hicimos un check-in, y en la tabla `outbox` quedó una
fila con `published_at` seteado tras unos milisegundos — prueba de que el relay
la recogió y publicó.

### 4.2.1 El hueco que quedaba (y que ya se cerró): idempotencia del cliente

El Outbox garantiza que el *servidor* nunca guarda un acceso sin su evento. Pero
queda un caso distinto: si el **cliente** (el torno) no recibe la respuesta 201
—por ejemplo, el proceso muere justo después del `commit`, o hay un timeout de
red— y reintenta el mismo check-in, sin ningún mecanismo adicional se crearía un
**segundo acceso duplicado**. El servidor hizo todo bien; el problema es que el
cliente no sabe si su request anterior tuvo éxito.

La solución: un header opcional `Idempotency-Key: <uuid>` que el cliente genera
una vez por intento lógico y reenvía tal cual si reintenta. El handler primero
busca si ya existe un acceso con esa clave (`findByIdempotencyKey`); si existe,
devuelve el mismo `access_log_id` sin duplicar nada ni volver a publicar el
evento. Bajo concurrencia real (dos requests casi simultáneos con la misma
clave), la restricción `UNIQUE` de PostgreSQL sobre `idempotency_key` es el
árbitro final: el `INSERT` que pierde la carrera lanza una violación de
unicidad que se traduce a la excepción de dominio `DuplicateIdempotencyKey`, y
el handler responde con el registro que sí ganó — no con un error.

El header es opcional a propósito: sin él, cada llamada crea un acceso nuevo
(comportamiento original). La responsabilidad de generarlo y reenviarlo en
reintentos es del cliente, no algo que el servidor pueda inventar por su cuenta.

Archivos: [`RegisterCheckInHandler.php`](src/AccessControl/Application/RegisterCheckIn/RegisterCheckInHandler.php) ·
[`DuplicateIdempotencyKey.php`](src/AccessControl/Domain/DuplicateIdempotencyKey.php) ·
[`EloquentAccessLogRepository.php`](src/AccessControl/Infrastructure/Persistence/EloquentAccessLogRepository.php) ·
tests en [`RegisterCheckInHandlerTest.php`](tests/Unit/RegisterCheckInHandlerTest.php)
(incluye la condición de carrera, simulada con un doble en memoria).

**Lo probamos en vivo:** dos requests HTTP consecutivos con el mismo
`Idempotency-Key` devolvieron el mismo `access_log_id`, y la tabla `access_logs`
solo tenía una fila para esa clave.

### 4.3 DIP + Anti-Corruption Layer (ACL) — el punto que más preguntan

**El problema:** el dominio de Engagement necesita el concepto de "frase". Pero
si escribes directo:
```php
$response = Http::get('https://dummyjson.com/quotes/random');
$text = $response->json()['quote'];
```
...tu caso de uso ahora sabe qué es HTTP, qué es JSON, y conoce el nombre exacto
del campo `quote` de un tercero. Si dummyjson cambia su API mañana, tienes que
tocar tu lógica de negocio. Peor: no puedes testear tu caso de uso sin hacer una
llamada de red real.

**La solución — invertir la dependencia:** el dominio define una **interfaz**
(el "puerto"):
```php
interface QuoteProviderPort {
    public function fetchRandom(): Quote;   // Quote es un Value Object propio
}
```
El dominio depende de esta interfaz, no de HTTP. La implementación real
([`DummyJsonQuoteProvider`](src/Engagement/Infrastructure/Http/DummyJsonQuoteProvider.php))
vive en la capa de infraestructura y es la única pieza del sistema que sabe que
existe dummyjson, que responde JSON, y qué forma tiene ese JSON. Si el contrato
JSON cambia (falta un campo, cambia un nombre), este adaptador lo detecta y
lanza `QuoteUnavailable` — una excepción **del dominio**, no un error de HTTP.

Este patrón se llama **Anti-Corruption Layer**: una capa que traduce el "idioma"
de un sistema externo al "idioma" de tu dominio, para que lo externo nunca
"corrompa" tus modelos internos.

¿Dónde se conecta la interfaz con la implementación? En
[`DomainServiceProvider.php`](src/Shared/Infrastructure/DomainServiceProvider.php):
```php
$this->app->bind(QuoteProviderPort::class, function ($app) {
    return new DummyJsonQuoteProvider(...);
});
```
Esa única línea es todo lo que habría que cambiar para usar otra API de frases,
o un doble de prueba, sin tocar el dominio.

**Lo probamos en vivo:** apuntamos `QUOTES_API_URL` a un dominio inexistente y
vimos exactamente esto: 3 reintentos con backoff y luego el mensaje fue a la
Dead Letter Queue — sin que el check-in se enterara de nada.

### 4.4 Reintentos + Dead Letter Queue (DLX) — consistencia eventual

RabbitMQ permite declarar, en la cola, un **Dead Letter Exchange**: si un mensaje
se rechaza (`nack`) sin pedir reencolarlo, RabbitMQ lo reenruta automáticamente a
otra cola ("cola de mensajes muertos") en vez de perderlo o bloquear la cola
principal.

El consumidor ([`ConsumeEngagementCommand.php`](src/Engagement/Infrastructure/Console/ConsumeEngagementCommand.php))
implementa esta lógica:
1. Si el caso de uso lanza una excepción (p. ej. `QuoteUnavailable`), mira un
   header `x-retries` del mensaje.
2. Si no ha alcanzado el máximo (3, configurable), **re-publica** el mismo
   mensaje con el contador incrementado y hace `ack` del original — es un
   reintento manual con backoff (`usleep`).
3. Si ya agotó los reintentos, hace `nack(requeue: false)` — RabbitMQ, gracias a
   la topología declarada en
   [`RabbitMqConnection.php`](src/Shared/Infrastructure/RabbitMq/RabbitMqConnection.php),
   manda ese mensaje a `engagement.assign_phrase.dlq`.

Esto es **consistencia eventual**: el sistema acepta que, por un rato, un acceso
puede no tener su frase asignada, a cambio de nunca bloquear ni perder nada.

### 4.5 CQRS — separar cómo escribo de cómo leo

**El problema que resuelve:** si el endpoint del dashboard hiciera
```sql
SELECT * FROM access_logs JOIN motivational_phrases ON ...
```
en cada request, cada lectura pagaría el costo de una unión entre dos tablas
"vivas" del negocio. Con miles de accesos por día, ese JOIN se vuelve caro y el
endpoint de lectura queda acoplado al esquema interno de escritura.

**La solución:** cuando el worker procesa el evento, además de guardar la frase
en su tabla normal (`motivational_phrases`), **también escribe** una fila ya
combinada (acceso + frase) en `dashboard_read_model`
([`EloquentDashboardProjectionRepository.php`](src/Engagement/Infrastructure/Persistence/EloquentDashboardProjectionRepository.php)).
El endpoint de lectura ([`DashboardController.php`](src/Engagement/Infrastructure/Http/DashboardController.php))
hace un `SELECT` plano sobre esa tabla — sin JOINs, O(1) por fila.

El "costo" de armar el dato se paga **una vez, al escribir** (de forma
asíncrona, sin apuro), en vez de pagarlo **en cada lectura**.

**Lo vimos en vivo:** la respuesta de `/api/dashboard/{userId}` ya trae
`quote.text` y `quote.author` mezclados con el acceso, sin que el controlador
haga ningún join.

### 4.6 Idempotencia

RabbitMQ (y en general cualquier broker) da la garantía **at-least-once**: un
mensaje puede entregarse más de una vez (por ejemplo, si el relay publica pero
el proceso muere antes de marcar `published_at`, lo volverá a publicar). Por eso
el handler de Engagement chequea primero si ya existe una frase para ese
`access_log_id` — si sí, no hace nada. Es la diferencia entre "se entrega al
menos una vez" y "se procesa exactamente una vez": el broker garantiza lo
primero, tu código garantiza lo segundo comprobando antes de actuar.

---

## 5. Estructura del repositorio — por qué no es MVC

Laravel por defecto organiza el código por **tipo técnico** (`Controllers/`,
`Models/`, `Requests/`...). Este proyecto lo organiza por **dominio y capa**
(Arquitectura Hexagonal / Clean Architecture):

```
src/
├── Shared/                          # plumbing técnico sin lógica de negocio
│   ├── Domain/                      # DomainEvent (base), EventBus (puerto), TransactionManager (puerto)
│   └── Infrastructure/
│       ├── DomainServiceProvider.php  # conecta TODOS los puertos con sus adaptadores
│       ├── Outbox/                    # OutboxEventBus, OutboxModel, OutboxRelayCommand
│       ├── RabbitMq/                  # conexión AMQP + topología (exchanges/colas/DLX)
│       └── Database/                  # DbTransactionManager (adaptador de DB::transaction())
│
├── AccessControl/
│   ├── Domain/            # CheckIn (entidad), CheckedIn (evento), AccessLogRepository (puerto),
│   │                      # DuplicateIdempotencyKey (excepción de dominio)
│   ├── Application/       # RegisterCheckInCommand + Handler  ← LA RUTA CRÍTICA
│   └── Infrastructure/    # CheckInController, EloquentAccessLogRepository
│
└── Engagement/
    ├── Domain/
    │   ├── Quote/          # Quote (VO), QuoteProviderPort (puerto), QuoteUnavailable
    │   └── Phrase/         # MotivationalPhrase, sus 2 puertos (escritura y lectura)
    ├── Application/        # AssignPhraseOnCheckInHandler (el "consumidor lógico")
    └── Infrastructure/
        ├── Http/           # DummyJsonQuoteProvider (el ACL), DashboardController
        ├── Persistence/    # modelos Eloquent + adaptadores de los puertos
        └── Console/        # ConsumeEngagementCommand (el worker)
```

**Regla de dependencias:** `Infrastructure → Application → Domain`. El `Domain`
no importa nada de Laravel ni de ninguna librería externa — puedes leer
`CheckIn.php` o `Quote.php` y no vas a encontrar ni un `use Illuminate\...`
(salvo el helper `Str::uuid()`, una concesión práctica). Eso es lo que hace al
dominio testeable sin arrancar el framework completo (ver los tests unitarios).

---

## 6. Vocabulario de Laravel que necesitas para defender esto

Como conoces PHP pero no Laravel, esto es lo mínimo que te van a dar por hecho:

- **Service Provider** — una clase donde le dices al framework "cuando alguien
  pida tal interfaz, dale tal implementación concreta". Es el lugar donde se
  cablea la Inversión de Dependencias. Aquí es
  [`DomainServiceProvider`](src/Shared/Infrastructure/DomainServiceProvider.php).
- **Contenedor de Inyección de Dependencias (IoC container)** — Laravel resuelve
  automáticamente los argumentos del constructor de cualquier clase que
  instancie él (controladores, comandos). Por eso `RegisterCheckInHandler` solo
  declara `AccessLogRepository $repository` en su constructor, y el framework le
  entrega la implementación correcta sin que nadie escriba `new`.
- **Eloquent** — el ORM de Laravel (equivalente conceptual a Doctrine). Cada
  `...Model.php` en `Infrastructure/Persistence` es una clase Eloquent — pura
  infraestructura, el dominio nunca la ve directamente.
- **Migraciones** — scripts versionados que crean/alteran tablas
  (`database/migrations/*.php`). Se ejecutan con `php artisan migrate`. Cada uno
  representa un cambio de esquema con su fecha en el nombre.
- **Artisan** — la CLI de Laravel (`php artisan <comando>`). Los procesos
  `worker` y `relay` de este proyecto son justamente comandos Artisan
  personalizados que corren en bucle infinito.
- **Facade / `Http::` client** — Laravel envuelve `Guzzle` (cliente HTTP) detrás
  de una fachada simple. `DummyJsonQuoteProvider` la usa vía inyección de
  `Illuminate\Http\Client\Factory`, no la fachada estática, precisamente para
  poder sustituirla en tests con `Http::fake()`.
- **`config/*.php`** — configuración leída desde variables de entorno (`.env`)
  con valores por defecto. Cada archivo agrupa configuración de un tema
  (`database.php`, `services.php`, `rabbitmq.php` es uno propio de este
  proyecto).

---

## 7. Infraestructura (`docker-compose.yml`)

| Servicio   | Imagen / Build            | Rol                                                          |
|------------|----------------------------|---------------------------------------------------------------|
| `nginx`    | `nginx:1.27-alpine`         | Recibe HTTP en :8080 y reenvía a `app` por FastCGI            |
| `app`      | `docker/php/Dockerfile`     | PHP-FPM: procesa las requests de Laravel; corre las migraciones al arrancar |
| `postgres` | `postgres:16-alpine`        | Base de datos: `access_logs`, `outbox`, `motivational_phrases`, `dashboard_read_model` |
| `rabbitmq` | `rabbitmq:3.13-management`  | Broker de eventos + panel web en :15672                       |
| `worker`   | mismo build que `app`       | Ejecuta `php artisan engagement:consume` en bucle              |
| `relay`    | mismo build que `app`       | Ejecuta `php artisan outbox:relay` en bucle                     |

`worker` y `relay` comparten la imagen de `app` (mismo código), solo cambia el
comando (`command:` en el compose). Es el mismo monolito desplegado tres veces
con distinto punto de entrada — **no son microservicios**, siguen siendo el
mismo código fuente, solo se ejecutan como procesos separados porque uno debe
correr sin parar escuchando la cola, y no tendría sentido meterlo dentro del
proceso que atiende HTTP.

El [`entrypoint.sh`](docker/php/entrypoint.sh) hace, en orden: instala
dependencias si falta `vendor/`, genera `.env`/`APP_KEY` si faltan, espera a que
PostgreSQL acepte conexiones, corre migraciones (solo el contenedor `app`, para
no correr la migración 3 veces en paralelo) y finalmente ejecuta el comando real.

---

## 8. Cómo levantarlo y probarlo

```bash
docker compose up --build -d
```

```bash
# 1. Check-in (síncrono, responde al instante)
curl -X POST http://localhost:8080/api/check-in \
  -H "Content-Type: application/json" \
  -d '{"user_id":"11111111-1111-1111-1111-111111111111","branch_id":"22222222-2222-2222-2222-222222222222"}'

# 2. Dashboard (unos segundos después, ya proyectado)
curl http://localhost:8080/api/dashboard/11111111-1111-1111-1111-111111111111
```

- Panel de RabbitMQ: http://localhost:15672 (guest/guest) — para ver las colas,
  sus mensajes y la DLQ en vivo.
- Tests: `docker compose exec app ./vendor/bin/phpunit --testdox`

### Escenarios de resiliencia para demostrar en vivo

| Qué simular | Cómo | Qué deberías ver |
|---|---|---|
| API externa caída | Cambiar `QUOTES_API_URL` en `.env` del contenedor `app`/`worker` a una URL inválida y `docker compose restart worker` | El check-in sigue respondiendo 201. En los logs del worker: 3 reintentos y luego "Enviando a DLQ". La cola `engagement.assign_phrase.dlq` muestra 1 mensaje en el panel de RabbitMQ. `access_logs` conserva el registro intacto. |
| Broker caído | `docker compose stop rabbitmq`, hacer un check-in, luego `docker compose start rabbitmq` | El check-in responde 201 igual (el Outbox no depende del broker). La fila en `outbox` queda con `published_at = NULL` hasta que el relay puede publicarla al volver RabbitMQ. |
| Worker caído por horas | `docker compose stop worker`, hacer varios check-ins, luego `docker compose start worker` | Los eventos se acumulan en la cola `engagement.assign_phrase` (durable, no se pierden). Al reactivar el worker, los procesa todos en orden. |

> Todas las filas de esta tabla fueron efectivamente probadas al construir este
> proyecto — no son solo hipótesis de diseño.

---

## 9. Dónde está resuelto cada criterio de la rúbrica

| Criterio | Archivo(s) |
|---|---|
| **No acoplamiento inter-modular** (fallo crítico si se viola) | [`CheckedIn.php`](src/AccessControl/Domain/CheckedIn.php) (evento publicado) + [`ConsumeEngagementCommand.php`](src/Engagement/Infrastructure/Console/ConsumeEngagementCommand.php) (consumido por primitivas JSON, sin importar clases de AccessControl) |
| **DIP + Anti-Corruption Layer** | [`QuoteProviderPort.php`](src/Engagement/Domain/Quote/QuoteProviderPort.php) (puerto) + [`DummyJsonQuoteProvider.php`](src/Engagement/Infrastructure/Http/DummyJsonQuoteProvider.php) (adaptador) |
| **Atomicidad — patrón Outbox** | [`RegisterCheckInHandler.php`](src/AccessControl/Application/RegisterCheckIn/RegisterCheckInHandler.php) + [`OutboxEventBus.php`](src/Shared/Infrastructure/Outbox/OutboxEventBus.php) + [`OutboxRelayCommand.php`](src/Shared/Infrastructure/Outbox/OutboxRelayCommand.php) |
| **Consistencia eventual / reintentos / DLX** | [`RabbitMqConnection.php`](src/Shared/Infrastructure/RabbitMq/RabbitMqConnection.php) (declara la topología con DLX) + [`ConsumeEngagementCommand.php`](src/Engagement/Infrastructure/Console/ConsumeEngagementCommand.php) (lógica de reintento) |
| **CQRS** | [`EloquentDashboardProjectionRepository.php`](src/Engagement/Infrastructure/Persistence/EloquentDashboardProjectionRepository.php) (proyector, escribe) + [`DashboardController.php`](src/Engagement/Infrastructure/Http/DashboardController.php) (lee sin JOINs) |
| **Organización Hexagonal, no MVC** | Todo `src/` — ver sección 5 |
| **Tests del adaptador externo + DIP** | [`tests/Unit/DummyJsonQuoteProviderTest.php`](tests/Unit/DummyJsonQuoteProviderTest.php) y [`tests/Unit/AssignPhraseOnCheckInHandlerTest.php`](tests/Unit/AssignPhraseOnCheckInHandlerTest.php) |
| **Idempotencia del cliente (reintentos seguros)** | Sección 4.2.1 · [`RegisterCheckInHandler.php`](src/AccessControl/Application/RegisterCheckIn/RegisterCheckInHandler.php) + [`tests/Unit/RegisterCheckInHandlerTest.php`](tests/Unit/RegisterCheckInHandlerTest.php) |

---

## 10. Decisión de diseño discutible: reintento vs. fallback

Ante un fallo de la API externa, el caso de uso ([`AssignPhraseOnCheckInHandler.php`](src/Engagement/Application/AssignPhraseOnCheckIn/AssignPhraseOnCheckInHandler.php))
**propaga** la excepción `QuoteUnavailable` para que el consumidor la reintente
y, si persiste, la mande a la DLQ. Es la opción que mejor demuestra reintentos y
DLX, que es justo lo que pide la rúbrica explícitamente.

La alternativa de producto sería usar
[`Quote::fallback()`](src/Engagement/Domain/Quote/Quote.php) para asignar una
frase por defecto de inmediato y no reintentar — el dashboard nunca queda
"pendiente", pero se pierde la demostración de resiliencia. Es una decisión de
negocio (¿prefieres degradar silenciosamente o encolar y reintentar?), no una
limitación técnica: el código deja ambos caminos servidos (el método
`fallback()` ya existe y está probado).

---

## 11. Preguntas típicas de la defensa oral (con la respuesta corta)

- **¿Por qué monolito y no microservicios, si hay eventos y colas de por
  medio?** Porque siguen siendo un solo repositorio, un solo `composer.json`, un
  solo ciclo de despliegue. Los eventos son un patrón de **comunicación interna
  desacoplada**, no una frontera de despliegue. Eso es justamente lo que pide el
  enunciado ("prohibido separar en microservicios").
- **¿Qué pasa si el evento se entrega dos veces?** El handler de Engagement
  comprueba `existsForAccessLog` antes de procesar — es idempotente.
- **¿Por qué Outbox y no publicar directo tras el `save()`?** Porque entre
  guardar y publicar puede caerse el broker; el Outbox los ata a la misma
  transacción SQL, eliminando la ventana de inconsistencia.
- **¿Cómo migrarías esto a microservicios reales el día de mañana?** Cada
  módulo ya tiene sus fronteras de datos y solo se comunica por eventos —
  "cortar" en la frontera del broker y separar el repositorio sería el único
  cambio estructural grande.
- **¿Por qué RabbitMQ y no las colas nativas de Laravel?** Porque necesito
  exchanges, routing keys y Dead Letter Exchange explícitos — vocabulario y
  mecanismos que la rúbrica pide por nombre.
- **¿Qué pasa si el torno reintenta el mismo check-in porque no recibió
  respuesta?** Si envía el mismo header `Idempotency-Key`, el handler detecta
  que ya existe ese acceso y devuelve el mismo `access_log_id` sin duplicar
  nada — incluso si dos reintentos llegan casi al mismo tiempo, la restricción
  `UNIQUE` de la base de datos resuelve la carrera (sección 4.2.1).
- **¿Qué garantiza que un acceso nunca se pierde aunque todo lo demás falle?**
  El `INSERT` en `access_logs` ocurre siempre, sin importar qué pase después: es
  lo primero (y, junto al outbox, lo único síncrono) de la transacción.
