/* ============================================================================
 * SCRIPT: process-image.mjs
 * ============================================================================
 *
 * Procesa una imagen de portada mediante Sharp.
 *
 * Recibe la imagen original, la ruta del archivo de destino, las dimensiones
 * finales y la calidad WebP desde el servicio de Laravel.
 *
 * Rechaza imágenes demasiado pequeñas, corrige su orientación, las recorta
 * sin deformarlas y genera una portada WebP con las dimensiones solicitadas.
 * ============================================================================ */

import { existsSync } from 'node:fs';
import sharp from 'sharp';

const [
    sourcePath,
    destinationPath,
    widthArgument,
    heightArgument,
    qualityArgument,
] = process.argv.slice(2);

try {
    if (
        !sourcePath
        || !destinationPath
        || !widthArgument
        || !heightArgument
        || !qualityArgument
    ) {
        throw new Error(
            'Faltan la ruta original, la ruta de destino o los valores de procesamiento.'
        );
    }

    if (!existsSync(sourcePath)) {
        throw new Error('La imagen original no existe.');
    }

    if (existsSync(destinationPath)) {
        throw new Error('El archivo de destino ya existe.');
    }

    const width = Number(widthArgument);
    const height = Number(heightArgument);
    const quality = Number(qualityArgument);

    if (!Number.isInteger(width) || width <= 0) {
        throw new Error('El ancho final debe ser un número entero mayor que cero.');
    }

    if (!Number.isInteger(height) || height <= 0) {
        throw new Error('El alto final debe ser un número entero mayor que cero.');
    }

    if (
        !Number.isInteger(quality)
        || quality < 1
        || quality > 100
    ) {
        throw new Error('La calidad WebP debe ser un número entero entre 1 y 100.');
    }

    const metadata = await sharp(sourcePath, {
        failOn: 'error',
    }).metadata();

    if (!metadata.width || !metadata.height) {
        throw new Error('No se pudieron obtener las dimensiones de la imagen.');
    }

    /*
     * Las orientaciones EXIF comprendidas entre 5 y 8 intercambian visualmente
     * el ancho y el alto de la imagen.
     */
    const swapsDimensions = [5, 6, 7, 8].includes(
        metadata.orientation ?? 1
    );

    const sourceWidth = swapsDimensions
        ? metadata.height
        : metadata.width;

    const sourceHeight = swapsDimensions
        ? metadata.width
        : metadata.height;

    if (sourceWidth < width || sourceHeight < height) {
        throw new Error(
            `La imagen debe medir al menos ${width} × ${height} píxeles. `
            + `La imagen recibida mide ${sourceWidth} × ${sourceHeight} píxeles.`
        );
    }

    await sharp(sourcePath, {
        failOn: 'error',
    })
        // Corrige automáticamente la orientación indicada por los datos EXIF.
        .rotate()
        // Recorta desde el centro hasta obtener las dimensiones exactas.
        .resize(width, height, {
            fit: 'cover',
            position: 'centre',
            withoutEnlargement: true,
        })
        .webp({
            quality,
        })
        .toFile(destinationPath);

    process.stdout.write(
        JSON.stringify({
            output_path: destinationPath,
            width,
            height,
            format: 'webp',
        })
    );
} catch (error) {
    const message = error instanceof Error
        ? error.message
        : 'Ocurrió un error desconocido al procesar la portada.';

    process.stderr.write(
        JSON.stringify({
            error: message,
        })
    );

    process.exit(1);
}