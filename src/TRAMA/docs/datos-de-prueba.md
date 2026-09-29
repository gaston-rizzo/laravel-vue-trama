# TRAMA — Datos de prueba

TRAMA incluye una base de datos de prueba con usuarios, noticias, comentarios, publicidad y actividad del sitio.

La base de datos puede cargarse importando el archivo SQL incluido en el repositorio o generarse desde cero mediante las migraciones y los seeders del proyecto.

## Base de datos incluida

El archivo SQL se encuentra en la carpeta database con el nombre trama_mysql.sql.

Este archivo contiene la base de datos de prueba completa de TRAMA y puede importarse directamente en MySQL sin necesidad de ejecutar los seeders.

## Generar la base de datos con los seeders

La base también puede generarse desde el proyecto Laravel.

Antes de ejecutar los seeders, hacé una copia de seguridad de la base de datos actual si contiene información que quieras conservar.

Desde la carpeta src, ejecutá:

    php artisan optimize:clear
    php artisan migrate:fresh --seed

migrate:fresh elimina todas las tablas de la base de datos configurada, vuelve a ejecutar las migraciones y finalmente carga los datos de prueba mediante los seeders.

## Cuentas internas

Las siguientes cuentas se crean automáticamente:

    admin@trama.test - Marina Ibarra - admin
    mateo.ledesma@trama.test - Mateo Ledesma - editor
    valentina.rios@trama.test - Valentina Ríos - journalist
    lucia.ferreyra@trama.test - Lucía Ferreyra - journalist
    bruno.salvatierra@trama.test - Bruno Salvatierra - journalist
    camila.torres@trama.test - Camila Torres - journalist

## Usuarios de prueba

Además de las cuentas internas, pueden utilizarse las siguientes cuentas de usuarios públicos para probar las funciones disponibles para usuarios registrados:

    euge44@outlook.test — Eugenia Alejandro Godoy Campos
    ccabrera@proton.test — Candela Cabrera
    magali.campos@gmail.test — Magalí Campos

## Contraseña de prueba

Todas las cuentas generadas utilizan la misma contraseña:

    password

Esto incluye:

- los 2.500 usuarios públicos;
- Marina Ibarra;
- Mateo Ledesma;
- Valentina Ríos;
- Lucía Ferreyra;
- Bruno Salvatierra;
- Camila Torres.

Los seeders almacenan directamente un hash bcrypt de esta contraseña, es decir, una representación no reversible de password que Laravel puede verificar de forma segura sin guardar la contraseña en texto plano.

## Datos generados

La base contiene:

- 2.500 usuarios públicos registrados.
- 6 empleados.
- 2.330 usuarios públicos verificados y activos.
- 120 usuarios sin verificar.
- 50 usuarios bloqueados con historial administrativo.

También contiene 90 noticias distribuidas de la siguiente manera:

- 60 publicadas.
- 8 archivadas.
- 5 programadas.
- 7 en revisión.
- 5 devueltas para realizar cambios.
- 5 borradores.

Además:

- 6 categorías.
- 12 etiquetas activas.
- 15 publicidades.
- 285 registros de métricas publicitarias correspondientes a 15 banners durante 19 días, desde el 01/07/2026 hasta el 19/07/2026.

Las cantidades de vistas, comentarios, respuestas, likes, reportes y revisiones administrativas surgen de la actividad simulada por los seeders.

Los valores finales generados quedan registrados en:

    src/database/seeders/data/seed_stats.json

## Correos electrónicos de prueba

Los 2.500 usuarios públicos utilizan direcciones con dominios reservados para pruebas, por ejemplo:

    gmail.test
    hotmail.test
    outlook.test

Los nombres y formatos de las direcciones son variados, pero ninguna apunta a una cuenta de correo real.

De esta manera, una verificación de correo o una recuperación de contraseña realizada accidentalmente durante una demostración no puede enviarse a una persona real.

## Imágenes

### Empleados

Las imágenes de los seis empleados se encuentran en:

    src/public/images/team/

Todas están en formato WebP y tienen una resolución de 600×600 píxeles.

### Noticias

El proyecto incluye las 88 imágenes de portada utilizadas por las 90 noticias.

Todas están en formato WebP y tienen una resolución de 1600×900 píxeles.

Dos de los borradores no tienen imagen de portada de forma intencional.

Las noticias publicadas y programadas utilizan nombres de archivo derivados de sus títulos.

Las noticias que todavía se encuentran dentro del flujo editorial y aún no tienen un slug público pueden conservar nombres con el formato:

    borrador-UUID.webp

### Banners publicitarios

Los 15 banners utilizados por las publicidades están incluidos en el proyecto con los mismos nombres almacenados en advertisements.image_path.

Las dimensiones utilizadas son:

- Header: 1456×180.
- Sidebar superior: 600×500.
- Sidebar inferior: 600×1200.

No es necesario renombrar los archivos manualmente.

## Comprobaciones de integridad

Al finalizar la carga de datos, TramaIntegritySeeder realiza distintas comprobaciones para evitar que la base quede en un estado inconsistente.

Entre otras cosas, verifica:

- la cantidad esperada de usuarios;
- la cantidad de noticias y su distribución por estado;
- que las noticias no públicas no tengan vistas ni comentarios;
- que el contador articles.views coincida con la cantidad de registros existentes en article_views;
- que los usuarios sin verificar no tengan comentarios;
- que los usuarios bloqueados tengan su correspondiente resolución administrativa con estado blocked;
- que exista la cantidad esperada de publicidades;
- que exista la cantidad esperada de métricas publicitarias.

Si alguna de estas comprobaciones falla, el proceso de carga se interrumpe y la transacción se revierte.