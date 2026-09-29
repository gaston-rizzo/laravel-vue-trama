# Flujo editorial de TRAMA

## Estados

Las noticias pueden pasar por los siguientes estados:

```text
Borrador
   ↓
En revisión
   ├──→ Devuelta para corrección ──→ En revisión
   ├──→ Programada ─────────────────→ Publicada
   └──→ Publicada

Publicada ──→ Archivada

Restauración de una revisión histórica ──→ Borrador
```

Estos estados corresponden internamente a:

```text
draft
review
needs_changes
scheduled
published
archived
```

## Reglas principales

- El periodista crea y modifica únicamente noticias propias que se encuentren en borrador o devueltas para corrección.
- Una noticia enviada a revisión queda bloqueada para el periodista hasta que un editor tome una decisión.
- El editor debe registrar una observación cuando devuelve una noticia para realizar cambios.
- Cuando el periodista corrige y reenvía una noticia, las observaciones abiertas correspondientes quedan resueltas y se registra una nueva revisión.
- El editor puede publicar una noticia inmediatamente o programarla para una fecha y hora determinadas.
- Las noticias programadas se publican automáticamente mediante Laravel Scheduler cuando llega el momento establecido según el reloj editorial de TRAMA.
- Publicar, programar, archivar o restaurar una versión genera información de revisión para conservar el historial editorial.
- Restaurar una revisión histórica no reemplaza directamente una noticia publicada. La versión restaurada vuelve al flujo editorial como borrador para que pueda revisarse nuevamente.
