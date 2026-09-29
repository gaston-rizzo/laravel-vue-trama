# Moderación automática de comentarios — TRAMA

## Arquitectura final

Las rutas de código indicadas en este documento son relativas a `src/`, que es la
raíz de la aplicación Laravel dentro del repositorio.

La moderación automática de comentarios sigue el mismo patrón general que los
procesos Node utilizados por TRAMA para imágenes: Laravel ejecuta directamente un
archivo `.mjs` mediante `Process::run()` y consume el JSON producido por stdout.

No existe servidor HTTP local, host, puerto ni token de comunicación para esta
funcionalidad.

```text
Usuario registrado envía comentario o respuesta
        |
        v
Laravel guarda la fila en comments
status = processing
moderation_revision = 1
moderation_source = automatic
moderation_reason = automation_queued
        |
        v
Laravel responde inmediatamente:
"Comentario recibido.
Estamos revisándolo antes de publicarlo."
        |
        v
ModerateComment queda en Queue
        |
        v
worker de Laravel toma el Job
        |
        v
Process::run()
        |
        v
node scripts/comments/moderate-comment.mjs
        |
        +--> detect-comment-language.mjs
        +--> detect-comment-links.mjs
        +--> detect-comment-threats.mjs
        +--> classify-comment-toxicity.mjs
        +--> classify-comment-spam.mjs
        |
        v
JSON por stdout
        |
        v
ModerateComment valida y actualiza la MISMA fila
        |
        v
approved / pending / rejected
        |
        v
Vue consulta estado-procesamiento y actualiza la interfaz
```

Las respuestas realizadas por cuentas internas autorizadas de TRAMA no recorren
este pipeline. El periodista puede responder como **Autor de la nota** dentro de
sus propias publicaciones y el editor puede responder como **Equipo TRAMA**;
esas participaciones se guardan directamente como `approved`.

## Por qué el comentario se guarda primero

La entidad real del comentario es la fila de `comments`. La Queue sólo guarda el
trabajo que debe realizarse.

Guardar primero `status = processing` permite que el comentario quede persistido,
identificado y recuperable aunque el usuario cierre la página o falle
temporalmente Node, ONNX o el worker.

`processing` no aparece en el hilo público de la noticia ni en el panel de
moderación editorial. El propio autor sí puede ver esa participación dentro de
**Mis comentarios**, donde se incluyen los estados `processing`, `pending`,
`approved` y `rejected`.

## Estados

- `processing`: análisis automático en curso. No aparece en el hilo público ni en
  el panel editorial.
- `approved`: comentario publicado.
- `pending`: el comentario requiere una decisión humana, ya sea porque el pipeline
  encontró una situación ambigua o porque la infraestructura automática no pudo
  completar el análisis de forma segura.
- `rejected`: comentario no publicado y conservado como historial.

Los rechazos se diferencian con `moderation_source`:

- `automatic`: resultado o transición producida por el flujo automático.
- `editorial`: decisión realizada por un editor.

Por eso el panel puede mostrar dos grupos diferentes aunque ambos utilicen
`status = rejected`:

- **Rechazados**: `status = rejected` + `moderation_source = editorial`.
- **Rechazos automáticos**: `status = rejected` + `moderation_source = automatic`.

Los `rejected` históricos previos a esta integración se migran como `editorial`.

## Campos agregados a comments

La integración agrega únicamente tres columnas nuevas:

```text
moderation_revision
moderation_source
moderation_reason
```

- `moderation_revision` impide que un Job viejo guarde un resultado sobre una
  edición posterior del comentario.
- `moderation_source` distingue el origen automático o editorial de la transición
  y permite separar rechazos automáticos de rechazos editoriales.
- `moderation_reason` conserva una causa corta como `threat_high`, `spam_clear`,
  `editorial_rejected`, `automation_enqueue_error` o `automation_error`.

No se guardan auditorías JSON del pipeline ni una fecha adicional de moderación
en la fila de `comments`. Los errores producidos durante la ejecución del Job se
registran en `TramaLog`. Si falla el despacho del Job, la excepción se reporta
mediante el mecanismo de excepciones de Laravel.

## Job ModerateComment

Ruta:

```text
app/Jobs/ModerateComment.php
```

El Job declara explícitamente:

```php
private const SCRIPT_PATH =
    'scripts/comments/moderate-comment.mjs';
```

La ejecución real obtiene primero la ruta de Node y la ruta absoluta del script:

```php
$nodeBinary = trim(
    (string) config('trama.node_binary')
);

$scriptPath = base_path(
    self::SCRIPT_PATH
);
```

Luego ejecuta:

```php
$process = Process::path(base_path())
    ->timeout(60)
    ->env([
        'SystemRoot' => getenv('SystemRoot'),
        'PATH' => getenv('PATH'),
    ])
    ->run([
        $nodeBinary,
        $scriptPath,
        (string) $comment->id,
        (string) $this->moderationRevision,
        (string) $comment->body,
    ]);
```

La ruta de Node puede configurarse mediante:

```text
TRAMA_NODE_BINARY="C:/Program Files/nodejs/node.exe"
```

El valor se expone mediante:

```php
config('trama.node_binary')
```

La ruta anterior es la configuración de ejemplo para Windows; el ejecutable es
configurable por entorno.

## Coordinador Node

Ruta:

```text
scripts/comments/moderate-comment.mjs
```

No es un servidor. Se inicia para procesar un comentario, importa los detectores,
produce la decisión agregada y termina.

Entrada:

```text
node scripts/comments/moderate-comment.mjs <comment_id> <revision> <text>
```

Antes de ejecutar los detectores valida que:

- `comment_id` sea un entero positivo;
- `revision` sea un entero positivo;
- `text` tenga entre 8 y 1200 caracteres.

Salida exitosa: un único objeto JSON por stdout.

Error técnico: mensaje por stderr + código de salida distinto de cero.

## Pipeline

Orden:

```text
idioma -> links -> amenazas -> toxicidad -> spam
```

El pipeline no ejecuta necesariamente las cinco etapas completas. Un resultado
terminal puede cortar el procesamiento antes de cargar los detectores siguientes.

### Reglas principales

1. **Link detectado**: `rejected` con `link_detected` y corte inmediato.
2. **Idioma extranjero o mezcla confirmada**, sin link: `pending` y corte.
3. **Idioma `unknown` por texto corto o evidencia insuficiente**: agrega
   `language_review` como señal provisional y continúa por amenazas, toxicidad y
   spam.
4. **Amenaza HIGH**: `rejected` con `threat_high` y corte.
5. **Amenaza MEDIUM**: agrega `threat_medium` y continúa.
6. **Amenaza con `review_recommended`**: agrega `threat_review` y continúa.
7. **Toxicidad con `review_recommended`**: agrega `toxicity_review` y continúa.
8. **Toxicidad >= 0.70 sin `review_recommended`**: `rejected` con
   `toxicity_clear` y corte.
9. **Spam con `review_recommended`**: agrega `spam_review`.
10. **Spam >= 0.78 sin `review_recommended`**: `rejected` con `spam_clear`.
11. Si el pipeline termina sin rechazo y quedan razones de revisión distintas de
    `language_review`: `pending`.
12. Si la única señal provisional era `language_review` y amenazas, toxicidad y
    spam terminaron limpios: `approved` con `automatic_clean`.
13. Si no existe ninguna señal de revisión ni rechazo: `approved` con
    `automatic_clean`.

La prioridad agregada es:

```text
rejected > pending > approved
```

La excepción importante es `language_review`: esa razón por sí sola no fuerza un
`pending`. Esto permite que textos cortos como `Buen dato` puedan aprobarse si el
resto de los detectores no encuentra riesgos.

`review_recommended` tiene prioridad sobre los umbrales numéricos de toxicidad y
spam. Por ejemplo, una toxicidad elevada con señal explícita de revisión humana
queda en `pending` en lugar de rechazarse automáticamente por porcentaje.

## Razones principales de moderación

`moderation_reason` conserva una razón breve, no el JSON completo del pipeline.
Entre las razones utilizadas se encuentran:

```text
automation_queued
automation_recheck_queued

automatic_clean

link_detected
language_mixed
unsupported_language
threat_high
threat_medium
threat_review
toxicity_clear
toxicity_review
spam_clear
spam_review

automation_enqueue_error
automation_error

editorial_approved
editorial_rejected
parent_editorial_rejected
```

`language_review` existe únicamente como señal interna provisional del
coordinador. No se persiste como `moderation_reason` final y no implica por sí
sola que el estado final deba ser `pending`. Si era la única señal y el resto del
pipeline termina limpio, el resultado persistido es `automatic_clean`; si existe
otra causa de revisión, se conserva esa otra causa.

## Comentarios internos de TRAMA

Las cuentas internas autorizadas mantienen un flujo diferente.

```text
Periodista autor de la nota
        |
        v
Autor de la nota
        |
        v
approved directamente

Editor
        |
        v
Equipo TRAMA
        |
        v
approved directamente
```

Estas participaciones no reciben `moderation_revision > 0` ni se despachan a
`ModerateComment`.

El Job incluye además una protección adicional: aunque se despachara por error
sobre una participación interna, no aplica moderación automática a los contextos
`Autor de la nota` o `Equipo TRAMA`.

## Edición

Mientras un comentario está `processing`, no puede editarse.

Si el análisis termina en `pending`, el usuario registrado puede editarlo según
la política existente. La edición:

1. modifica el texto;
2. cambia `pending -> processing`;
3. incrementa `moderation_revision`;
4. establece `moderation_source = automatic`;
5. establece temporalmente `moderation_reason = automation_recheck_queued`;
6. despacha un nuevo `ModerateComment`.

La revisión evita que un Job viejo modifique una versión nueva.

Antes de ejecutar Node y nuevamente antes de guardar el resultado, el Job verifica
que la fila siga en `processing` y que `moderation_revision` coincida con la
revisión para la que fue creado.

## Fallos técnicos

Los fallos de infraestructura no son motivos de aprobación ni de rechazo.

### Fallo al encolar el Job

Puede ocurrir antes de que exista una ejecución de `ModerateComment`:

```text
Laravel guarda comments.status = processing
        |
        v
intenta despachar ModerateComment
        |
        v
la Queue no recibe el Job
        |
        v
status = pending
moderation_source = automatic
moderation_reason = automation_enqueue_error
```

En este caso no existen reintentos del Job porque el trabajo nunca llegó a quedar
encolado.

### Fallo durante la ejecución del Job

Node detenido, error ONNX, timeout, JSON inválido, contrato inesperado o cualquier
fallo necesario para completar el pipeline hacen fallar el intento del Job.

`ModerateComment` declara:

```text
tries = 3
timeout = 70 segundos
Process Node timeout = 60 segundos
backoff = 5, 15, 30 segundos
```

Si se agotan los intentos y la misma revisión continúa en `processing`, cambia a:

```text
status = pending
moderation_source = automatic
moderation_reason = automation_error
```

Así el comentario termina en revisión humana y nunca se publica por defecto
debido a un problema de infraestructura.

## Actualización de la interfaz

La petición HTTP no espera a que terminen los modelos. Cuando Laravel crea o
reedita una participación que queda en `processing`, devuelve además el ID de esa
fila mediante `automatic_comment_id`.

`ArticleShow.vue` utiliza ese ID para consultar periódicamente:

```text
GET /noticias/{article}/comentarios/estado-procesamiento
```

Mientras la fila continúa en `processing`, el endpoint responde:

```json
{
    "has_processing": true,
    "latest_result": null
}
```

Cuando finaliza la moderación, puede devolver un resultado como:

```json
{
    "has_processing": false,
    "latest_result": {
        "id": 85,
        "parent_id": null,
        "type": "comment",
        "status": "approved",
        "reason": "automatic_clean"
    }
}
```

Vue detiene el polling cuando el comentario deja de estar en `processing`. Para
los resultados `approved` y `pending`, actualiza la información visible y recarga
la prop `article` mediante Inertia. Para `rejected`, aplica el resultado
localmente sin recargar la noticia completa.

Las transiciones posibles siguen siendo:

```text
processing -> approved
processing -> pending
processing -> rejected
```

El endpoint no devuelve el texto ni los scores internos del pipeline.

## Panel editorial

`processing` queda excluido del panel de moderación editorial.

Se mantienen:

- Por revisar.
- Pendientes.
- Reportados.
- Rechazados.
- Rechazos automáticos.
- Aprobados.

`Rechazados` contiene decisiones editoriales.

`Rechazos automáticos` contiene decisiones del pipeline automático.

## Comandos

Aplicar la migración:

```bash
php artisan migrate
```

Limpiar cachés:

```bash
php artisan optimize:clear
```

Desarrollo habitual:

```bash
composer run dev
```

El comando de desarrollo escucha las colas `moderation,default`. En un entorno
de ejecución independiente también puede levantarse el worker con:

```bash
php artisan queue:work --queue=moderation,default --tries=3 --timeout=70
```

No hay que iniciar ningún `moderation-server.mjs`.
