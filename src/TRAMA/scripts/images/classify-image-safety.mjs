/* ============================================================================
 * SCRIPT: classify-image-safety.mjs
 * ============================================================================
 *
 * Clasifica una imagen mediante el modelo de seguridad incluido en TRAMA.
 *
 * Recibe la ruta física de una imagen, la convierte al tensor requerido por
 * el modelo ONNX y devuelve exclusivamente una respuesta JSON mediante stdout.
 *
 * Categorías devueltas:
 * - NSFL: contenido gráfico extremo o gore.
 * - NSFW: contenido sexual o altamente sugerente.
 * - SFW: contenido permitido.
 *
 * La decisión de aceptar o rechazar la imagen corresponde a Laravel según
 * los umbrales definidos por la aplicación.
 * ============================================================================ */

import fs from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import * as ort from 'onnxruntime-node';
import sharp from 'sharp';

const IMAGE_WIDTH = 224;
const IMAGE_HEIGHT = 224;

const LABELS = ['NSFL', 'NSFW', 'SFW'];

const currentFile = fileURLToPath(import.meta.url);
const currentDirectory = path.dirname(currentFile);
const projectRoot = path.resolve(currentDirectory, '..', '..');

const modelPath = path.join(
    projectRoot,
    'resources',
    'models',
    'image-safety',
    'image-safety-classifier-m.onnx',
);

/**
 * Convierte una imagen al tensor esperado por el modelo ONNX.
 *
 * El tensor contiene una sola imagen RGB de 224 × 224 píxeles y utiliza
 * el formato NCHW: lote, canales, altura y ancho.
 */
async function createImageTensor(imagePath) {
    const { data, info } = await sharp(imagePath)
        .rotate()
        .resize(IMAGE_WIDTH, IMAGE_HEIGHT, {
            fit: 'fill',
            kernel: sharp.kernel.cubic,
        })
        .toColourspace('srgb')
        .removeAlpha()
        .raw()
        .toBuffer({
            resolveWithObject: true,
        });

    if (info.channels !== 3) {
        throw new Error(
            `La imagen procesada debe tener 3 canales RGB, pero tiene ${info.channels}.`,
        );
    }

    const pixelCount = IMAGE_WIDTH * IMAGE_HEIGHT;
    const tensorData = new Float32Array(3 * pixelCount);

    // Sharp devuelve los canales intercalados como RGBRGBRGB. El modelo espera
    // primero todos los valores rojos, luego los verdes y finalmente los azules.
    for (let pixel = 0; pixel < pixelCount; pixel++) {
        const sourceOffset = pixel * 3;

        tensorData[pixel] = data[sourceOffset];
        tensorData[pixelCount + pixel] = data[sourceOffset + 1];
        tensorData[(pixelCount * 2) + pixel] = data[sourceOffset + 2];
    }

    return new ort.Tensor(
        'float32',
        tensorData,
        [1, 3, IMAGE_HEIGHT, IMAGE_WIDTH],
    );
}

/**
 * Ejecuta el modelo y devuelve las probabilidades correspondientes a cada
 * categoría de seguridad.
 */
async function classifyImage(imagePath) {
    await fs.access(modelPath);
    await fs.access(imagePath);

    const session = await ort.InferenceSession.create(modelPath);

    try {
        const imageTensor = await createImageTensor(imagePath);

        const result = await session.run({
            image: imageTensor,
        });

        const output = result.probabilities;

        if (!output) {
            throw new Error(
                'El modelo no devolvió la salida "probabilities".',
            );
        }

        const values = Array.from(output.data, Number);

        if (
            values.length !== LABELS.length
            || values.some((value) => !Number.isFinite(value))
        ) {
            throw new Error(
                'El modelo devolvió probabilidades inválidas.',
            );
        }

        const probabilities = Object.fromEntries(
            LABELS.map((label, index) => [
                label,
                values[index],
            ]),
        );

        const primary = LABELS.reduce(
            (highestLabel, currentLabel) => (
                probabilities[currentLabel] > probabilities[highestLabel]
                    ? currentLabel
                    : highestLabel
            ),
            LABELS[0],
        );

        return {
            primary,
            probabilities,
        };
    } finally {
        await session.release();
    }
}

/**
 * Resuelve la ruta recibida y escribe la clasificación como una única
 * estructura JSON para que Laravel pueda decodificarla.
 */
async function main() {
    const imageArgument = process.argv[2];

    if (!imageArgument) {
        throw new Error(
            'No se recibió la ruta de la imagen que debe analizarse.',
        );
    }

    const imagePath = path.isAbsolute(imageArgument)
        ? path.normalize(imageArgument)
        : path.resolve(projectRoot, imageArgument);

    const classification = await classifyImage(imagePath);

    process.stdout.write(
        `${JSON.stringify(classification)}\n`,
    );
}

try {
    await main();
} catch (error) {
    // Los errores se envían exclusivamente mediante stderr para no contaminar
    // el JSON que Laravel espera recibir desde stdout.
    process.stderr.write(
        `${JSON.stringify({
            error: error instanceof Error
                ? error.message
                : String(error),
        })}\n`,
    );

    process.exitCode = 1;
}