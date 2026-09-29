# Ampliación de la gestión editorial

Este documento describe los principales cambios introducidos por la migración:

```text
database/migrations/2026_07_20_000000_expand_trama_editorial_management.php
```

## Tabla `users`

- `is_active`: habilita o bloquea el acceso.
- `last_login_at`: registra el último inicio de sesión correcto.
- `disabled_at`: conserva la fecha y hora en que se desactivó la cuenta.
- `password_reset_required_at`: permite exigir un restablecimiento de contraseña después de una intervención administrativa.

## Tabla `tags`

- `description`: describe el alcance editorial de la etiqueta.
- `is_active`: controla si puede utilizarse en noticias nuevas.
- `merged_into_id`: conserva la etiqueta de destino cuando se realiza una fusión.

## Tabla `article_review_feedback`

Registra las devoluciones realizadas durante la revisión editorial, incluyendo el usuario responsable, el mensaje de la devolución y su resolución posterior.

## Tabla `article_revisions`

- `changed_fields`: conserva los campos modificados entre revisiones.
- `restored_from_revision_id`: identifica la revisión histórica utilizada como origen de una restauración.

## Tabla `media_assets`

- `usage`: permite distinguir el uso asignado a los archivos multimedia, por ejemplo imágenes de portada o imágenes utilizadas dentro del contenido de una noticia.

## Protección de relaciones históricas

La migración modifica determinadas claves foráneas para evitar que la eliminación de registros relacionados borre automáticamente información editorial que debe conservarse.

Entre las relaciones protegidas se encuentran:

- `articles.author_id`;
- `articles.category_id`;
- `article_revisions.user_id`.

Estas relaciones utilizan restricciones que impiden eliminar el registro relacionado mientras siga siendo necesario para conservar la integridad del historial editorial.

## Estado `needs_changes`

La columna `articles.status` ya utiliza un tipo de cadena, por lo que incorporar el estado `needs_changes` no requiere modificar físicamente la definición de la columna.
