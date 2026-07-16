# Arquitectura — Monolito Modular: Control de Acceso y Engagement

> Sistema de operativa para una cadena de gimnasios. Dos dominios conviven en un
> mismo repositorio y un mismo proceso de despliegue (**monolito modular**), pero
> mantienen fronteras lógicas estrictas y se comunican **exclusivamente** mediante
> eventos de dominio a través de un broker (RabbitMQ).

---

## 1. Mapa de Contextos Limitados (Bounded Contexts)

El sistema se divide en dos contextos de negocio y un módulo transversal técnico.

```
┌──────────────────────────────────────────────────────────────────────┐
│                        MONOLITO MODULAR (1 repo, 1 deploy)             │
│                                                                        │
│  ┌───────────────────────────┐        ┌───────────────────────────┐   │
│  │      AccessControl        │        │        Engagement         │   │
│  │  (Control de acceso)      │        │  (Fidelización)           │   │
│  │                           │        │                           │   │
│  │  Upstream / Publica       │        │  Downstream / Reacciona   │   │
│  │  el evento CheckedIn      │        │  al evento CheckedIn      │   │
│  └────────────┬──────────────┘        └─────────────▲─────────────┘   │
│               │                                     │                 │
│               │   evento de dominio (broker)        │                 │
│               └──────────────►  RabbitMQ  ──────────┘                 │
│                                                                        │
│  ┌──────────────────────────────────────────────────────────────┐    │
│  │  Shared Kernel (técnico): EventBus, DomainEvent, Outbox       │    │
│  └──────────────────────────────────────────────────────────────┘    │
└──────────────────────────────────────────────────────────────────────┘
```

### 1.1 Contexto `AccessControl` (upstream)

- **Responsabilidad:** registrar el paso físico del usuario por el torno (Check-in).
  Operación **transaccional, síncrona y con prioridad absoluta**.
- **Propiedad de los datos (data ownership):**
  - `access_logs` — log transaccional de accesos (**dueño exclusivo**).
  - `outbox` — bandeja de salida de eventos (técnica, ver §4).
- **No conoce** la existencia de "frases", de API externas ni del módulo Engagement.
  Su única obligación hacia el exterior es **emitir el evento** `CheckedIn`.

### 1.2 Contexto `Engagement` (downstream)

- **Responsabilidad:** asignar la "frase motivacional del día" tras un check-in y
  mantener el modelo de lectura del dashboard.
- **Propiedad de los datos (data ownership):**
  - `motivational_phrases` — frases asignadas por usuario (**dueño exclusivo**).
  - `dashboard_read_model` — proyección desnormalizada para lectura (§5).
- **No conoce** la mecánica del torno ni la tabla `access_logs`. Solo consume el
  evento `CheckedIn` que llega por el broker.

### 1.3 `Shared Kernel` (módulo técnico transversal)

Contratos e infraestructura genérica sin lógica de negocio: interfaz `EventBus`,
clase base `DomainEvent`, y el mecanismo `Outbox`. No es un bounded context de
negocio; existe para no duplicar plumbing entre módulos.

> **Regla de dependencia inviolable:** `AccessControl` y `Engagement` pueden
> depender de `Shared`, pero **nunca uno del otro**. Ningún `use` de un namespace
> cruza de un dominio al otro.

---

## 2. Patrón de Comunicación Inter-modular

**Coreografía basada en eventos sobre un broker (RabbitMQ). No hay llamadas
directas entre módulos.**

### 2.1 Por qué eventos y no una llamada directa

El check-in tiene prioridad absoluta y no tolera latencia. Si `AccessControl`
llamara directamente (aunque fuera a un servicio interno) a `Engagement`, y este a
la API externa, la latencia o caída de un tercero **contaminaría** la ruta crítica.
Al desacoplar con eventos, `AccessControl` termina su trabajo y responde de
inmediato; lo demás ocurre de forma asíncrona y eventual.

### 2.2 Contrato del evento (`CheckedIn`)

El evento viaja serializado como **primitivas** (no objetos de dominio de otro
módulo), evitando acoplamiento de tipos:

```jsonc
{
  "eventId": "uuid",
  "eventName": "access.checked_in",
  "aggregateId": "uuid",        // = access_log_id
  "occurredOn": "2026-07-16T12:00:00Z",
  "payload": {
    "userId": "uuid",
    "branchId": "uuid",
    "checkedInAt": "2026-07-16T12:00:00Z"
  }
}
```

> Nota de implementación: el evento se serializa con un sobre (`eventId`,
> `eventName`, `aggregateId`, `occurredOn`) y el cuerpo específico del evento
> anidado en `payload`. Esto permite que el consumidor deduplique por
> `eventId`/`aggregateId` sin tener que abrir el payload primero.

### 2.3 Topología en RabbitMQ

```
exchange:  domain_events        (type: topic, durable)
routing:   access.checked_in    → cola  engagement.assign_phrase
```

- **Publicador:** relay del Outbox de `AccessControl` (§4).
- **Consumidor:** worker de `Engagement`, bindeado a `access.checked_in`.
- Añadir nuevos reactores (p. ej. notificaciones) = bindear una cola nueva a la
  misma routing key, **sin tocar** `AccessControl`.

---

## 3. Estrategia de Integración y Aislamiento contra la API externa (Anti-Corruption Layer)

La API `https://dummyjson.com/quotes/random` es un tercero volátil: puede cambiar
su contrato JSON, devolver errores 5xx o colgarse. El dominio de `Engagement` debe
**ignorar por completo** que existe HTTP o JSON.

### 3.1 Puerto en el dominio (Dependency Inversion)

```php
// Engagement/Domain/Quote/QuoteProviderPort.php
namespace App\Engagement\Domain\Quote;

interface QuoteProviderPort
{
    /** @throws QuoteUnavailable si no se pudo obtener una frase válida. */
    public function fetchRandom(): Quote;   // Quote = Value Object del dominio
}
```

- `Quote` es un **Value Object** del dominio (`text`, `author`). No sabe de HTTP.
- El dominio depende de la **interfaz**, nunca de Guzzle ni del `Http` facade.

### 3.2 Adaptador en infraestructura (ACL real)

```php
// Engagement/Infrastructure/Http/DummyJsonQuoteProvider.php
final class DummyJsonQuoteProvider implements QuoteProviderPort
{
    // - Timeout corto y explícito.
    // - Traduce el JSON del tercero → Value Object Quote (traducción de modelos).
    // - Valida el contrato: si falta 'quote'/'author', lanza QuoteUnavailable
    //   en vez de propagar un shape ajeno hacia el dominio.
    // - Encapsula Guzzle/Http; nada de esto escapa de esta clase.
}
```

El binding puerto→adaptador se registra en un Service Provider. Cambiar de
proveedor (otra API, un stub) = una línea en el contenedor de dependencias.

### 3.3 Tolerancia a fallos en la frontera

- **Timeout** explícito para no heredar la latencia del tercero.
- Ante error/timeout/contrato inválido, el adaptador lanza `QuoteUnavailable`; el
  caso de uso decide: **reintentar** (vía broker) o aplicar **fallback** (frase por
  defecto) para que el usuario siempre tenga dashboard. La decisión vive en
  `Application`, no en el adaptador.

---

## 4. Atomicidad del Command y el patrón Outbox

**Problema:** si el handler guarda el acceso y luego publica el evento en dos pasos
separados, una micro-caída del broker entre ambos produce **accesos fantasma**
(registrados pero sin evento) — o, al revés, eventos sin acceso.

**Solución — Transactional Outbox:**

```
RegisterCheckInHandler:
  DB::transaction:
     1. INSERT access_logs        (hecho de negocio)
     2. INSERT outbox (CheckedIn) (intención de publicar)
  commit  ← ambos o ninguno (misma transacción ACID de PostgreSQL)

Relay (proceso aparte, "outbox:publish"):
  lee filas outbox no publicadas → publica en RabbitMQ → marca published_at
```

- El acceso físico y el registro del evento son **atómicos**: nunca hay acceso sin
  su evento pendiente.
- El check-in **no depende de la disponibilidad del broker** para responder: si
  RabbitMQ está caído, el evento queda persistido en `outbox` y se publicará en
  cuanto el relay pueda. La ruta crítica sigue en alta disponibilidad.
- El relay es idempotente/at-least-once; el consumidor deduplica por `eventId`.

### 4.1 Idempotencia del lado del CLIENTE (header `Idempotency-Key`)

La atomicidad de arriba resuelve la consistencia **dentro** del servidor, pero
queda un caso: ¿qué pasa si el servidor confirma el commit pero el cliente (el
torno) nunca recibe la respuesta 201 — por un timeout de red, por ejemplo — y
reintenta el mismo check-in?

Sin ningún mecanismo adicional, ese reintento crearía un **segundo acceso
físico duplicado**, porque `RegisterCheckInHandler` no tiene forma de saber que
ya procesó esa misma intención antes.

**Solución:** el cliente puede enviar un header `Idempotency-Key: <uuid>` — una
clave que él mismo genera una vez por intento lógico de check-in (no por
request HTTP: la reutiliza si reintenta). El handler:

1. Busca si ya existe un `access_log` con esa clave (`findByIdempotencyKey`).
   Si existe, devuelve ese mismo `access_log_id` sin crear nada nuevo ni volver
   a publicar el evento.
2. Si no existe, procede a crear el acceso normalmente, guardando la clave.
3. Bajo **concurrencia** (dos requests con la misma clave llegan casi al mismo
   tiempo, ambos pasan el paso 1 antes de que cualquiera confirme su INSERT), la
   restricción `UNIQUE` de PostgreSQL sobre `idempotency_key` es el árbitro
   final: el `INSERT` perdedor lanza una violación de unicidad, que el
   adaptador traduce a la excepción de dominio `DuplicateIdempotencyKey`; el
   handler la captura y devuelve el registro que sí ganó la carrera.

El header es **opcional**: sin él, el endpoint se comporta como antes (cada
llamada crea un acceso nuevo) — la responsabilidad de generarlo y reenviarlo en
reintentos es del cliente (el firmware del torno), no del servidor.

Archivos: [`RegisterCheckInHandler.php`](src/AccessControl/Application/RegisterCheckIn/RegisterCheckInHandler.php) ·
[`DuplicateIdempotencyKey.php`](src/AccessControl/Domain/DuplicateIdempotencyKey.php) ·
[`EloquentAccessLogRepository.php`](src/AccessControl/Infrastructure/Persistence/EloquentAccessLogRepository.php) ·
migración [`..._add_idempotency_key_to_access_logs_table.php`](database/migrations/2026_01_01_000005_add_idempotency_key_to_access_logs_table.php).

---

## 5. Segregación de Modelos: CQRS (escritura vs. lectura)

### 5.1 Modelo de escritura (normalizado)

- `access_logs` (dueño: AccessControl).
- `motivational_phrases` (dueño: Engagement).

Optimizados para consistencia e integridad transaccional.

### 5.2 Modelo de lectura (desnormalizado)

- `dashboard_read_model`: una fila por acceso ya combinada con su frase.

```
dashboard_read_model
──────────────────────────────────────────────────────────────
access_log_id (PK) | user_id | checked_in_at | quote_text | quote_author
```

> `access_log_id` es la clave primaria (permite `updateOrCreate` idempotente en
> la proyección). No se desnormaliza `branch_id`: el dashboard de esta prueba
> solo necesita usuario + fecha + frase; agregar la sucursal sería una columna
> más en la misma proyección si el negocio lo pidiera.

### 5.3 Proyección

El consumidor asíncrono de `Engagement`, tras obtener la frase, **proyecta** el
dato ya combinado en `dashboard_read_model`. Así:

- El endpoint `GET /dashboard/{userId}` hace un **SELECT directo** sobre una tabla
  desnormalizada — lectura **O(1)** por fila, **sin JOINs** en runtime entre el log
  de accesos y las frases.
- La consistencia es **eventual**: puede existir una ventana breve donde el acceso
  ya está registrado pero la frase aún no proyectada (API externa lenta). Es
  aceptable por diseño y no bloquea nada.

---

## 6. Resiliencia y Consistencia Eventual (escenarios de estrés)

| Escenario | Comportamiento del sistema |
|---|---|
| **API externa devuelve 500 sostenido** | El consumidor falla al obtener la frase → reintentos con backoff → tras N intentos, el mensaje va a la **Dead Letter Exchange** (`domain_events.dlx` → cola `engagement.assign_phrase.dlq`). El check-in **ya está registrado**; solo se retrasa/omite la frase. (`Quote::fallback()` existe como Value Object por defecto y está cubierto por tests, pero **no está conectado** en el flujo actual — hoy se prioriza demostrar reintentos + DLQ en vez de degradar silenciosamente; ver discusión en README §10). |
| **Worker de Engagement caído por horas** | Los eventos se **acumulan en la cola** (durable) de RabbitMQ. Al reponerse el worker, los procesa. Nada se pierde; el check-in nunca se vio afectado. |
| **Broker (RabbitMQ) caído en el instante del check-in** | El evento queda en la tabla `outbox` (§4). El relay lo publica cuando el broker vuelve. **Cero accesos fantasma.** |
| **Cambio de contrato JSON del tercero** | El ACL (§3) valida el shape y lanza `QuoteUnavailable`; el shape ajeno **nunca** entra al dominio. Mismo camino de reintento/DLQ/fallback. |
| **Mensaje duplicado (at-least-once)** | El consumidor deduplica por `eventId` antes de proyectar. |

**Topología de resiliencia en RabbitMQ:**

```
domain_events (topic) ──access.checked_in──► engagement.assign_phrase
                                                    │  (x-dead-letter-exchange)
                                                    ▼
                                           domain_events.dlx ──► engagement.assign_phrase.dlq
```

---

## 7. Organización del Repositorio (Clean / Hexagonal)

Se abandona la estructura MVC por defecto de Laravel. El directorio `src/` refleja
**dominio → capa**:

```
src/
├── Shared/
│   ├── Domain/                 # DomainEvent (base), EventBus (interfaz/puerto)
│   └── Infrastructure/
│       ├── DomainServiceProvider   # cablea TODOS los puertos con sus adaptadores
│       ├── Outbox/                 # OutboxEventBus (implementa EventBus), OutboxModel, OutboxRelayCommand
│       └── RabbitMq/                # RabbitMqConnection (topología+DLX), RabbitMqPublisher
│
├── AccessControl/
│   ├── Domain/                 # CheckIn (entidad), CheckedIn (evento), AccessLogRepository (puerto)
│   ├── Application/            # RegisterCheckInCommand + Handler
│   └── Infrastructure/         # CheckInController, EloquentAccessLogRepository
│
└── Engagement/
    ├── Domain/                 # Quote (VO), QuoteProviderPort (puerto), MotivationalPhrase,
    │                           # sus 2 puertos de persistencia (escritura y lectura), QuoteUnavailable
    ├── Application/            # AssignPhraseOnCheckInHandler (el "consumidor lógico": pide la
    │                           # frase, persiste y proyecta al read model — sin clase separada
    │                           # "DashboardProjector"; la proyección es una llamada al puerto
    │                           # DashboardProjectionRepository dentro de este mismo handler)
    └── Infrastructure/         # DummyJsonQuoteProvider (ACL), ConsumeEngagementCommand (worker),
                                # DashboardController, repos Eloquent (incl. EloquentDashboardProjectionRepository)
```

- **Dirección de dependencias:** `Infrastructure → Application → Domain`. El dominio
  no depende de nada externo (ni de Laravel).
- Autoload PSR-4: `"App\\": "src/"` en `composer.json`.

---

## 8. Stack e Infraestructura

| Componente | Elección | Motivo |
|---|---|---|
| Runtime | PHP 8.3 / Laravel 11 | Requerido. |
| Base de datos | **PostgreSQL** | Transaccionalidad sólida para el Outbox. |
| Broker | **RabbitMQ** | Soporte de primera clase para exchanges, routing y **DLX**. |
| Contenedores | `docker-compose` | `app` (php-fpm) · `nginx` · `postgres` · `rabbitmq` · `worker` (consumidor) · `relay` (outbox). |

---

## 9. Ruta crítica end-to-end (resumen)

```
POST /check-in
   └─► RegisterCheckInHandler
         └─ tx: INSERT access_logs + INSERT outbox(CheckedIn)   ── responde 201 (síncrono, rápido)

OutboxRelay  ──publica──►  RabbitMQ (domain_events / access.checked_in)

Worker Engagement (AssignPhraseOnCheckInHandler, disparado por ConsumeEngagementCommand)
   ├─ QuoteProviderPort.fetchRandom()   (ACL, con timeout/retry/DLQ)
   ├─ persiste MotivationalPhrase
   └─ DashboardProjectionRepository.project() → dashboard_read_model

GET /dashboard/{userId}  ──►  SELECT directo sobre dashboard_read_model (O(1), sin JOINs)
```
