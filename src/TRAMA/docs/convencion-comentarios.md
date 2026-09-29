# Convención de comentarios de TRAMA

## Encabezados de archivo

Los archivos propios de TRAMA siguen como convención un bloque inicial que identifica su tipo, nombre y responsabilidad.

Un ejemplo de este tipo de encabezado es:

```php
/* ============================================================================
 * CONTROLLER: ArticleController.php
 * ============================================================================
 *
 * Controla la gestión de noticias del panel editorial de TRAMA.
 *
 * Muestra listados, abre formularios de creación o edición, valida permisos,
 * guarda imágenes de portada y entrega los datos que necesita Vue. Las acciones
 * que modifican estados editoriales se delegan al servicio de flujo editorial
 * para que creación, actualización, archivo y revisiones queden registradas de
 * forma consistente.
 * ============================================================================ */
```

Según la responsabilidad del archivo se utilizan identificadores como `CONTROLLER`, `MODEL`, `REQUEST`, `MIDDLEWARE`, `SERVICE`, `RESOURCE`, `MIGRATION`, `SEEDER`, `CONFIG`, `ROUTES`, `COMMAND`, `JOB`, `SUPPORT` y `TEST`, entre otros.

## Métodos y funciones

Cuando un método requiere documentación adicional, se utiliza PHPDoc para describir su propósito, reglas de negocio o efectos relevantes. También puede utilizarse para declarar tipos que no quedan expresados completamente en la firma del código.

Por ejemplo:

```php
/**
 * Devuelve una noticia en revisión y conserva la observación obligatoria.
 */
public function requestChanges(...)
```

En métodos con comportamiento más específico, el bloque puede ampliar las condiciones de la operación:

```php
/**
 * Cancela la programación de una noticia sin eliminarla ni archivarla.
 *
 * Si la noticia pertenece al editor que realiza la acción, vuelve a borrador.
 * Si pertenece a un periodista, vuelve a revisión editorial.
 */
public function cancelSchedule(...)
```

PHPDoc también se utiliza cuando resulta útil precisar tipos de parámetros, colecciones o variables:

```php
/**
 * @param array<string, mixed> $attributes
 * @param list<int> $tagIds
 */
```

## Comentarios internos

Los comentarios internos se reservan para decisiones técnicas o reglas cuyo motivo no resulta evidente al leer el código, por ejemplo:

- orden obligatorio de una operación;
- motivo de una transacción o bloqueo;
- protección de autoría e historial;
- compensación de archivos ante un rollback;
- sanitización o validación de seguridad;
- reglas específicas del flujo editorial.

No es necesario comentar asignaciones, retornos o llamadas cuyo propósito ya resulte evidente por el propio código.

## Idioma y mantenimiento

Los comentarios propios se escriben en español claro y deben mantenerse sincronizados con el comportamiento real del código. Cuando una regla o implementación cambia, también debe actualizarse su comentario para evitar documentación obsoleta.
