/* ============================================================================
 * SCRIPT: classify-comment-spam.mjs
 * ============================================================================
 *
 * Clasifica spam en comentarios de TRAMA mediante una arquitectura híbrida cuyo
 * componente semántico principal es el modelo:
 *
 * tanaos/tanaos-spam-detection-spanish
 *
 * TRAMA ejecuta localmente la variante ONNX INT8 almacenada en:
 *
 * resources/models/comment-spam/model_quantized.onnx
 *
 * El modelo neuronal distingue dos clases:
 *
 * - not_spam
 * - spam
 *
 * La predicción neuronal NO se utiliza como autoridad absoluta. Se combina con
 * señales generales de intención, autoría, oferta, captación y destino externo,
 * junto con protecciones de contexto evaluadas por alcance local. El objetivo es
 * generalizar a familias nuevas sin memorizar frases de una batería concreta.
 *
 * La arquitectura contempla, entre otras cosas:
 *
 * - normalización Unicode y reconstrucción auxiliar de técnicas de evasión;
 * - comentario completo, cláusulas semánticas y ventanas largas superpuestas;
 * - variantes de capitalización cuando aportan una entrada distinta al modelo;
 * - deduplicación exacta antes de inferencia;
 * - cache LRU acotada entre comentarios;
 * - batches ONNX ordenados por longitud para reducir padding;
 * - fallback automático a batch=1 si el export ONNX no acepta batch dinámico;
 * - contexto local de cita, reporte, denuncia, rechazo y metalenguaje;
 * - exclusión real de spans protegidos antes del modelo, sin placeholders;
 * - representación gramatical con tildes para distinguir imperativos de reportes;
 * - negación/rechazo por alcance para no convertir advertencias en spam;
 * - separación entre autoría, oferta, incentivo, CTA y destino externo;
 * - autopromoción, venta, servicios, SEO, afiliación, soporte falso y phishing;
 * - promoción o desvío hacia contenido adulto/+18, sin tratar el vocabulario
 *   sexual aislado como spam;
 * - señales estructurales de monetización, inversión, apuestas, préstamos,
 *   sorteos, enlaces, desvío de tráfico y captación;
 * - fusión por alcance entre evidencia neuronal, estructural y contextual;
 * - estimación interna de certeza e indicación de revisión recomendada.
 *
 * El comentario original recibido desde Laravel nunca se modifica. Todas las
 * transformaciones se realizan sobre copias auxiliares de análisis.
 *
 * La responsabilidad de este archivo termina en SPAM / NOT_SPAM y sus señales.
 * No decide approved / pending / rejected. Esa política corresponde a la capa de
 * moderación que consume el resultado.
 * ============================================================================ */

import fs from 'node:fs';
import path from 'node:path';
import process from 'node:process';
import { pathToFileURL } from 'node:url';

import * as ort from 'onnxruntime-node';
import { Tokenizer } from '@huggingface/tokenizers';


/* ============================================================================
 * 1. RUTAS Y MODELO
 * ============================================================================ */

/*
 * El script se ejecuta/importa desde la raíz del proyecto TRAMA.
 */
const ROOT_PATH = process.cwd();

/*
 * Identificador documental del modelo utilizado por el clasificador.
 */
const MODEL_NAME =
    'tanaos/tanaos-spam-detection-spanish';

/*
 * Carpeta local esperada:
 *
 * resources/
 * └── models/
 *     └── comment-spam/
 *         ├── model_quantized.onnx
 *         ├── tokenizer.json
 *         ├── tokenizer_config.json
 *         ├── config.json
 *         ├── special_tokens_map.json
 *         └── vocab.txt
 */
const MODEL_PATH = path.join(
    ROOT_PATH,
    'resources',
    'models',
    'comment-spam'
);

const tokenizerJson = JSON.parse(
    fs.readFileSync(
        path.join(MODEL_PATH, 'tokenizer.json'),
        'utf8'
    )
);

const tokenizerConfig = JSON.parse(
    fs.readFileSync(
        path.join(MODEL_PATH, 'tokenizer_config.json'),
        'utf8'
    )
);

const modelConfig = JSON.parse(
    fs.readFileSync(
        path.join(MODEL_PATH, 'config.json'),
        'utf8'
    )
);

/*
 * Tokenizer y sesión ONNX se crean UNA SOLA VEZ al importar el módulo.
 * Esto evita recargar el modelo de ~130 MB por cada comentario.
 */
const tokenizer = new Tokenizer(
    tokenizerJson,
    tokenizerConfig
);

const session = await ort.InferenceSession.create(
    path.join(MODEL_PATH, 'model_quantized.onnx')
);

const PAD_TOKEN_ID =
    Number(
        modelConfig.pad_token_id
        ?? tokenizerJson.padding?.pad_id
        ?? 0
    );

/*
 * DistilBERT suele aceptar hasta 512 posiciones. Se toma primero la configuración
 * del modelo y se limita defensivamente a un rango razonable.
 */
const MODEL_MAX_TOKENS =
    Math.max(
        32,
        Math.min(
            512,
            Number(
                modelConfig.max_position_embeddings
                ?? tokenizerConfig.model_max_length
                ?? 512
            ) || 512
        )
    );


/* ============================================================================
 * 2. CONFIGURACIÓN GENERAL
 * ============================================================================ */

const SPAM_THRESHOLD = 0.50;

/*
 * Los comentarios de TRAMA pueden llegar aproximadamente a 1.200 caracteres.
 * Se usan ventanas para evitar que una solicitud comercial breve quede diluida
 * dentro de un texto largo aparentemente legítimo.
 */
const LONG_SEGMENT_WINDOW_WORDS = 42;
const LONG_SEGMENT_OVERLAP_WORDS = 12;
const MAX_ANALYSIS_UNITS = 36;

/*
 * Las representaciones de UN comentario se ejecutan en lotes ONNX pequeños.
 * La batería puede modificar este valor antes de importar el módulo:
 *
 * TRAMA_MODEL_BATCH_SIZE=16
 */
const MODEL_INFERENCE_BATCH_SIZE =
    Math.max(
        1,
        Math.min(
            64,
            Number(
                process.env.TRAMA_MODEL_BATCH_SIZE
                ?? 16
            ) || 16
        )
    );

/*
 * Cache LRU acotada de inferencias neuronales exactas.
 *
 * La clave es exactamente el texto enviado al tokenizer. No se canonicaliza la
 * clave porque capitalización, símbolos u otras diferencias pueden modificar la
 * tokenización y, por lo tanto, el score del modelo.
 *
 * TRAMA_MODEL_CACHE_MAX=24000
 */
const MODEL_INFERENCE_CACHE_MAX =
    Math.max(
        1000,
        Math.min(
            100000,
            Number(
                process.env.TRAMA_MODEL_CACHE_MAX
                ?? 24000
            ) || 24000
        )
    );

/*
 * Zonas de certeza internas. La salida pública sigue siendo binaria.
 */
const CLEAR_NOT_SPAM_MAX = 0.28;
const UNCERTAIN_LOW = 0.38;
const UNCERTAIN_HIGH = 0.68;
const CLEAR_SPAM_MIN = 0.78;


/* ============================================================================
 * 3. UTILIDADES GENERALES
 * ============================================================================ */

/**
 * Limita cualquier valor numérico al intervalo cerrado [0, 1].
 * Se usa para mantener consistentes todos los scores parciales y finales.
 *
 * @param {number} value
 * @returns {number}
 */
function clamp01(value) {
    return Math.max(
        0,
        Math.min(
            1,
            Number(value) || 0
        )
    );
}

/**
 * Normaliza un score al rango válido y lo redondea a seis decimales para que
 * la salida de auditoría sea estable entre ejecuciones.
 *
 * @param {number} value
 * @returns {number}
 */
function roundScore(value) {
    return Number(
        clamp01(value).toFixed(6)
    );
}

/**
 * Elimina cadenas vacías y duplicadas preservando el orden de aparición.
 * Resulta útil al construir variantes de inferencia sin repetir trabajo ONNX.
 *
 * @param {Array<unknown>} values
 * @returns {string[]}
 */
function uniqueStrings(values) {
    const seen = new Set();
    const result = [];

    for (const value of values) {
        const text = String(value ?? '').trim();

        if (!text || seen.has(text)) {
            continue;
        }

        seen.add(text);
        result.push(text);
    }

    return result;
}

/**
 * Quita marcas diacríticas de una copia auxiliar del texto. El original nunca
 * se modifica; esta forma se reserva para comparaciones léxicas tolerantes.
 *
 * @param {string} text
 * @returns {string}
 */
function removeAccents(text) {
    return String(text)
        .normalize('NFD')
        .replace(/\p{M}+/gu, '')
        .normalize('NFC');
}

/*
 * Representación canónica utilizada únicamente por la capa estructural.
 * No se envía directamente al modelo neuronal.
 */
/**
 * Construye la representación canónica de la capa estructural: minúsculas,
 * Unicode compatible, sin tildes y sin caracteres invisibles de control.
 *
 * @param {string} text
 * @returns {string}
 */
function canonicalizeForStructure(text) {
    return removeAccents(
        String(text ?? '')
            .normalize('NFKC')
            .toLocaleLowerCase('es')
    )
        .replace(/[\u200B-\u200F\u202A-\u202E\u2060-\u206F\uFEFF]/gu, '')
        .replace(/\s+/gu, ' ')
        .trim();
}

/*
 * Representación gramatical: normaliza Unicode y minúsculas, pero conserva
 * tildes. Se usa donde la tilde distingue funciones morfológicas importantes,
 * por ejemplo `respondé` (imperativo/CTA) frente a `responde` (tercera persona).
 */
/**
 * Construye una representación gramatical conservando tildes, necesarias para
 * distinguir formas como `respondé` de `responde` o usos verbales ambiguos.
 *
 * @param {string} text
 * @returns {string}
 */
function canonicalizeForGrammar(text) {
    return String(text ?? '')
        .normalize('NFKC')
        .toLocaleLowerCase('es')
        .replace(/[\u200B-\u200F\u202A-\u202E\u2060-\u206F\uFEFF]/gu, '')
        .replace(/\s+/gu, ' ')
        .trim();
}

/**
 * Cuenta todas las coincidencias de una expresión regular sin alterar la
 * instancia recibida ni depender de su estado interno de `lastIndex`.
 *
 * @param {string} text
 * @param {RegExp} pattern
 * @returns {number}
 */
function countMatches(text, pattern) {
    const flags = pattern.flags.includes('g')
        ? pattern.flags
        : `${pattern.flags}g`;

    const regex = new RegExp(
        pattern.source,
        flags
    );

    return Array.from(
        String(text).matchAll(regex)
    ).length;
}


/* ============================================================================
 * 4. NORMALIZACIÓN Y EVASIÓN
 * ============================================================================ */

/*
 * Reconstruye secuencias de letras deliberadamente separadas, por ejemplo:
 *
 * w-h-a-t-s-a-p-p  -> whatsapp
 * t . e . l . e . g . r . a . m -> telegram
 *
 * Se utiliza solamente sobre la representación deobfuscada.
 */
/**
 * Reconstruye palabras escritas con separadores entre letras para reducir una
 * técnica frecuente de evasión manteniendo intacta la representación original.
 *
 * @param {string} text
 * @returns {string}
 */
function collapseSeparatedLetterRuns(text) {
    const source =
        String(text);

    /*
     * Se preservan límites de oración reales antes de recomponer letras. Sin
     * esto, dos evasiones consecutivas como `p r i v a d o. r e s p o n d e`
     * podían fusionarse accidentalmente en `privadoresponde`.
     *
     * Un punto usado como separador interno (`t . e . l . e`) no cumple este
     * patrón porque antes del punto hay un espacio, no una letra inmediata.
     */
    const parts =
        source.split(
            /((?<=\p{L})[.!?;:]\s+(?=\p{L}))/gu
        );

    return parts
        .map(
            (part, index) => {
                if (index % 2 === 1) {
                    return part;
                }

                return part.replace(
                    /(?<!\p{L})(?:\p{L}[\s._*|/\\-]{1,3}){3,}\p{L}(?!\p{L})/gu,
                    (match) =>
                        match.replace(
                            /[\s._*|/\\-]+/gu,
                            ''
                        )
                );
            }
        )
        .join('');
}

/*
 * Traduce sustituciones leetspeak frecuentes solamente dentro de tokens que
 * contienen simultáneamente letras y dígitos. Esto evita convertir números
 * normales —precios, fechas, porcentajes— en palabras arbitrarias.
 */
/**
 * Normaliza sustituciones leetspeak únicamente dentro de tokens que combinan
 * letras y números, evitando modificar cifras comunes del comentario.
 *
 * @param {string} text
 * @returns {string}
 */
function normalizeLeetspeakTokens(text) {
    const map = {
        '0': 'o',
        '1': 'i',
        '3': 'e',
        '4': 'a',
        '5': 's',
        '7': 't'
    };

    return String(text).replace(
        /[\p{L}\p{N}]{3,}/gu,
        (token) => {
            if (
                !/\p{L}/u.test(token)
                || !/\p{N}/u.test(token)
            ) {
                return token;
            }

            return Array.from(token)
                .map(
                    (character) =>
                        map[character]
                        ?? character
                )
                .join('');
        }
    );
}

/*
 * Reduce repeticiones exageradas dentro de palabras. Se conservan dos caracteres
 * para no destruir grafías legítimas con duplicación.
 */
/**
 * Reduce repeticiones exageradas de una misma letra sin destruir duplicaciones
 * normales del idioma.
 *
 * @param {string} text
 * @returns {string}
 */
function collapseExaggeratedLetters(text) {
    return String(text).replace(
        /(\p{L})\1{3,}/giu,
        '$1$1'
    );
}

/**
 * Recompone una separación interna breve aplicada sobre verbos de acción.
 *
 * No elimina guiones de cualquier palabra: sólo recompone tokens cuya forma
 * resultante coincide con una acción dirigida al lector conocida por la capa
 * estructural. Esto cubre evasiones como `entr-á`, `us-á`, `respond-é` sin
 * convertir compuestos legítimos con guion en otra palabra.
 *
 * @param {string} text
 * @returns {string}
 */
function normalizeInterruptedActionTokens(text) {
    const knownActions = new Set([
        'entrá',
        'entra',
        'ingresá',
        'ingresa',
        'usá',
        'usa',
        'aplicá',
        'aplica',
        'abrí',
        'abre',
        'seguí',
        'sigue',
        'respondé',
        'responde',
        'mandá',
        'manda',
        'contactá',
        'contacta',
        'escribí',
        'escribe',
        'pedí',
        'pide',
        'confirmá',
        'confirma',
        'validá',
        'valida',
        'completá',
        'completa',
        'pagá',
        'paga',
        'registrate',
        'regístrate'
    ]);

    return String(text).replace(
        /(?<!\p{L})(\p{L}{2,12})[-._*|/\\]+(\p{L}{1,6})(?!\p{L})/giu,
        (match, left, right) => {
            const joined =
                `${left}${right}`
                    .normalize('NFC')
                    .toLocaleLowerCase('es');

            return knownActions.has(joined)
                ? `${left}${right}`
                : match;
        }
    );
}


/**
 * Reconstruye sintaxis web escrita deliberadamente para evitar detectores.
 *
 * No intenta convertir cualquier palabra "punto" en un dominio. Solamente se
 * normalizan secuencias que ya poseen forma de protocolo/dirección o marcadores
 * inequívocos de URL. La representación original permanece intacta.
 *
 * @param {string} text
 * @returns {string}
 */
function normalizeWrittenUrlSyntax(text) {
    return String(text)
        .replace(/\bhxxps\b/giu, 'https')
        .replace(/\bhxxp\b/giu, 'http')
        .replace(/\bhttps?\s+(?:dos\s+puntos|colon)\s+(?:barra|slash)\s+(?:barra|slash)\b/giu, (match) =>
            /^https/i.test(match) ? 'https://' : 'http://')
        .replace(/\b([a-z0-9][a-z0-9-]{1,62})\s+(?:punto|dot)\s+(com|net|org|io|app|site|shop|store|xyz|online|click|link|me|co|ar|ai|info|biz|example|invalid)\b/giu, '$1.$2')
        .replace(
            /\b((?:https?:\/\/)?[a-z0-9][a-z0-9-]{1,62}\.(?:com|net|org|io|app|site|shop|store|xyz|online|click|link|me|co|ar|ai|info|biz|example|invalid))\s+(?:barra|slash)\s+([a-z0-9][a-z0-9/_-]*)\b/giu,
            '$1/$2'
        )
        .replace(/\bwww\s+(?:punto|dot)\s+/giu, 'www.')
        .replace(/\b(https?:\/\/)\s+(?=[a-z0-9])/giu, '$1');
}

/**
 * Reconstruye marcadores adultos muy frecuentes escritos con separadores.
 *
 * Esta transformación es puramente formal: no convierte la presencia de +18,
 * XXX o NSFW en spam. Sólo permite que la capa estructural vea la misma forma
 * canónica cuando el emisor intenta evadir filtros superficiales.
 *
 * @param {string} text
 * @returns {string}
 */
function normalizeAdultEvasionSyntax(text) {
    return String(text)
        .replace(
            /(?<!\p{L})x[\s._*|/\\-]+x[\s._*|/\\-]+x(?!\p{L})/giu,
            'xxx'
        )
        .replace(
            /(?<!\d)\+\s*1\s*8(?!\d)/gu,
            '+18'
        )
        .replace(
            /(?<!\d)1\s*8\s*\+(?!\d)/gu,
            '18+'
        );
}

/**
 * Crea las representaciones auxiliares utilizadas por el clasificador.
 *
 * La normalización se limita a transformaciones de forma. No convierte términos
 * de contenido en etiquetas de spam ni introduce vocabulario de una batería.
 *
 * @param {string} text
 * @returns {object}
 */
function buildNormalizationBundle(text) {
    const originalText =
        String(text ?? '').trim();

    /*
     * Fase 1: saneo Unicode básico. Aquí sólo se eliminan diferencias formales
     * e invisibles; todavía no se intenta reconstruir ninguna evasión léxica.
     */
    const normalizedText =
        originalText
            .normalize('NFKC')
            .replace(
                /[\u200B-\u200F\u202A-\u202E\u2060-\u206F\uFEFF]/gu,
                ''
            )
            .replace(/\s+/gu, ' ')
            .trim();

    const obfuscationTypes = [];

    if (
        originalText
        !== originalText.normalize('NFKC')
    ) {
        obfuscationTypes.push(
            'unicode-compatibility'
        );
    }

    if (
        /[\u200B-\u200F\u202A-\u202E\u2060-\u206F\uFEFF]/u
            .test(originalText)
    ) {
        obfuscationTypes.push(
            'invisible-unicode'
        );
    }

    /*
     * Fase 2: reconstrucción progresiva de evasiones. Cada transformación se
     * compara con la anterior para registrar exactamente qué técnica apareció.
     */
    const separatedCollapsed =
        collapseSeparatedLetterRuns(
            normalizedText
        );

    if (
        separatedCollapsed
        !== normalizedText
    ) {
        obfuscationTypes.push(
            'internal-separators'
        );
    }

    const interruptedActionNormalized =
        normalizeInterruptedActionTokens(
            separatedCollapsed
        );

    if (
        interruptedActionNormalized
        !== separatedCollapsed
    ) {
        obfuscationTypes.push(
            'interrupted-action-token'
        );
    }

    /*
     * Fase 3: normalización de sintaxis web y marcadores especiales. Estas
     * transformaciones facilitan detectar destinos sin convertirlos en spam.
     */
    const writtenUrlNormalized =
        normalizeWrittenUrlSyntax(
            interruptedActionNormalized
        );

    if (
        writtenUrlNormalized
        !== interruptedActionNormalized
    ) {
        obfuscationTypes.push(
            'written-url-syntax'
        );
    }

    const adultSyntaxNormalized =
        normalizeAdultEvasionSyntax(
            writtenUrlNormalized
        );

    if (
        adultSyntaxNormalized
        !== writtenUrlNormalized
    ) {
        obfuscationTypes.push(
            'adult-marker-separators'
        );
    }

    /*
     * Fase 4: reconstrucción léxica final (leetspeak y repeticiones exageradas).
     * Se hace al final para trabajar sobre tokens ya compactados.
     */
    const leetNormalized =
        normalizeLeetspeakTokens(
            adultSyntaxNormalized
        );

    if (
        leetNormalized
        !== adultSyntaxNormalized
    ) {
        obfuscationTypes.push(
            'leetspeak'
        );
    }

    const repeatedCollapsed =
        collapseExaggeratedLetters(
            leetNormalized
        );

    if (
        repeatedCollapsed
        !== leetNormalized
    ) {
        obfuscationTypes.push(
            'repeated-letters'
        );
    }

    const deobfuscatedText =
        repeatedCollapsed
            .replace(/\s+/gu, ' ')
            .trim();

    /*
     * El bundle conserva tanto el texto normalizado como el deobfuscado. `changed`
     * sólo informa que hubo intervención; no implica por sí mismo intención spam.
     */
    const changed =
        normalizedText !== originalText
        || deobfuscatedText !== normalizedText;

    const obfuscationSignal =
        clamp01(
            obfuscationTypes.length * 0.22
        );

    return {
        originalText,
        normalizedText,
        deobfuscatedText,
        changed,
        obfuscation_signal:
            roundScore(obfuscationSignal),
        obfuscation_types:
            [...new Set(obfuscationTypes)]
    };
}

/**
 * Devuelve directamente la representación deobfuscada que utiliza la capa de
 * análisis cuando no necesita conservar el bundle completo.
 *
 * @param {string} text
 * @returns {string}
 */
function normalizeTextForAnalysis(text) {
    return buildNormalizationBundle(text)
        .deobfuscatedText;
}


/* ============================================================================
 * 5. DIVISIÓN SEMÁNTICA
 * ============================================================================ */

/**
 * Divide el comentario en unidades pequeñas sin asumir una oración concreta de
 * una batería. Se utilizan puntuación fuerte y algunos conectores que suelen
 * separar intenciones distintas dentro de un mismo comentario.
 *
 * @param {string} text
 * @returns {string[]}
 */
function splitSemanticClauses(text) {
    const source =
        String(text ?? '')
            .replace(/\r\n?/gu, '\n')
            .trim();

    if (!source) {
        return [];
    }

    /*
     * Además de puntuación fuerte se consideran conectores que suelen marcar un
     * cambio de intención. La lista describe funciones discursivas generales:
     * contraste, inciso, oferta autoral y transición hacia una solicitud.
     */
    const rough =
        source.split(
            /(?<=[.!?;])\s+|\n+|\s+(?=(?:pero|aunque|sin\s+embargo|ademas|además|tambien|también|por\s+cierto|de\s+paso|ya\s+que\s+estamos|por\s+otro\s+lado|aprovecho(?:\s+para)?|si\s+(?:alguien|quieren|queres|querés|les\s+interesa)|les\s+dejo|les\s+paso|vendo\b|ofrezco\b|tengo\b|administro\b|contacten(?:me|nos)?\b|escriban(?:me)?\b|manden(?:me)?\b))/giu
        );

    return rough
        .map(
            (part) =>
                part.replace(/\s+/gu, ' ').trim()
        )
        .filter(Boolean);
}

/**
 * Divide una cláusula demasiado extensa en ventanas superpuestas.
 *
 * @param {string} text
 * @returns {string[]}
 */
function splitLongSegmentIntoWindows(text) {
    const words =
        String(text ?? '')
            .trim()
            .split(/\s+/u)
            .filter(Boolean);

    if (
        words.length
        <= LONG_SEGMENT_WINDOW_WORDS
    ) {
        return [String(text).trim()];
    }

    const windows = [];

    const step = Math.max(
        1,
        LONG_SEGMENT_WINDOW_WORDS
        - LONG_SEGMENT_OVERLAP_WORDS
    );

    for (
        let start = 0;
        start < words.length;
        start += step
    ) {
        const windowWords =
            words.slice(
                start,
                start + LONG_SEGMENT_WINDOW_WORDS
            );

        if (!windowWords.length) {
            break;
        }

        windows.push(
            windowWords.join(' ')
        );

        if (
            start + LONG_SEGMENT_WINDOW_WORDS
            >= words.length
        ) {
            break;
        }
    }

    return windows;
}


/* ============================================================================
 * 6. CONTEXTO PROTEGIDO POR ALCANCE
 * ============================================================================ */

/*
 * El contexto se evalúa sobre cada cláusula/ventana. Una mención periodística al
 * principio del comentario no puede proteger una oferta autoral que aparezca más
 * adelante, y una cita de spam no debe contaminar el resto del comentario.
 */
const REPORT_OBJECT_PATTERN =
    /\b(?:mensaje|comentario|texto|captura|publicidad|anuncio|correo|email|chat|conversacion|conversación|publicacion|publicación|posteo|sitio|pagina|página|campana|campaña|oferta|promocion|promoción|estafa|fraude|engaño|denuncia|investigacion|investigación|expediente|nota|articulo|artículo|informe|testimonio|comunicado|alerta|documento|archivo|evidencia|spam)\b/u;

/* Acciones inequívocamente reportativas incluso sin verbos comerciales. */
const STRONG_REPORT_ACTION_PATTERN =
    /\b(?:dice|decia|dijo|dijeron|dicen|menciona|mencionan|mencionaba|menciono|mencionaron|muestra|muestran|mostraba|mostro|mostraron|reproduce|reproducen|reprodujo|reprodujeron|copia|copian|copiaba|copio|copiaron|incluye|incluyen|incluia|incluian|incluyo|incluyeron|cita|citan|citaba|cito|citaron|transcribe|transcriben|transcribio|transcribieron|adjunta|adjuntan|adjunto|adjuntaron|documenta|documentan|documento|documentaron|denuncia|denuncian|denunciaba|denuncio|denunciaron|informa|informan|informaba|informo|informaron|advierte|advierten|advertia|advirtio|advirtieron|explica|explican|explicaba|explico|explicaron|describe|describen|describia|describio|describieron|senala|senalan|senalo|senalaron|relata|relatan|relato|relataron|cuenta|cuentan|conto|contaron|detalla|detallan|detallo|detallaron|indica|indican|indico|indicaron|reconstruye|reconstruyen|reconstruyo|reconstruyeron|confirma|confirman|confirmo|confirmaron|responde|responden|respondio|respondieron|declara|declaran|declaro|declararon|sostiene|sostienen|sostuvo|sostuvieron|asegura|aseguran|aseguro|aseguraron|afirma|afirman|afirmo|afirmaron|reported|reports|described|describes|quoted|quotes|copied|copies|showed|shows|explained|explains|warned|warns|states?|mentions?|documents?|reproduces?)\b/u;

/*
 * Verbos que también pueden ser parte de un spam real. Sólo cuentan como reporte
 * cuando existe una fuente/actor atribuible en el mismo alcance.
 */
const ATTRIBUTED_CONTENT_ACTION_PATTERN =
    /\b(?:prometia|prometía|prometian|prometían|promete|prometen|ofrecia|ofrecía|ofrecian|ofrecían|ofrece|ofrecen|pedia|pedía|pedian|pedían|pide|piden|solicitaba|solicitaban|solicita|solicitan|enviaba|enviaban|publicaba|publicaban|difundia|difundía|difundian|difundían|promised|offered|asked|requested|advertised|sent)\b/u;

const REPORT_SOURCE_PATTERN =
    /\b(?:la\s+nota|la\s+cobertura(?:\s+periodistica|\s+periodística|\s+extensa)?|el\s+analisis|el\s+análisis|el\s+articulo|el\s+artículo|la\s+investigacion|la\s+investigación|la\s+fiscalia|la\s+fiscalía|la\s+policia|la\s+policía|la\s+denuncia|las?\s+victimas?|las?\s+víctimas?|el\s+periodista|la\s+periodista|el\s+moderador|la\s+moderadora|el\s+revisor|la\s+revisora|el\s+informe|el\s+expediente|el\s+reporte|la\s+captura(?:\s+denunciada)?|el\s+mensaje|los\s+mensajes|el\s+comentario\s+eliminado|el\s+texto|los\s+textos|la\s+frase|las\s+frases|la\s+muestra|la\s+publicidad|el\s+anuncio|la\s+campana|la\s+campaña|la\s+oferta|el\s+documento|el\s+archivo|la\s+evidencia|los?\s+acusados?|los?\s+responsables?|el\s+comunicado|la\s+fuente|el\s+estudio|la\s+alerta|the\s+article|the\s+report|the\s+journalist|the\s+moderator|the\s+investigation|the\s+source|the\s+security\s+warning)\b/u;

/* Fuentes válidas sólo con un verbo reportativo inequívoco. */
const GENERIC_REPORT_SOURCE_PATTERN =
    /\b(?:el\s+organismo|la\s+empresa|el\s+equipo\s+de\s+seguridad|el\s+soporte\s+oficial|la\s+pagina\s+oficial|la\s+página\s+oficial|el\s+municipio|la\s+escuela|defensa\s+civil|el\s+equipo\s+de\s+prensa|the\s+official\s+statement|official\s+support)\b/u;

const ATTRIBUTION_FRAME_PATTERN =
    /\b(?:segun|según|de\s+acuerdo\s+con|conforme\s+a|en\s+palabras\s+de|according\s+to|as\s+reported\s+by)\b/u;


/*
 * Relación local FUENTE -> ACCIÓN REPORTATIVA. Se exige proximidad dentro de la
 * misma cláusula para que una mención periodística distante no proteja un pitch
 * autoral escondido más adelante.
 */
const REPORT_SOURCE_ACTION_PROXIMITY_PATTERN =
    /\b(?:la\s+nota|la\s+cobertura(?:\s+periodistica|\s+periodística|\s+extensa)?|el\s+analisis|el\s+análisis|el\s+articulo|el\s+artículo|la\s+investigacion|la\s+investigación|la\s+fiscalia|la\s+fiscalía|la\s+policia|la\s+policía|la\s+denuncia|las?\s+victimas?|las?\s+víctimas?|el\s+periodista|la\s+periodista|el\s+moderador|la\s+moderadora|el\s+revisor|la\s+revisora|el\s+informe|el\s+expediente|el\s+reporte|la\s+captura(?:\s+denunciada)?|el\s+mensaje|los\s+mensajes|el\s+comentario\s+eliminado|el\s+texto|los\s+textos|la\s+frase|las\s+frases|la\s+muestra|la\s+publicidad|el\s+anuncio|la\s+campana|la\s+campaña|la\s+oferta|el\s+documento|el\s+archivo|la\s+evidencia|los?\s+acusados?|los?\s+responsables?|el\s+comunicado|la\s+fuente|el\s+estudio|la\s+alerta|el\s+organismo|la\s+empresa|el\s+equipo\s+de\s+seguridad|el\s+soporte\s+oficial|la\s+pagina\s+oficial|la\s+página\s+oficial|el\s+municipio|la\s+escuela|defensa\s+civil|el\s+equipo\s+de\s+prensa)[^.!?;]{0,95}\b(?:dice|decia|dijo|dijeron|menciona|menciono|mencionaron|muestra|mostro|mostraron|reproduce|reprodujo|copia|copio|copiaron|incluye|incluia|incluyo|cita|cito|transcribe|transcribio|adjunta|adjunto|documenta|documento|denuncia|denuncio|informa|informo|advierte|advirtio|explica|explico|describe|describio|senala|senalo|relata|relato|cuenta|conto|detalla|detallo|indica|indico|reconstruye|reconstruyo|confirma|confirmo|responde|respondio|declara|declaro|sostiene|sostuvo|asegura|aseguro|afirma|afirmo|prometia|prometio|ofrecia|ofrecio|pedia|pidio|solicitaba|solicito|enviaba|envio|publicaba|publico|difundia|difundio)\b/u;

/*
 * Discurso atribuido productivo: reconoce sujetos en tercera persona seguidos de
 * un verbo reportativo y una subordinada con "que". No enumera frases completas;
 * modela la relación SUJETO + REPORTE + CONTENIDO.
 *
 * Ejemplos cubiertos:
 * - "Las víctimas contaron que..."
 * - "Los investigadores indicaron que..."
 * - "La empresa explicó que..."
 * - "Un testigo afirmó que..."
 *
 * Se limita a tercera persona para no convertir "yo cuento que vendo..." en un
 * contexto protector.
 */
const ATTRIBUTED_REPORT_CLAUSE_PATTERN =
    /\b(?:el|la|los|las|un|una|unos|unas)\s+[a-z][a-z0-9_-]*(?:\s+[a-z][a-z0-9_-]*){0,4}\s+(?:dijo|dijeron|conto|contaron|relato|relataron|senalo|senalaron|indico|indicaron|explico|explicaron|informo|informaron|advirtio|advirtieron|describio|describieron|detallo|detallaron|declaro|declararon|sostuvo|sostuvieron|aseguro|aseguraron|afirmo|afirmaron|menciono|mencionaron|mostro|mostraron|confirmo|confirmaron)\s+que\b/u;

/*
 * Tercera persona + verbo inequívocamente comunicativo/documental. A diferencia
 * del patrón anterior, no exige una subordinada con `que`: cubre construcciones
 * legítimas como `La escuela avisó por WhatsApp` o `El equipo publicó una guía`.
 */
const THIRD_PERSON_REPORT_ACTION_PATTERN =
    /\b(?:el|la|los|las|un|una|unos|unas)\s+(?:organismo|empresa|escuela|municipio|equipo(?:\s+(?:tecnico|técnico|de\s+seguridad|de\s+prensa))?|mesa\s+de\s+soporte|soporte(?:\s+oficial)?|periodista|fuente|vocero|portavoz|ministerio|gobierno|autoridad|investigadores?|testigos?|victimas?|víctimas?|medio|canal|institucion|institución)\s+(?:aviso|avisaron|publico|publicaron|documento|documentaron|respondio|respondieron|informo|informaron|explico|explicaron|comunico|comunicaron|anuncio|anunciaron|detallo|detallaron|indico|indicaron|senalo|senalaron|advirtio|advirtieron|confirmo|confirmaron|aclaro|aclararon)\b/u;

/*
 * Imperativos que no deben convertirse en verbos reportativos al quitar tildes.
 * Se evalúan sobre canonicalizeForGrammar(), por lo que `respondé` conserva la
 * información que se perdería en la representación sin acentos.
 */
const IMPERATIVE_GRAMMAR_PATTERN =
    /(?:^|[^\p{L}\p{N}_])(?:respondé|escribime|contactá|contactame|mandá|mandame|hablá|hablame|registrate|regístrate|entrá|ingresá|visitá|comprá|depositá|confirmá|validá|completá|descargá|suscribite|sumate|unite|accedé|solicitá|reservá|activá|obtené|aprovechá|usá|aplicá|pagá|reclamá|colocá|actualizá|verificá)(?=$|[^\p{L}\p{N}_])/u;

/*
 * Imperativos plurales dirigidos al lector. Se exige un complemento cercano
 * para no confundir formas verbales de tercera persona con una CTA.
 */
const PLURAL_IMPERATIVE_GRAMMAR_PATTERN =
    /(?:^|[^\p{L}\p{N}_])(?:respondan|escriban|contacten|manden|entren|ingresen|registrense|regístrense|compren|inviertan|depositen|confirmen|validen|completen|descarguen|sigan|suscribanse|suscríbanse|sumense|súmense|unanse|únanse|accedan|soliciten|reserven|activen|obtengan)(?=\s+(?:este|esta|estos|estas|el|la|los|las|al|a|por|con|en|sus?|tus?|mi|nuestro|nuestra|un|una)\b)/u;

/*
 * Formas rioplatenses que pueden ser tanto imperativo como pasado de primera
 * persona (`pedí`, `recibí`, `seguí`, `invertí`, `abrí`, `escribí`, `conseguí`). Nunca se
 * consideran CTA por sí solas: necesitan una señal cercana dirigida al lector.
 */
const AMBIGUOUS_VOSEO_ACTION_PATTERN =
    /(?:^|[^\p{L}\p{N}_])(?:pedí|recibí|seguí|invertí|abrí|escribí|conseguí)(?=$|[^\p{L}\p{N}_])/u;

const READER_DIRECTED_CUE_PATTERN =
    /\b(?:te|tu|tus|vos|usted|ustedes|para\s+vos|por\s+privado|por\s+mensaje|por\s+whatsapp|por\s+telegram|por\s+dm|ahora|hoy|acá|aqui|aquí|este\s+(?:comentario|enlace|link|formulario|sitio|codigo|código)|mi\s+(?:link|enlace|codigo|código|referencia|perfil|canal|grupo)|nuestro\s+(?:link|enlace|codigo|código|canal|grupo)|para\s+(?:acceder|entrar|recibir|activar|registrarte|sumarte)|al\s+(?:link|enlace|sitio|canal|grupo))\b/u;

/*
 * Imperativos sin tilde, comunes en comentarios, sólo cuentan al inicio de una
 * cláusula o tras puntuación y cuando introducen un objeto/destino accionable.
 */
const NON_ACCENTED_DIRECTED_ACTION_PATTERN =
    /(?:^|[.!?;:]\s+|\bpor\s+favor\s+)(?:responde|escribe|contacta|manda|habla|consulta|busca|buscame|pide|entra|ingresa|visita|compra|accede|solicita|reserva|aprovecha|activa|obtene|obten|sigue|completa|confirma|valida|descarga|suscribite|sumate|unite|usa|aplica|abre|paga|reclama|coloca|actualiza|verifica)(?=\s+(?:este|esta|estos|estas|el|la|los|las|al|a|por|con|en|tu|tus|mi|mis|nuestro|nuestra|un|una|https?|www|link|enlace|codigo|código|perfil|canal|grupo|formulario|datos|clave|referencia)\b)/u;


/*
 * Indicios de narración en primera persona. Se utilizan únicamente para
 * desambiguar formas como `pedí`, `recibí`, `seguí` o `conseguí`, que pueden ser pasado o
 * imperativo en español rioplatense.
 */
const FIRST_PERSON_NARRATIVE_PATTERN =
    /(?:^|[^\p{L}\p{N}_])(?:yo\s+)?(?:pedí|recibí|seguí|invertí|abrí|escribí|conseguí)(?=$|[^\p{L}\p{N}_])[^.!?;]{0,110}(?:^|[^\p{L}\p{N}_])(?:tuve|hice|quise|decidí|preferí|devolví|denuncié|eliminé|bloqueé|probé|usé|compré|me\s+(?:pareció|llegó|cobraron|dijeron)|lo\s+(?:devolví|eliminé|denuncié)|la\s+(?:devolví|eliminé|denuncié))(?=$|[^\p{L}\p{N}_])/u;

const PERSONAL_EXPERIENCE_CONTEXT_PATTERN =
    /(?:^|[^\p{L}\p{N}_])(?:lo\s+cuento|mi\s+experiencia|experiencia\s+personal|tuve\s+que\s+(?:devolver|reclamar|gestionar)|me\s+(?:llegó|cobraron|mandaron)|pedí\s+un\s+producto|compré\s+un\s+producto|recibí\s+una\s+invitación|preferí\s+denunciar|me\s+pareció\s+una\s+estafa|gestion(?:ar|é)\s+una\s+devolución|atención\s+al\s+cliente)(?=$|[^\p{L}\p{N}_])/u;


/*
 * Rechazo/advertencia productivos. No dependen de una lista de dominios ni de
 * una categoría concreta: capturan verbos de confianza/acción negados y marcos
 * explícitos de advertencia.
 */
const NEGATED_ACTION_SCOPE_PATTERN =
    /\b(?:no|nunca|jamás|jamas)\s+(?:crean|creas|crea|confien|confíen|confies|confíes|confies|confie|confíe|compren|compres|entren|entres|ingresen|ingreses|abran|abras|hagan\s+click|hagas\s+click|se\s+registren|te\s+registres|inviertan|inviertas|manden|mandes|envien|envíen|envies|envíes|compartan|compartas|respondan|respondas|instalen|instales|contraten|contrates|depositen|deposites|paguen|pagues|descarguen|descargues|sigan|sigas|usen|uses)\b/u;

const NEGATED_PROMISE_SCOPE_PATTERN =
    /\b(?:no\s+(?:hay|existen|son|estan|están|garantiza|garantizan|asegura|aseguran|promete|prometen)|sin\s+(?:garantia|garantía|recomendacion|recomendación|promocion|promoción|oferta|intencion\s+comercial|intención\s+comercial))\b/u;

const METALINGUISTIC_STRONG_PATTERN =
    /\b(?:spam|detector|clasificador|moderacion|moderación|falso\s+positivo|falso\s+negativo|dataset|bateria|batería|caso\s+de\s+prueba|test\s+case|modelo|onnx|heuristica|heurística|metalenguaje|moderation|classifier|false\s+positive|false\s+negative)\b/u;

const METALINGUISTIC_MENTION_PATTERN =
    /\b(?:palabra|termino|término|frase|expresion|expresión|ejemplo|cita|texto|mensaje|descripcion|descripción|publicidad|promocion|promoción|recomendacion|recomendación)\b/u;

const METALINGUISTIC_ACTION_PATTERN =
    /\b(?:significa|quiere\s+decir|se\s+usa|uso|menciona|describe|analiza|discute|cita|reproduce|incluye|contiene|distinguir|separar|clasificar|detectar|meaning|means|used|mentions?|describes?|analyzes?|discusses?|quotes?|contains?|distinguish)\b/u;

const CONTEXT_URL_PATTERN =
    /\b(?:https?:\/\/|www\.)[^\s<>()]+|\b(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+(?:com|net|org|io|app|site|shop|store|xyz|online|click|link|me|co|ar|ai|info|biz|example|invalid)(?:\/[^\s<>()]*)?/giu;

const REJECTION_PATTERN =
    /\b(?:es\s+una\s+estafa|es\s+fraude|es\s+un\s+fraude|es\s+enganos[oa]|es\s+engaños[oa]|es\s+engaño|no\s+compren|no\s+compres|no\s+entren|no\s+entres|no\s+se\s+registren|no\s+te\s+registres|no\s+inviertan|no\s+inviertas|no\s+manden\s+dinero|no\s+mandes\s+dinero|no\s+respondan|no\s+respondas|no\s+envien\s+datos|no\s+envíen\s+datos|no\s+compartan\s+(?:datos|claves|contrasenas|contraseñas|codigos|códigos)|no\s+se\s+unan|no\s+te\s+unas|no\s+instalen|no\s+instales|no\s+contraten|no\s+contrates|no\s+recomiendo|no\s+lo\s+recomiendo|no\s+estoy\s+recomendando|no\s+es\s+(?:una\s+)?(?:recomendacion|recomendación|promocion|promoción|oferta|publicidad)(?:\s+comercial)?|no\s+convierte\s+(?:a|al|esto|esa?|ese)?\s*[^.;!?]{0,45}\s+en\s+(?:una\s+)?(?:promocion|promoción|oferta|publicidad|recomendacion|recomendación)|sin\s+recomendar|sin\s+ofrecer\s+(?:productos?|servicios?|promociones?|ofertas?)|sin\s+promocionar|sin\s+invitar\s+a\s+comprar|solamente\s+comento|solo\s+comento|eviten|evita|cuidado\s+con|ojo\s+con|denuncio|denunciamos|reporto|reportamos|bloqueen|bloquealo|bloquéalo|no\s+hagan\s+click|no\s+hagas\s+click|no\s+abran\s+el\s+enlace|no\s+abras\s+el\s+enlace|sospechos[oa]|phishing|scam|fraud|do\s+not\s+click|don't\s+click|do\s+not\s+send|don't\s+send|avoid\s+this|preferi\s+denunciar|preferí\s+denunciar|lo\s+denuncie|lo\s+denuncié|la\s+denuncie|la\s+denuncié|lo\s+elimine|lo\s+eliminé|la\s+elimine|la\s+eliminé|me\s+parecio\s+una\s+estafa|me\s+pareció\s+una\s+estafa|conviene\s+desconfiar)\b/u;

/*
 * Autoría comercial o de captación. Se incluyen formas productivas (vendo,
 * ofrezco, hago, administro, tengo...) y posesivos/destinos propios. No se exige
 * una categoría comercial concreta para que la regla generalice a servicios o
 * productos todavía no enumerados.
 */
const AUTHORIAL_SOLICITATION_PATTERN =
    /\b(?:aprovecho\s+para\s+(?:ofrecer|vender|promocionar|compartir|pasar)|aprovechamos\s+para\s+(?:ofrecer|vender|promocionar|compartir|pasar)|puedo\s+(?:pasar(?:les|te|le)?|enviar(?:les|te|le)?|compartir(?:les|te|le)?|ofrecer(?:les|te|le)?|dar(?:les|te|le)?)|podemos\s+(?:pasar(?:les|te|le)?|enviar(?:les|te|le)?|compartir(?:les|te|le)?|ofrecer(?:les|te|le)?|dar(?:les|te|le)?)|escribime|escribeme|escríbeme|escribinos|escriban(?:me)?|contactame|contáctame|contactanos|contáctanos|contacten(?:me|nos)?|mandame|mándame|mandanos|mándanos|mandenme|mándenme|manden\s+(?:mensaje|mensajes)|(?:me|nos)\s+mande[n]?\s+(?:mensaje|mensajes|un\s+mensaje)|hablame|háblame|hablanos|háblanos|consultame|consúltame|consultanos|consúltanos|pedime|pedíme|respondeme|respóndeme|te\s+paso|les\s+paso|te\s+envio|te\s+envío|les\s+envio|les\s+envío|les\s+dejo\s+mi\s+(?:promocion|promoción|oferta|enlace|link|contacto|grupo|canal|web|sitio)|vendo|vendemos|ofrezco|ofrecemos|brindo|brindamos|hago|hacemos|realizo|realizamos|doy\s+clases|tomamos?\s+trabajos?|administro|administramos|gestiono|gestionamos|tengo\s+(?:vacantes|senales|señales|prestamos|préstamos|creditos|créditos|una\s+oferta|una\s+promocion|una\s+promoción|un\s+servicio|productos?|stock)|tenemos\s+(?:vacantes|senales|señales|prestamos|préstamos|creditos|créditos|productos?|stock)|mi\s+(?:enlace|link|codigo|código|grupo|canal|web|sitio|pagina|página|perfil)|nuestro\s+(?:enlace|link|codigo|código|grupo|canal|web|sitio|pagina|página)|registrate\s+(?:conmigo|con\s+nosotros)|regístrate\s+(?:conmigo|con\s+nosotros)|registrandose\s+conmigo|registrándose\s+conmigo|sumate\s+a\s+(?:mi|nuestro)|súmate\s+a\s+(?:mi|nuestro)|dm\s+me|message\s+me|contact\s+me|our\s+(?:offer|service|site|group)|my\s+(?:link|code|site|group))\b/u;

const INFORMATIONAL_DISCUSSION_PATTERN =
    /\b(?:nota|noticia|articulo|artículo|informe|analisis|análisis|investigacion|investigación|debate|cobertura|estudio|reporte|fuente|documento|regulacion|regulación|reguladores|estadistica[s]?|estadística[s]?|dato[s]?|jornada\s+(?:bursatil|bursátil|financiera)|consumidores|usuarios|economia|economía|economic[oa]s?|lanzamiento|actualizacion|actualización|condiciones\s+de\s+uso|soporte\s+al\s+cliente|segun\s+la\s+informacion|según\s+la\s+información|contexto\s+de\s+la\s+nota|discusion\s+informativa|discusión\s+informativa|tema\s+(?:discutido|debatido)|no\s+garantiza|no\s+implica|no\s+significa|no\s+es\s+recomendacion|no\s+es\s+recomendación|no\s+estoy\s+recomendando|sin\s+recomendar|solamente\s+comento|solo\s+comento|no\s+hay\s+(?:ganancias?|rentabilidad|retornos?)\s+garantizad[oa]s?|official|article|report|study|analysis|investigation|source|informational|for\s+informational\s+purposes)\b/u;

/*
 * Discusión analítica/económica sin llamada a la acción. Esta señal sólo se
 * convierte en contexto protector si el mismo alcance carece de autoría, CTA y
 * desvío externo, por lo que términos como tasa o precio no limpian una oferta.
 */
const ANALYTICAL_DISCUSSION_PATTERN =
    /\b(?:mora|morosidad|tasa[s]?|intereses?|costo\s+financiero|capacidad\s+de\s+pago|deuda[s]?|refinanciacion|refinanciación|precios?\s+(?:de\s+lista|promedio[s]?|minoristas?|mayoristas?)|consumo|importaciones?|salarios?|contrataciones?|mercado\s+laboral|empleo\s+registrado|estadisticas?|estadísticas?|porcentaje[s]?|metodologia|metodología|evolucion|evolución|comparar|comparacion|comparación|regulacion|regulación)\b/u;

/**
 * Detecta si el alcance habla SOBRE spam, publicidad o moderación en vez de
 * ejecutar la acción promocional mencionada.
 *
 * @param {string} canonicalText
 * @returns {boolean}
 */
function hasMetalinguisticContext(canonicalText) {
    const withoutUrls =
        String(canonicalText ?? '')
            .replace(
                CONTEXT_URL_PATTERN,
                ' enlace '
            );

    if (
        METALINGUISTIC_STRONG_PATTERN.test(
            withoutUrls
        )
    ) {
        return true;
    }

    return (
        METALINGUISTIC_MENTION_PATTERN.test(
            withoutUrls
        )
        && METALINGUISTIC_ACTION_PATTERN.test(
            withoutUrls
        )
    );
}

/**
 * Detecta discurso reportado o atribuido a una fuente externa. La representación
 * gramatical se usa para evitar que un imperativo dirigido al lector sea tomado
 * erróneamente como un verbo de reporte.
 *
 * @param {string} canonicalText
 * @param {string} [grammaticalText='']
 * @returns {boolean}
 */
function hasReportedContext(
    canonicalText,
    grammaticalText = ''
) {
    const attributedReportClause =
        ATTRIBUTED_REPORT_CLAUSE_PATTERN.test(
            canonicalText
        );

    const thirdPersonReportAction =
        THIRD_PERSON_REPORT_ACTION_PATTERN.test(
            canonicalText
        );

    const sourceActionProximity =
        REPORT_SOURCE_ACTION_PROXIMITY_PATTERN.test(
            canonicalText
        );

    /*
     * Los imperativos inequívocos o plurales dirigidos al lector invalidan una
     * lectura reportativa del mismo alcance. Las formas ambiguas de primera
     * persona/presente no se usan aquí como veto automático.
     */
    const imperativeGrammar =
        IMPERATIVE_GRAMMAR_PATTERN.test(
            grammaticalText
        )
        || PLURAL_IMPERATIVE_GRAMMAR_PATTERN.test(
            grammaticalText
        )
        || NON_ACCENTED_DIRECTED_ACTION_PATTERN.test(
            grammaticalText
        );

    if (imperativeGrammar) {
        return attributedReportClause
        || thirdPersonReportAction;
    }

    /*
     * V4.3 elimina la regla amplia `fuente en algún lugar + verbo en algún
     * lugar`. En ventanas largas esa combinación podía proteger una promoción
     * autoral distante. Ahora la fuente y la acción deben formar una relación
     * local o una construcción de tercera persona reconocible.
     */
    return attributedReportClause
    || thirdPersonReportAction
    || sourceActionProximity;
}

/**
 * Detecta rechazo, negación o advertencia explícita dentro del alcance local.
 * Estas señales pueden proteger vocabulario que de otro modo parecería spam.
 *
 * @param {string} canonicalText
 * @param {string} [grammaticalText='']
 * @returns {boolean}
 */
function hasRejectionContext(
    canonicalText,
    grammaticalText = ''
) {
    return REJECTION_PATTERN.test(
        canonicalText
    )
    || NEGATED_ACTION_SCOPE_PATTERN.test(
        grammaticalText
    )
    || NEGATED_PROMISE_SCOPE_PATTERN.test(
        grammaticalText
    );
}

/**
 * Resume el contexto pragmático de un alcance: reporte, metalenguaje, rechazo e
 * información. Este objeto acompaña al texto durante la segmentación y fusión.
 *
 * @param {string} text
 * @returns {object}
 */
function detectScopeContext(text) {
    const canonical =
        canonicalizeForStructure(
            text
        );

    const grammatical =
        canonicalizeForGrammar(
            text
        );

    return {
        reported_context:
            hasReportedContext(
                canonical,
                grammatical
            ),
        metalinguistic_context:
            hasMetalinguisticContext(
                canonical
            ),
        rejection_context:
            hasRejectionContext(
                canonical,
                grammatical
            ),
        informational_context:
            INFORMATIONAL_DISCUSSION_PATTERN.test(
                canonical
            ),
        imperative_context:
            IMPERATIVE_GRAMMAR_PATTERN.test(
                grammatical
            )
            || PLURAL_IMPERATIVE_GRAMMAR_PATTERN.test(
                grammatical
            )
    };
}

/**
 * Comprueba si una cláusula conserva intención autoral de spam después de aplicar
 * el contexto protector. Si la conserva, la cláusula no puede descartarse.
 *
 * @param {string} text
 * @returns {boolean}
 */
function hasResidualAuthorialSpamPotential(text) {
    const canonicalText =
        canonicalizeForStructure(
            text
        );

    const grammaticalText =
        canonicalizeForGrammar(
            text
        );

    if (
        AUTHORIAL_SOLICITATION_PATTERN.test(
            canonicalText
        )
        || OFFER_AUTHORSHIP_PATTERN.test(
            canonicalText
        )
    ) {
        return true;
    }

    /*
     * Un CTA puede formar parte de discurso referido. Sólo se considera residual
     * cuando existe una acción realmente dirigida al lector junto con una oferta
     * o destino y no una fuente reportativa local.
     */
    const cta =
        CTA_PATTERN.test(
            canonicalText
        )
        || CTA_GRAMMATICAL_PATTERN.test(
            grammaticalText
        )
        || NON_ACCENTED_MIRA_CTA_PATTERN.test(
            grammaticalText
        )
        || NON_ACCENTED_DIRECTED_ACTION_PATTERN.test(
            grammaticalText
        )
        || PLURAL_IMPERATIVE_GRAMMAR_PATTERN.test(
            grammaticalText
        );

    const commercial =
        PROMOTION_PATTERN.test(
            canonicalText
        )
        || COMMERCIAL_OFFER_PATTERN.test(
            canonicalText
        )
        || EXTERNAL_DESTINATION_PATTERN.test(
            canonicalText
        )
        || SECURITY_PRETEXT_PATTERN.test(
            canonicalText
        );

    return (
        cta
        && commercial
        && !REPORT_SOURCE_ACTION_PROXIMITY_PATTERN.test(
            canonicalText
        )
    );
}

/**
 * Examina el texto inmediatamente anterior y posterior a una cita para decidir
 * si la cita pertenece a evidencia, reporte, moderación, rechazo o metalenguaje.
 *
 * @param {string} text
 * @param {number} start
 * @param {number} end
 * @returns {object}
 */
function hasReferenceContextAround(
    text,
    start,
    end
) {
    /*
     * Se limita el análisis a una ventana local alrededor de la cita para evitar
     * que una fuente distante contamine la atribución de otro fragmento.
     */
    const windowStart =
        Math.max(
            0,
            start - 220
        );

    const windowEnd =
        Math.min(
            text.length,
            end + 220
        );

    /*
     * El contenido de la cita se excluye ANTES de decidir si existe un marco
     * reportativo. Esto es crítico: un imperativo citado como `comprá`,
     * `respondé` o `escribime` no debe anular el verbo reportativo que lo
     * introduce ni convertirse en intención del autor del comentario.
     */
    const beforeRaw =
        text.slice(
            windowStart,
            start
        );

    const afterRaw =
        text.slice(
            end,
            windowEnd
        );

    const outsideText =
        `${beforeRaw} ${afterRaw}`
            .replace(/\s+/gu, ' ')
            .trim();

    /*
     * El contexto se calcula exclusivamente sobre el texto exterior a la cita.
     * Así el imperativo citado no puede crear por sí mismo una falsa autoría.
     */
    const outsideScope =
        detectScopeContext(
            outsideText
        );

    const beforeCanonical =
        canonicalizeForStructure(
            beforeRaw
        );

    const afterCanonical =
        canonicalizeForStructure(
            afterRaw
        );

    /*
     * Marco introductorio cercano al borde de la cita. Se modela la función
     * lingüística VERBO DE REPORTE -> CITA, no el contenido concreto citado.
     */
    const quoteIntroducer =
        /(?:\b(?:dice|decia|dijo|dijeron|dicen|menciona|mencionan|mostraba|mostro|muestra|muestran|reproduce|reprodujo|reproducen|copia|copio|copiaron|copiaba|citaba|cito|cita|citan|transcribe|transcribio|adjunta|adjunto|documenta|documento|denuncia|denuncio|informa|informo|advierte|advirtio|explica|explico|describe|describio|senala|senalo|relata|relato|cuenta|conto|detalla|detallo|indica|indico|confirma|confirmo|declara|declaro|sostiene|sostuvo|asegura|aseguro|afirma|afirmo|presenta|presento|incluye|incluia|incluyo|contenia|contiene|figura|figuraba|aparece|aparecia|recibio|said|says|reported|reports|described|quoted|copied|showed|included|contained)\b[^.!?;]{0,110}:?\s*)$/u
            .test(beforeCanonical);

    /*
     * Marco nominal de evidencia/moderación. Permite reconocer citas cuando el
     * verbo no es uno de los introductores clásicos, por ejemplo una captura o
     * un moderador que copia una frase para analizarla.
     */
    const quoteEvidenceFrame =
        /\b(?:moderador|moderadora|revisor|revisora|reporte|captura|muestra|evidencia|mensaje|comentario|frase|texto)[^.!?;]{0,120}\b(?:evidencia|denunciad[oa]|revision|revisión|moderacion|moderación|analisis|análisis|ejemplo|spam|fraude|estafa)\b/u
            .test(beforeCanonical)
        || /\b(?:evidencia|denuncia|revision|revisión|moderacion|moderación|analisis|análisis|ejemplo)[^.!?;]{0,90}\b(?:frase|mensaje|texto|captura|comentario)\b/u
            .test(beforeCanonical);

    /*
     * También se reconoce el marco posterior cuando la atribución se explica
     * después de cerrar las comillas.
     */
    const quoteFollower =
        /^(?:\s*[,;:]?\s*(?:(?:aparece|aparecia|figura|figuraba|esta|estaba|queda|quedo|fue|era|es)\s+(?:(?:presentad|citad|reproducid|mostrad|incluid|identificad|documentad)[oa]\s+)?(?:como|dentro\s+de|en)\s+(?:evidencia|ejemplo|captacion|denuncia|investigacion|nota|articulo|contenido\s+fraudulento|contenido\s+enganoso|spam|publicidad|promocion)|(?:se\s+(?:presenta|presento|reproduce|reprodujo|cita|cito|muestra|mostro|incluye|incluyo|identifica|identifico|documenta|documento))\b[^.!?;]{0,100}\b(?:como|dentro\s+de|en)\s+(?:evidencia|ejemplo|captacion|denuncia|investigacion|nota|articulo|contenido\s+fraudulento|contenido\s+enganoso|spam|publicidad|promocion)|(?:la\s+nota|el\s+articulo|el\s+informe|la\s+denuncia|la\s+investigacion)\b[^.!?;]{0,110}\b(?:presenta|presento|explica|explico|describe|describio|aclara|aclaro|identifica|identifico|denuncia|denuncio|cuestiona|cuestiono)\b))/u
            .test(afterCanonical);

    /*
     * La salida conserva todas las protecciones externas y refuerza `reported`
     * cuando existe un introductor, un marco de evidencia o una atribución posterior.
     */
    return {
        ...outsideScope,
        reported_context:
            outsideScope.reported_context
            || quoteIntroducer
            || quoteEvidenceFrame
            || quoteFollower
    };
}

/**
 * Elimina del texto de inferencia únicamente las citas cuyo contexto demuestra
 * que son contenido reportado/protegido. Las demás citas permanecen intactas.
 *
 * @param {string} text
 * @returns {object}
 */
function neutralizeProtectedQuotes(text) {
    const quotePattern =
        /"[^"\n]*"|'[^'\n]*'|“[^”\n]*”|‘[^’\n]*’|«[^»\n]*»/gu;

    let cursor = 0;
    let result = '';
    let protectedQuoteCount = 0;
    let quotedContext = false;
    let reportedContext = false;
    let metalinguisticContext = false;
    let rejectionContext = false;

    /*
     * Cada cita se evalúa de manera independiente. Una cita protegida se elimina
     * de la entrada semántica sin introducir placeholders artificiales.
     */
    for (const match of text.matchAll(quotePattern)) {
        const start =
            match.index ?? 0;

        const end =
            start + match[0].length;

        const context =
            hasReferenceContextAround(
                text,
                start,
                end
            );

        /*
         * Sólo se protege cuando el contexto exterior justifica la atribución.
         * Las comillas por sí solas nunca convierten el contenido en NOT_SPAM.
         */
        const shouldProtect =
            context.metalinguistic_context
            || context.reported_context
            || context.rejection_context;

        result += text.slice(
            cursor,
            start
        );

        if (shouldProtect) {
            result += ' ';
            protectedQuoteCount += 1;
            quotedContext = true;
            reportedContext ||= context.reported_context;
            metalinguisticContext ||= context.metalinguistic_context;
            rejectionContext ||= context.rejection_context;
        } else {
            result += match[0];
        }

        cursor = end;
    }

    result += text.slice(cursor);

    return {
        text:
            result
                .replace(/\s+/gu, ' ')
                .trim(),
        quoted_context:
            quotedContext,
        reported_context:
            reportedContext,
        metalinguistic_context:
            metalinguisticContext,
        rejection_context:
            rejectionContext,
        protected_quote_count:
            protectedQuoteCount
    };
}

/**
 * Excluye cada cláusula protegida de la inferencia por su propio alcance. Un
 * contexto legítimo no se extiende a cláusulas vecinas y una solicitud autoral
 * impide excluir la cláusula aunque contenga vocabulario periodístico. Nunca se
 * introduce texto artificial como reemplazo del contenido protegido.
 */
function neutralizeProtectedClauses(text) {
    const clauses =
        splitSemanticClauses(text);

    const output = [];

    let protectedClauseCount = 0;
    let reportedContext = false;
    let metalinguisticContext = false;
    let rejectionContext = false;

    /*
     * Cada cláusula recibe su propio contexto para impedir que una advertencia o
     * una noticia protejan accidentalmente una oferta autoral vecina.
     */
    for (const clause of clauses) {
        const canonical =
            canonicalizeForStructure(
                clause
            );

        const scope =
            detectScopeContext(
                clause
            );

        /*
         * Antes de excluir una cláusula protegida se verifica que no quede una
         * acción comercial propia. Esta es la barrera contra spam camuflado.
         */
        const residualSpam =
            hasResidualAuthorialSpamPotential(
                clause
            );

        const protect =
            (
                scope.reported_context
                || scope.metalinguistic_context
                || scope.rejection_context
            )
            && !residualSpam;

        if (protect) {
            output.push('');

            protectedClauseCount += 1;
            reportedContext ||= scope.reported_context;
            metalinguisticContext ||= scope.metalinguistic_context;
            rejectionContext ||= scope.rejection_context;
        } else {
            output.push(clause);
        }
    }

    return {
        text:
            output
                .filter(Boolean)
                .join('. ')
                .replace(/\s+/gu, ' ')
                .trim(),
        reported_context:
            reportedContext,
        metalinguistic_context:
            metalinguisticContext,
        rejection_context:
            rejectionContext,
        protected_clause_count:
            protectedClauseCount,
        clause_count:
            clauses.length
    };
}

/**
 * Construye una representación contextual de un texto, excluyendo citas y
 * cláusulas protegidas y acumulando métricas de protección para auditoría.
 *
 * @param {string} text
 * @returns {object}
 */
function prepareSingleContextRepresentation(text) {
    const sourceText =
        String(text ?? '')
            .replace(/\s+/gu, ' ')
            .trim();

    /*
     * Primero se obtiene el contexto del texto completo; después se calculan las
     * versiones residuales para citas y cláusulas de forma independiente.
     */
    const directScope =
        detectScopeContext(
            sourceText
        );

    const quoted =
        neutralizeProtectedQuotes(
            sourceText
        );

    const clauses =
        neutralizeProtectedClauses(
            quoted.text
        );

    /*
     * La intensidad de protección se calcula por cantidad de unidades excluidas,
     * no por palabras concretas, y se conserva solamente como señal de auditoría.
     */
    const totalProtectionUnits =
        quoted.protected_quote_count
        + clauses.protected_clause_count;

    const denominator =
        Math.max(
            1,
            clauses.clause_count
            + quoted.protected_quote_count
        );

    return {
        text:
            clauses.text,
        source_text:
            sourceText,
        adjusted:
            totalProtectionUnits > 0,
        protection_score:
            roundScore(
                totalProtectionUnits
                / denominator
            ),
        protected_quote_count:
            quoted.protected_quote_count,
        protected_clause_count:
            clauses.protected_clause_count,
        quoted_context:
            quoted.quoted_context,
        reported_context:
            quoted.reported_context
            || clauses.reported_context
            || directScope.reported_context,
        metalinguistic_context:
            quoted.metalinguistic_context
            || clauses.metalinguistic_context
            || directScope.metalinguistic_context,
        rejection_context:
            quoted.rejection_context
            || clauses.rejection_context
            || directScope.rejection_context,
        informational_context:
            directScope.informational_context
    };
}

/**
 * Prepara el contexto global tanto para la versión normalizada como para la
 * deobfuscada y consolida sus indicadores sin alterar el comentario original.
 *
 * @param {object} normalizationBundle
 * @returns {object}
 */
function prepareContextForClassification(
    normalizationBundle
) {
    const normalized =
        prepareSingleContextRepresentation(
            normalizationBundle.normalizedText
        );

    /*
     * Si la deobfuscación no cambió el texto se reutiliza el mismo resultado para
     * evitar trabajo duplicado y mantener coherentes los contadores de contexto.
     */
    const deobfuscated =
        normalizationBundle.deobfuscatedText
        === normalizationBundle.normalizedText
            ? normalized
            : prepareSingleContextRepresentation(
                normalizationBundle.deobfuscatedText
            );

    return {
        normalized_text:
            normalized.text,
        deobfuscated_text:
            deobfuscated.text,
        adjusted:
            normalized.adjusted
            || deobfuscated.adjusted,
        protection_score:
            Math.max(
                normalized.protection_score,
                deobfuscated.protection_score
            ),
        protected_quote_count:
            Math.max(
                normalized.protected_quote_count,
                deobfuscated.protected_quote_count
            ),
        protected_clause_count:
            Math.max(
                normalized.protected_clause_count,
                deobfuscated.protected_clause_count
            ),
        quoted_context:
            normalized.quoted_context
            || deobfuscated.quoted_context,
        reported_context:
            normalized.reported_context
            || deobfuscated.reported_context,
        metalinguistic_context:
            normalized.metalinguistic_context
            || deobfuscated.metalinguistic_context,
        rejection_context:
            normalized.rejection_context
            || deobfuscated.rejection_context,
        informational_context:
            normalized.informational_context
            || deobfuscated.informational_context
    };
}


/* ============================================================================
 * 7. SEÑALES ESTRUCTURALES DE SPAM — INTENCIÓN Y RELACIONES
 * ============================================================================ */

/*
 * Ninguna señal aislada determina SPAM. La decisión estructural se apoya en
 * relaciones entre autoría, oferta, incentivo, CTA y destino externo.
 */
const CONTACT_CHANNEL_PATTERN =
    /\b(?:whats?app|wsp|wpp|wa\b|telegram|tg\b|signal|messenger|instagram|facebook|discord|wechat|line|dm|inbox|privado|mensaje\s+directo|correo|email|e-mail)\b/u;

const CONTACT_ACTION_PATTERN =
    /\b(?:escribime|escribeme|escríbeme|escribinos|escribanme|escríbanme|contactame|contáctame|contactanos|contáctanos|contactenme|contáctenme|contactennos|contáctennos|mandame|mándame|mandanos|mándanos|mandenme|mándenme|manden\s+(?:mensaje|mensajes)|(?:me|nos)\s+mande[n]?\s+(?:mensaje|mensajes|un\s+mensaje)|hablame|háblame|hablanos|háblanos|consultame|consúltame|consultá|buscame|búscame|buscanos|búscanos|consulta\s+por|pedime|pedíme|respondeme|respóndeme|(?:paso|envio|envío|mando|entrego)\s+(?:(?:los?|un|una|el|la|mi|mis)\s+)?(?:detalles|informacion|información|datos|acceso|invitacion|invitación|enlace|link|url|codigo|código|referencia)\s+(?:por|al|a\s+traves\s+de|a\s+través\s+de)\s+(?:whatsapp|telegram|privado|mensaje|chat|dm|inbox)|(?:atiendo|respondo|recibo\s+consultas|tomo\s+(?:pedidos|reservas|inscripciones|consultas))[^.!?;]{0,35}\b(?:por\s+(?:whatsapp|telegram|privado|mensaje|chat|dm|inbox)|en\s+privado)|reply|contact\s+me|message\s+me|dm\s+me|write\s+me|send\s+me\s+(?:a\s+)?(?:message|mensaje|dm))\b/u;

/* Formas cuya tilde identifica claramente un CTA/imperativo. */
const CONTACT_ACTION_GRAMMATICAL_PATTERN =
    /(?:^|[^\p{L}\p{N}_])(?:respondé|escribime|contactá|contactame|mandá|mandame|hablá|hablame|consultá|buscá|buscame|confirmá|validá|completá|usá|aplicá|pagá)(?=$|[^\p{L}\p{N}_])/u;

/*
 * `coordino` (presente, primera persona) y `coordinó` (pasado, tercera persona)
 * sólo se distinguen si conservamos la tilde. Por eso esta relación de contacto
 * se evalúa sobre canonicalizeForGrammar() y nunca sobre el texto sin acentos.
 */
const CONTACT_COORDINATION_GRAMMATICAL_PATTERN =
    /(?<!\bse\s)\bcoordino[^.!?;]{0,35}\b(?:por\s+(?:whatsapp|telegram|privado|mensaje|chat|dm|inbox)|en\s+privado)\b/u;

const CTA_GRAMMATICAL_PATTERN =
    /(?:^|[^\p{L}\p{N}_])(?:respondé|comprá|accedé|registrate|regístrate|entrá|ingresá|visitá|mirá|hacé\s+click|solicitá|reservá|aprovechá|activá|obtené|sumate|unite|depositá|suscribite|descargá|completá|confirmá|validá|mandá|contactá|consultá|buscá|buscame|usá|aplicá|pagá|reclamá|colocá|actualizá|verificá)(?=$|[^\p{L}\p{N}_])/u;

/* `mira` sin tilde sólo cuenta como CTA si introduce un objeto/destino. */
const NON_ACCENTED_MIRA_CTA_PATTERN =
    /(?:^|[.!?;:]\s+|\bpor\s+favor\s+)mira(?=\s+(?:mi|nuestro|nuestra|este|esta|estos|estas|el|la|los|las|aqui|aca|acá|perfil|web|sitio|pagina|página|link|enlace|oferta|promo|canal|grupo)\b)/u;

const CTA_PATTERN =
    /\b(?:llevate|llévate|registrate|regístrate|registrese|regístrese|hac[eé]\s+click|clickea|cliqueá|sumate|súmate|unite|únete|suscribite|suscríbete|etiquetá|claim\b|buy\s+now|sign\s+up|register\s+now|click\s+here|join\s+now|follow\s+now|subscribe\s+now)\b/u;

const PROMOTION_PATTERN =
    /\b(?:oferta|promocion|promoción|descuento|cupon|cupón|bono|beneficio|precio\s+promocional|precio\s+especial|liquidacion|liquidación|rebaja|envio\s+gratis|envío\s+gratis|envio\s+bonificado|envío\s+bonificado|dos\s+por\s+uno|2x1|ultimo[s]?\s+cupos?|último[s]?\s+cupos?|solo\s+por\s+hoy|por\s+tiempo\s+limitado|campana\s+(?:promocional|comercial)|campaña\s+(?:promocional|comercial)|condiciones\s+promocionales|promo\b|sale\b|discount|coupon|limited\s+time|special\s+price|welcome\s+bonus)\b/u;

const OFFER_AUTHORSHIP_PATTERN =
    /\b(?:vendo|vendemos|ofrezco|ofrecemos|brindo|brindamos|hago|hacemos|realizo|realizamos|sumamos|activamos|lanzamos|abrimos|habilitamos|liquidamos|difundo|difundimos|comparto\s+mi|compartimos\s+nuestro|comparto\s+(?:un|una|el|la)\s+(?:grupo|canal|enlace|link|referido|codigo|código|oferta|promocion|promoción|servicio|acceso|curso|membresia|membresía)|difundo\s+(?:un|una|el|la)\s+(?:grupo|canal|enlace|link|referido|codigo|código|oferta|promocion|promoción|servicio|acceso|curso|membresia|membresía)|dejo\s+(?:un|una|mi|nuestro|nuestra)\s+(?:acceso|enlace|link|url|oferta|promocion|promoción|codigo|código|referencia|contacto|grupo|canal|curso|servicio|membresia|membresía)|doy\s+clases|tomamos?\s+trabajos?|administro|administramos|gestiono|gestionamos|(?:estoy|estamos)\s+(?:vendiendo|ofreciendo|promocionando|difundiendo|compartiendo|administrando|gestionando|sumando|incorporando|tomando|dando|habilitando|pasando)|aprovecho[^.!?;]{0,45}\bpara\s+(?:ofrecer|vender|promocionar|compartir|pasar|difundir)|aprovechamos[^.!?;]{0,45}\bpara\s+(?:ofrecer|vender|promocionar|compartir|pasar|difundir)|puedo\s+(?:pasar(?:les|te|le)?|enviar(?:les|te|le)?|compartir(?:les|te|le)?|ofrecer(?:les|te|le)?|dar(?:les|te|le)?)|podemos\s+(?:pasar(?:les|te|le)?|enviar(?:les|te|le)?|compartir(?:les|te|le)?|ofrecer(?:les|te|le)?|dar(?:les|te|le)?)|tengo\s+(?:productos?|stock|vacantes|cupos|un\s+servicio|una\s+oferta|una\s+promocion|una\s+promoción|una\s+propuesta|un\s+acceso|una\s+referencia|un\s+referido|un\s+codigo|un\s+código|un\s+curso|una\s+mentoria|una\s+mentoría|una\s+membresia|una\s+membresía|senales|señales|prestamos|préstamos|creditos|créditos|un\s+metodo|un\s+método|un\s+sistema|un\s+grupo|un\s+canal|un\s+link|un\s+enlace|contenido(?:\s+(?:adulto|hot|privado|exclusivo|\+18))?)|tenemos\s+(?:productos?|stock|vacantes|cupos|servicios?|ofertas?|promociones?|propuestas?|accesos?|referencias?|referidos?|codigos?|códigos?|cursos?|mentorias?|mentorías?|membresias?|membresías?|senales|señales|prestamos|préstamos|creditos|créditos|metodos|métodos|sistemas|grupos|canales|links?|enlaces?|contenido(?:\s+(?:adulto|hot|privado|exclusivo|\+18))?)|we\s+(?:offer|sell|provide|can\s+send|can\s+share)|i\s+(?:offer|sell|provide|have|can\s+send|can\s+share))\b/u;

const COMMERCIAL_OFFER_PATTERN =
    /\b(?:venta|servicio[s]?|producto[s]?|reserva[s]?|catalogo|catálogo|presupuesto|pedido[s]?|stock|envio[s]?|envío[s]?|importado[s]?|asesoria[s]?|asesoría[s]?|curso[s]?|clases\s+particulares|reparacion(?:es)?|reparación(?:es)?|instalacion(?:es)?|instalación(?:es)?|traduccion(?:es)?|traducción(?:es)?|diseno|diseño|logo[s]?|tramite[s]?|trámite[s]?|gestione[s]?|freelance|mentoria[s]?|mentoría[s]?|refinanciacion|refinanciación|propuesta\s+(?:comercial|de\s+ingresos|de\s+inversion|de\s+inversión|laboral)|seguidores|crecimiento\s+de\s+redes|paquetes?\s+de\s+interacciones|interacciones\s+(?:sociales|para\s+redes)|publicidad|marketing|seo\b|posicionamiento|backlinks?|suscripcion|suscripción|membresia|membresía|consultoria|consultoría|acceso|canal\s+premium|grupo\s+pago|contenido\s+(?:adulto|hot|privado|exclusivo|premium|\+18)|perfil\s+(?:adulto|hot|\+18)|suscripcion\s+privada|suscripción\s+privada|acceso\s+(?:privado|vip|premium)|offer|service|deal)\b/u;

const EXTERNAL_DESTINATION_PATTERN =
    /\b(?:mi\s+(?:web|sitio|pagina|página|perfil|bio|canal|grupo|tienda|link|enlace)|nuestra\s+(?:web|pagina|página|tienda|oferta)|nuestro\s+(?:sitio|portal|canal|grupo|link|enlace)|link\s+de\s+la\s+bio|enlace\s+del\s+perfil|visiten\s+(?:mi|nuestro)|dejo\s+(?:mi|nuestro)\s+(?:web|sitio|pagina|página|enlace|link)|comparto\s+(?:mi|nuestra)\s+(?:pagina|página|web|sitio|enlace|link)|(?:detalles|informacion|información|datos|acceso|gestion|gestión|reserva|alta|atencion|atención|propuesta)\s+(?:se\s+)?(?:envian|envían|entregan|coordinan|confirman|continuan|continúan|pasan|realizan|hacen)[^.!?;]{0,30}\b(?:por\s+(?:privado|mensaje|chat|dm|whatsapp|telegram)|fuera\s+del\s+comentario|contacto\s+directo)|private\s+group|my\s+(?:site|link|profile|group)|our\s+(?:site|store|group)|mi\s+(?:onlyfans|fansly)|nuestro\s+(?:onlyfans|fansly))\b/u;

const MONEY_PATTERN =
    /\b(?:gan[aá]|ganar|ganancia[s]?|ingreso[s]?|rentabilidad|retorno[s]?|rendimiento|beneficio[s]?|dinero|plata|usd|dolares|dólares|pesos|usdt|capital|inversion|inversión|profits?|earn(?:ings)?)\b/u;

const MONEY_PROMISE_PATTERN =
    /\b(?:garantizad[oa]s?|asegurad[oa]s?|diari[oa]s?|semanal(?:es)?|por\s+semana|por\s+dia|por\s+día|desde\s+el\s+primer\s+dia|desde\s+el\s+primer\s+día|duplic[aá]|triplic[aá]|multiplic[aá]|sin\s+riesgo|sin\s+experiencia|pocas\s+horas|desde\s+casa|desde\s+tu\s+celular|facil|fácil|rapido|rápido|guaranteed|guaranteed\s+profits?|daily\s+returns?|easy\s+money|from\s+home|no\s+experience)\b/u;

const NEGATED_MONEY_PROMISE_PATTERN =
    /\b(?:no\s+hay\s+(?:ganancias?|rentabilidad|retornos?|beneficios?)\s+garantizad[oa]s?|no\s+(?:garantiza|asegura|promete)\s+(?:ganancias?|rentabilidad|retornos?|beneficios?)|(?:ganancias?|rentabilidad|retornos?)\s+no\s+(?:estan|están|son)\s+garantizad[oa]s?|returns?\s+are\s+not\s+guaranteed|no\s+guaranteed\s+profits?)\b/u;

const JOB_PATTERN =
    /\b(?:trabajo|trabajar|empleo|vacante[s]?|puesto[s]?|incorporamos|buscamos\s+personas|oportunidad\s+laboral|remoto|desde\s+casa|home\s+office|tareas\s+simples|sin\s+experiencia|sin\s+curriculum|sin\s+currículum|ingreso\s+inmediato|incorporacion\s+inmediata|incorporación\s+inmediata|work\s+from\s+home|online\s+job)\b/u;

const INVESTMENT_PATTERN =
    /\b(?:crypto|cripto|criptomoneda[s]?|bitcoin|ethereum|usdt|trading|forex|broker|inversion|inversión|inverti|invertí|senal(?:es)?|señal(?:es)?|bot\s+automatico|bot\s+automático|copy\s*trading|operacion(?:es)?|operación(?:es)?|wallet|token[s]?|trading\s+group)\b/u;

const GAMBLING_PATTERN =
    /\b(?:casino|apuesta[s]?|apostar|sportsbook|betting|tragamonedas|slots?|giros\s+gratis|free\s+spins|deposito|depósito|saldo\s+promocional)\b/u;

const LOAN_PATTERN =
    /\b(?:prestamo[s]?|préstamo[s]?|credito[s]?|crédito[s]?|financiacion|financiación|dinero\s+inmediato|aprobacion|aprobación|cuota[s]?|sin\s+garante|sin\s+recibo|sin\s+bancos|sin\s+tramites|sin\s+trámites|antecedentes\s+crediticios|cheap\s+loans?)\b/u;

const GIVEAWAY_PATTERN =
    /\b(?:premio[s]?|sorteo[s]?|ganador(?:a|es)?|seleccionad[oa]s?|regalo[s]?|recompensa[s]?|gift|winner|prize|giveaway)\b/u;

const CLAIM_PATTERN =
    /\b(?:claim|verify|redeem|reclame|confirme|valide|active|reciba|retire)\b/u;

const CLAIM_GRAMMATICAL_PATTERN =
    /(?:^|[^\p{L}\p{N}_])(?:reclamá|confirmá|validá|activá|cobrá|retirá|completá)(?=$|[^\p{L}\p{N}_])/u;

const AFFILIATE_PATTERN =
    /\b(?:afiliad[oa]|referid[oa]s?|referidos|codigo\s+de\s+referido|código\s+de\s+referido|codigo\s+de\s+invitacion|código\s+de\s+invitación|clave\s+de\s+(?:referido|invitacion|invitación)|referencia\s+(?:personal|comercial|de\s+campana|de\s+campaña|de\s+registro)|vinculo\s+de\s+socio|vínculo\s+de\s+socio|codigo\s+promocional|código\s+promocional|ref\b|referral|affiliate|mi\s+referencia|mi\s+codigo|mi\s+código|mi\s+enlace|nuestro\s+enlace|my\s+referral|my\s+code)\b/u;

/* Negación semántica local para afiliación y menciones comerciales. */
const NEGATED_AFFILIATE_SCOPE_PATTERN =
    /\b(?:sin\s+(?:ningun|ningún|ninguna|un|una)?\s*(?:enlace|link|codigo|código|referido|referencia)[^.!?;]{0,35}\b(?:afiliad[oa]|referid[oa]|promocional)|no\s+(?:hay|incluye|incluyen|contiene|contienen|usa|usan|utiliza|utilizan|tiene|tienen|es|son)[^.!?;]{0,35}\b(?:afiliad[oa]|referid[oa]|codigo\s+promocional|código\s+promocional|enlace\s+de\s+afiliado|link\s+de\s+afiliado))\b/u;

const NEGATED_COMMERCIAL_MENTION_PATTERN =
    /\b(?:no\s+es\s+(?:una\s+)?(?:promocion|promoción|oferta|publicidad|recomendacion|recomendación)(?:\s+comercial)?|no\s+constituye\s+(?:una\s+)?(?:promocion|promoción|oferta|publicidad)|no\s+convierte[^.!?;]{0,55}\ben\s+(?:una\s+)?(?:promocion|promoción|oferta|publicidad|recomendacion|recomendación)|sin\s+(?:promocion|promoción|oferta|publicidad|intencion\s+comercial|intención\s+comercial)|sin\s+ofrecer\s+(?:productos?|servicios?|promociones?|ofertas?))\b/u;

const GENERIC_POSSESSION_PATTERN =
    /\b(?:tengo|tenemos|dispongo|disponemos)\b/u;

const TRANSACTIONAL_FULFILLMENT_PATTERN =
    /\b(?:nuev[oa]s?|disponibles?|en\s+stock|stock|a\s+la\s+venta|para\s+vender|entrega|envio|envío|precio|mayorista|descuento|reserva|financiacion|financiación|pago|transferencia|cuotas?|coordinad[oa]|importad[oa]s?)\b/u;

const URGENCY_PATTERN =
    /\b(?:ahora|hoy|ya\b|urgente|ultimo[s]?\s+cupos?|último[s]?\s+cupos?|antes\s+de\s+medianoche|por\s+tiempo\s+limitado|no\s+te\s+lo\s+pierdas|no\s+dejes\s+pasar|solo\s+por\s+hoy|última\s+oportunidad|last\s+chance|limited\s+time|limited\s+spots)\b/u;

const SOCIAL_GROWTH_PATTERN =
    /\b(?:seguidores|followers|likes|me\s+gusta|views|visualizaciones|suscriptores|engagement|viral|redes\s+sociales|crecimiento\s+de\s+redes|paquetes?\s+de\s+interacciones|interacciones\s+(?:sociales|para\s+redes)|instagram|tiktok|youtube|reproducciones)\b/u;

const SEO_PROMOTION_PATTERN =
    /\b(?:seo|backlinks?|enlaces\s+patrocinados|comprar\s+enlaces|vender\s+enlaces|intercambiar\s+enlaces|autoridad\s+de\s+dominio|trafico\s+web|tráfico\s+web|rankings?|posicionamiento|articulos\s+patrocinados|artículos\s+patrocinados|marketing\s+digital)\b/u;

/*
 * Vocabulario temático adulto. Esta señal por sí sola NO determina spam.
 * Se exige una relación con oferta, autoría, CTA, contacto o destino externo.
 *
 * Incluye términos explícitos (porno, pornografía, XXX, NSFW), marcadores de
 * edad y categorías de acceso/contenido. Las marcas/plataformas se consideran
 * únicamente indicios de destino; una noticia sobre ellas debe seguir limpia.
 */
const ADULT_CONTENT_PATTERN =
    /(?:\b(?:porno|pornografia|pornografía|pornografico|pornográfico|pornografica|pornográfica|porn|xxx|nsfw|adultos?|contenido\s+(?:adulto|hot|erotico|erótico|erotica|erótica|sexual|\+18|18\+)|videos?\s+(?:hot|adultos?|porno|xxx)|fotos?\s+(?:hot|adultas?|xxx)|webcams?|camgirls?|camboys?|sexcams?|perfil\s+(?:hot|adulto|\+18|18\+)|pagina\s+(?:hot|adulta|xxx)|página\s+(?:hot|adulta|xxx)|sitio\s+(?:porno|adulto|xxx)|onlyfans|fansly|pornhub|xvideos|xhamster)\b|(?<!\d)\+18(?!\d)|(?<!\d)18\+(?!\d))/u;

/*
 * Lenguaje de acceso/pago/privacidad frecuente en promoción adulta. Tampoco es
 * suficiente por sí solo: "acceso premium" puede aparecer en otros contextos.
 */
const ADULT_ACCESS_PATTERN =
    /\b(?:contenido\s+(?:privado|exclusivo|premium|vip)|acceso\s+(?:privado|exclusivo|premium|vip)|grupo\s+(?:privado|vip)|canal\s+(?:privado|vip)|suscripcion\s+(?:privada|premium)|suscripción\s+(?:privada|premium)|material\s+(?:privado|exclusivo|adulto)|pack\s+(?:privado|hot|xxx)|link\s+(?:privado|vip|\+18)|enlace\s+(?:privado|vip|\+18)|private\s+content|exclusive\s+content|adult\s+content|premium\s+content)\b/u;

const SECURITY_PRETEXT_PATTERN =
    /\b(?:problema\s+(?:en|de)\s+tu\s+(?:cuenta|perfil|acceso)|cuenta\s+sera\s+suspendida|cuenta\s+será\s+suspendida|(?:cuenta|perfil|correo|dispositivo)\s+(?:requiere|necesita)\s+(?:actualizacion|actualización|validacion|validación|verificacion|verificación)|inicio\s+de\s+sesion\s+sospechoso|inicio\s+de\s+sesión\s+sospechoso|actividad\s+irregular|sesion\s+(?:marcada|detectada)\s+como\s+riesgosa|sesión\s+(?:marcada|detectada)\s+como\s+riesgosa|verificacion\s+de\s+cuenta|verificación\s+de\s+cuenta|validacion\s+de\s+cuenta|validación\s+de\s+cuenta|acceso\s+por\s+vencer|problema\s+de\s+seguridad\s+pendiente|(?:reembolso|saldo\s+a\s+reintegrar|reintegro)\s+(?:pendiente|disponible|por\s+activar|por\s+validar|por\s+confirmar)|devolucion\s+(?:de\s+dinero\s+)?(?:pendiente|sin\s+completar|disponible)|(?:figura|hay|aparece|se\s+registro|se\s+registró|tu\s+(?:perfil|cuenta|usuario)\s+(?:figura|aparece))[^.!?;]{0,40}\b(?:reembolso|saldo\s+a\s+reintegrar|reintegro)\b|(?:paquete|envio|envío|pedido|entrega)\s+(?:pendiente|retenido|bloqueado|sin\s+validar|por\s+validar|por\s+confirmar|con\s+incidencia)|security\s+alert|account\s+suspended|verify\s+your\s+account)\b/u;

const SENSITIVE_DATA_PATTERN =
    /\b(?:contrasena|contraseña|password|codigo\s+recibido|código\s+recibido|codigo\s+de\s+verificacion|código\s+de\s+verificación|(?:tus?|sus?)\s+datos|datos\s+personales|datos\s+bancarios|codigo\s+de\s+acceso|código\s+de\s+acceso|clave\s+de\s+acceso|direccion|dirección|domicilio|tarjeta|cvv|token\s+de\s+seguridad|credenciales?|documento\s+de\s+identidad|verify\s+your\s+password|send\s+your\s+code)\b/u;

const URL_PATTERN =
    /\b(?:https?:\/\/|www\.)[^\s<>()]+|\b(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+(?:com|net|org|io|app|site|shop|store|xyz|online|click|link|me|co|ar|ai|info|biz|example|invalid)(?:\/[^\s<>()]*)?/giu;

const EMAIL_PATTERN =
    /\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b/giu;

const PHONE_PATTERN =
    /(?:\+?\d[\d\s().-]{7,}\d)/gu;

/**
 * Extrae señales estructurales de intención de spam dentro de un único alcance.
 * Combina autoría, CTA, contacto, oferta, incentivos, destinos y dominios temáticos
 * sin utilizar el score neuronal para decidir estas relaciones.
 *
 * @param {string} text
 * @param {object} [scopeContext={}]
 * @returns {object}
 */
function analyzeSpamStructure(
    text,
    scopeContext = {}
) {
    const canonical =
        canonicalizeForStructure(text);

    const grammatical =
        canonicalizeForGrammar(text);

    /* ------------------------------------------------------------------------
     * Fase A: rasgos observables del alcance (URLs, correos, teléfonos y canales).
     * --------------------------------------------------------------------- */
    const urlCount =
        countMatches(
            text,
            URL_PATTERN
        );

    const emailCount =
        countMatches(
            text,
            EMAIL_PATTERN
        );

    const phoneCount =
        countMatches(
            text,
            PHONE_PATTERN
        );

    const hasContactChannel =
        CONTACT_CHANNEL_PATTERN.test(canonical);

    /* ------------------------------------------------------------------------
     * Fase B: intención dirigida al lector. Se combinan formas inequívocas,
     * imperativos con tilde y formas ambiguas que necesitan contexto adicional.
     * --------------------------------------------------------------------- */
    const hasPluralImperative =
        PLURAL_IMPERATIVE_GRAMMAR_PATTERN.test(
            grammatical
        );

    const hasStrongContactAction =
        CONTACT_ACTION_PATTERN.test(canonical)
        || CONTACT_ACTION_GRAMMATICAL_PATTERN.test(
            grammatical
        )
        || CONTACT_COORDINATION_GRAMMATICAL_PATTERN.test(
            grammatical
        )
        || NON_ACCENTED_DIRECTED_ACTION_PATTERN.test(
            grammatical
        )
        || hasPluralImperative;

    const hasStrongCta =
        CTA_PATTERN.test(canonical)
        || CTA_GRAMMATICAL_PATTERN.test(
            grammatical
        )
        || NON_ACCENTED_MIRA_CTA_PATTERN.test(
            grammatical
        )
        || NON_ACCENTED_DIRECTED_ACTION_PATTERN.test(
            grammatical
        )
        || hasPluralImperative;

    const hasAmbiguousVoseoAction =
        AMBIGUOUS_VOSEO_ACTION_PATTERN.test(
            grammatical
        );

    const firstPersonNarrative =
        FIRST_PERSON_NARRATIVE_PATTERN.test(
            grammatical
        );

    const readerDirectedCue =
        READER_DIRECTED_CUE_PATTERN.test(
            canonical
        );

    const negatedCommercialMention =
        NEGATED_COMMERCIAL_MENTION_PATTERN.test(
            canonical
        );

    const rawHasPromotion =
        PROMOTION_PATTERN.test(canonical);

    const rawHasOfferAuthorship =
        OFFER_AUTHORSHIP_PATTERN.test(canonical);

    const rawHasSecurityPretext =
        SECURITY_PRETEXT_PATTERN.test(canonical);

    /*
     * `Pedí`, `Recibí`, `Seguí`, `Invertí`, `Abrí`, `Escribí` y `Conseguí` no son CTA por
     * sí solas: en rioplatense también son pasado de primera persona. Sólo se
     * interpretan como acción dirigida al lector cuando existe un destino,
     * oferta/pretexto o señal de segunda persona y no domina una narración en
     * pasado.
     */
    const ambiguousDirectedAction =
        hasAmbiguousVoseoAction
        && !firstPersonNarrative
        && (
            readerDirectedCue
            || rawHasPromotion
            || rawHasOfferAuthorship
            || rawHasSecurityPretext
            || urlCount > 0
            || hasContactChannel
        );

    const hasContactAction =
        hasStrongContactAction
        || (
            ambiguousDirectedAction
            && /(?:^|[^\p{L}\p{N}_])(?:pedí|escribí)(?=$|[^\p{L}\p{N}_])/u.test(
                grammatical
            )
        );

    const hasCta =
        hasStrongCta
        || ambiguousDirectedAction;

    /* ------------------------------------------------------------------------
     * Fase C: oferta y transacción. Posesión genérica (`tengo`, `tenemos`) sólo
     * cuenta cuando aparece junto a entrega, precio, stock, reserva u otra señal.
     * --------------------------------------------------------------------- */
    const hasTransactionalFulfillment =
        TRANSACTIONAL_FULFILLMENT_PATTERN.test(
            canonical
        );

    const genericPossessiveOffer =
        GENERIC_POSSESSION_PATTERN.test(canonical)
        && hasTransactionalFulfillment
        && (
            hasContactAction
            || hasCta
            || rawHasPromotion
        );

    const hasPromotion =
        rawHasPromotion
        && !(
            negatedCommercialMention
            && !rawHasOfferAuthorship
            && !genericPossessiveOffer
        );

    const hasOfferAuthorship =
        rawHasOfferAuthorship
        || genericPossessiveOffer;

    const hasCommercialOffer =
        (
            COMMERCIAL_OFFER_PATTERN.test(canonical)
            || genericPossessiveOffer
        )
        && !(
            negatedCommercialMention
            && !hasOfferAuthorship
        );

    const hasExternalDestination =
        EXTERNAL_DESTINATION_PATTERN.test(canonical);

    /* ------------------------------------------------------------------------
     * Fase D: dominios temáticos. Dinero, empleo, inversión, apuestas, préstamos,
     * sorteos y afiliación aportan contexto, pero ninguno decide spam aislado.
     * --------------------------------------------------------------------- */
    const hasMoney =
        MONEY_PATTERN.test(canonical);

    const hasMoneyPromise =
        MONEY_PROMISE_PATTERN.test(canonical)
        && !NEGATED_MONEY_PROMISE_PATTERN.test(canonical);

    const hasJob =
        JOB_PATTERN.test(canonical);

    const hasInvestment =
        INVESTMENT_PATTERN.test(canonical);

    const hasGambling =
        GAMBLING_PATTERN.test(canonical);

    const hasLoan =
        LOAN_PATTERN.test(canonical);

    const hasGiveaway =
        GIVEAWAY_PATTERN.test(canonical);

    const hasClaim =
        CLAIM_PATTERN.test(canonical)
        || CLAIM_GRAMMATICAL_PATTERN.test(
            grammatical
        )
        || (
            NON_ACCENTED_DIRECTED_ACTION_PATTERN.test(
                grammatical
            )
            && (
                hasGiveaway
                || rawHasSecurityPretext
            )
        );

    const negatedAffiliate =
        NEGATED_AFFILIATE_SCOPE_PATTERN.test(
            canonical
        );

    const hasAffiliate =
        AFFILIATE_PATTERN.test(canonical)
        && !negatedAffiliate;

    const hasUrgency =
        URGENCY_PATTERN.test(canonical);

    const hasSocialGrowth =
        SOCIAL_GROWTH_PATTERN.test(canonical);

    const hasSeoPromotion =
        SEO_PROMOTION_PATTERN.test(canonical);

    const hasAdultContent =
        ADULT_CONTENT_PATTERN.test(canonical);

    const hasAdultAccess =
        ADULT_ACCESS_PATTERN.test(canonical);

    const hasSecurityPretext =
        rawHasSecurityPretext;

    const hasSensitiveData =
        SENSITIVE_DATA_PATTERN.test(canonical);

    /* ------------------------------------------------------------------------
     * Fase G: relaciones de intención. Aquí se decide si CTA, oferta, autoría y
     * destino pertenecen al mismo emisor/alcance y por tanto se refuerzan entre sí.
     * --------------------------------------------------------------------- */
    const authorialSolicitation =
        AUTHORIAL_SOLICITATION_PATTERN.test(canonical)
        || hasOfferAuthorship;

    /* ------------------------------------------------------------------------
     * Fase E: protecciones locales heredadas del análisis contextual del alcance.
     * Se evalúan antes de convertir relaciones semánticas en scores fuertes.
     * --------------------------------------------------------------------- */
    const scopeReported =
        Boolean(scopeContext.reported_context);

    const scopeMeta =
        Boolean(scopeContext.metalinguistic_context);

    const scopeRejection =
        Boolean(scopeContext.rejection_context);

    const personalExperienceContext =
        PERSONAL_EXPERIENCE_CONTEXT_PATTERN.test(
            grammatical
        );

    const scopeInformational =
        Boolean(scopeContext.informational_context)
        || INFORMATIONAL_DISCUSSION_PATTERN.test(canonical)
        || ANALYTICAL_DISCUSSION_PATTERN.test(canonical)
        || personalExperienceContext;

    const contactSignal =
        clamp01(
            (hasContactAction ? 0.62 : 0)
            + (hasContactChannel ? 0.22 : 0)
            + (phoneCount > 0 ? 0.32 : 0)
            + (emailCount > 0 ? 0.18 : 0)
        );

    const ctaSignal =
        clamp01(
            (hasCta ? 0.68 : 0)
            + (hasUrgency ? 0.16 : 0)
            + (hasClaim ? 0.18 : 0)
        );

    const authorshipSignal =
        clamp01(
            (hasOfferAuthorship ? 0.72 : 0)
            + (authorialSolicitation ? 0.22 : 0)
            + (hasExternalDestination ? 0.16 : 0)
        );

    const offerSignal =
        clamp01(
            (hasCommercialOffer ? 0.46 : 0)
            + (hasPromotion ? 0.36 : 0)
            + (hasJob ? 0.30 : 0)
            + (hasInvestment ? 0.30 : 0)
            + (hasGambling ? 0.34 : 0)
            + (hasLoan ? 0.34 : 0)
            + (hasGiveaway ? 0.28 : 0)
            + (hasSocialGrowth ? 0.28 : 0)
            + (hasSeoPromotion ? 0.30 : 0)
            + (
                hasAdultContent
                && (
                    hasAdultAccess
                    || hasOfferAuthorship
                    || hasPromotion
                )
                    ? 0.34
                    : 0
            )
        );

    const destinationSignal =
        clamp01(
            (urlCount > 0 ? 0.45 : 0)
            + (hasExternalDestination ? 0.38 : 0)
            + (hasContactAction ? 0.38 : 0)
            + (hasContactChannel ? 0.18 : 0)
            + (phoneCount > 0 ? 0.28 : 0)
            + (emailCount > 0 ? 0.16 : 0)
        );

    const incentiveSignal =
        clamp01(
            (hasPromotion ? 0.42 : 0)
            + (hasMoneyPromise ? 0.44 : 0)
            + (hasClaim ? 0.22 : 0)
            + (hasUrgency ? 0.16 : 0)
        );

    const moneyPromiseSignal =
        clamp01(
            (hasMoney ? 0.38 : 0)
            + (hasMoneyPromise ? 0.50 : 0)
            + (hasContactAction || hasCta ? 0.10 : 0)
        );

    const investmentPitchSignal =
        clamp01(
            (hasInvestment ? 0.48 : 0)
            + (hasMoneyPromise ? 0.28 : 0)
            + (hasContactAction || hasCta ? 0.22 : 0)
            + (authorialSolicitation ? 0.12 : 0)
        );

    const jobPitchSignal =
        clamp01(
            (hasJob ? 0.46 : 0)
            + (hasMoneyPromise ? 0.25 : 0)
            + (hasContactAction || hasCta ? 0.22 : 0)
            + (authorialSolicitation ? 0.12 : 0)
        );

    const gamblingSignal =
        clamp01(
            (hasGambling ? 0.54 : 0)
            + (hasPromotion || hasClaim ? 0.20 : 0)
            + (hasCta || hasContactAction ? 0.20 : 0)
        );

    const loanSignal =
        clamp01(
            (hasLoan ? 0.56 : 0)
            + (hasMoneyPromise ? 0.14 : 0)
            + (hasContactAction || hasCta ? 0.22 : 0)
        );

    const giveawaySignal =
        clamp01(
            (hasGiveaway ? 0.54 : 0)
            + (hasClaim ? 0.22 : 0)
            + (hasCta || hasContactAction ? 0.20 : 0)
        );

    const affiliateSignal =
        clamp01(
            (hasAffiliate ? 0.66 : 0)
            + (urlCount > 0 ? 0.18 : 0)
            + (hasCta ? 0.16 : 0)
        );

    const linkSignal =
        clamp01(
            Math.min(0.52, urlCount * 0.20)
            + (hasCta ? 0.18 : 0)
            + (hasAffiliate || hasPromotion ? 0.18 : 0)
            + (hasExternalDestination ? 0.16 : 0)
        );

    const socialGrowthSignal =
        clamp01(
            (hasSocialGrowth ? 0.44 : 0)
            + (hasCommercialOffer || hasOfferAuthorship ? 0.28 : 0)
            + (hasContactAction || hasCta ? 0.20 : 0)
        );

    const seoPromotionSignal =
        clamp01(
            (hasSeoPromotion ? 0.54 : 0)
            + (urlCount > 0 || hasExternalDestination ? 0.22 : 0)
            + (hasCta || authorialSolicitation ? 0.22 : 0)
        );

    /*
     * La temática adulta aislada pesa poco. El score sube únicamente cuando el
     * mismo alcance muestra una relación de promoción, captación o desvío.
     */
    const adultContentSignal =
        clamp01(
            (hasAdultContent ? 0.34 : 0)
            + (hasAdultAccess ? 0.20 : 0)
            + (hasOfferAuthorship || authorialSolicitation ? 0.24 : 0)
            + (hasCta || hasContactAction ? 0.24 : 0)
            + (
                urlCount > 0
                || hasExternalDestination
                || hasContactChannel
                    ? 0.20
                    : 0
            )
            + (hasPromotion ? 0.10 : 0)
        );

    const adultPromotion =
        hasAdultContent
        && (
            hasOfferAuthorship
            || authorialSolicitation
            || hasAdultAccess
        )
        && (
            hasCta
            || hasContactAction
            || urlCount > 0
            || hasExternalDestination
            || hasContactChannel
        );

    const credentialHarvestingSignal =
        clamp01(
            (hasSecurityPretext ? 0.42 : 0)
            + (hasSensitiveData ? 0.42 : 0)
            + (hasCta || hasContactAction || urlCount > 0 ? 0.26 : 0)
        );

    /* ------------------------------------------------------------------------
     * Fase F: construcción de señales continuas. Cada score representa una
     * relación semántica distinta para que la fusión pueda auditarlas por separado.
     * --------------------------------------------------------------------- */
    const solicitationSignal =
        clamp01(
            (hasContactAction ? 0.38 : 0)
            + (hasCta ? 0.34 : 0)
            + (authorialSolicitation ? 0.38 : 0)
            + (offerSignal >= 0.45 ? 0.18 : 0)
        );

    /*
     * Una promoción declarada como propia ya es autopromoción aunque no agregue
     * una segunda CTA. Se exige simultáneamente autoría de oferta + incentivo
     * promocional y que el alcance no esté protegido como cita/reporte/rechazo.
     *
     * Esto cubre relaciones productivas como `dejo mi promoción`, `comparto mi
     * oferta` o `difundo nuestra promo` sin convertir una mención periodística de
     * una promoción ajena en autoría del comentarista.
     */
    const explicitAuthorialPromotion =
        hasOfferAuthorship
        && hasPromotion
        && !scopeReported
        && !scopeMeta
        && !scopeRejection;

    const selfPromotion =
        authorialSolicitation
        && (
            offerSignal >= 0.38
            || explicitAuthorialPromotion
        )
        && (
            hasContactAction
            || hasCta
            || destinationSignal >= 0.40
            || incentiveSignal >= 0.35
            || explicitAuthorialPromotion
            /*
             * Una oferta comercial explícitamente autoral (por ejemplo, "vendo",
             * "ofrezco" o "tengo un curso") ya constituye autopromoción aunque el
             * emisor no agregue una segunda llamada a la acción. Las protecciones
             * de cita/reporte siguen aplicándose más abajo por alcance.
             */
            || (
                hasOfferAuthorship
                && offerSignal >= 0.50
            )
        );

    const commercialSolicitation =
        offerSignal >= 0.45
        && (
            solicitationSignal >= 0.48
            || destinationSignal >= 0.52
        );

    const directCommercialLink =
        urlCount > 0
        && (
            hasCta
            || hasAffiliate
            || hasExternalDestination
            || authorialSolicitation
        )
        && (
            offerSignal >= 0.32
            || incentiveSignal >= 0.32
            || hasSeoPromotion
            || adultPromotion
        );

    const trafficDiversion =
        (
            urlCount > 0
            || hasExternalDestination
        )
        && (
            hasCta
            || authorialSolicitation
            || hasAffiliate
            || hasSeoPromotion
        );

    const strongMoneySolicitation =
        hasMoney
        && hasMoneyPromise
        && (
            hasContactAction
            || hasCta
            || authorialSolicitation
        );

    const credentialHarvesting =
        credentialHarvestingSignal >= 0.82;

    const promotionalCta =
        hasPromotion
        && hasCta;

    const offerCta =
        hasCommercialOffer
        && hasCta;

    /*
     * Relación fuerte independiente del modelo: el emisor intenta captar al
     * lector y, en el mismo alcance, existe una oferta/incentivo/dominio de spam.
     * Esta señal permite rescatar falsos negativos neuronales sin convertir un
     * simple `escribime` o una mención temática aislada en spam.
     */
    const domainIntentSignal =
        Math.max(
            investmentPitchSignal,
            jobPitchSignal,
            gamblingSignal,
            loanSignal,
            giveawaySignal,
            affiliateSignal,
            socialGrowthSignal,
            seoPromotionSignal,
            adultContentSignal,
            credentialHarvestingSignal,
            moneyPromiseSignal,
            offerSignal,
            incentiveSignal
        );

    const multiActionSolicitation =
        hasContactAction
        && hasCta
        && (
            hasOfferAuthorship
            || hasCommercialOffer
            || hasPromotion
            || hasSecurityPretext
            || domainIntentSignal >= 0.30
        );

    const strongRelationalSolicitation =
        (
            (
                authorialSolicitation
                || hasContactAction
                || hasCta
            )
            && (
                contactSignal >= 0.52
                || destinationSignal >= 0.48
                || solicitationSignal >= 0.62
            )
            && domainIntentSignal >= 0.30
        )
        || multiActionSolicitation;

    const strongRelationalSolicitationUnprotected =
        strongRelationalSolicitation
        && !scopeReported
        && !scopeMeta
        && !scopeRejection;

    const directImperativeOffer =
        hasCta
        && (
            offerSignal >= 0.46
            || incentiveSignal >= 0.42
            || domainIntentSignal >= 0.54
        )
        && !scopeReported
        && !scopeMeta
        && !scopeRejection;

    /* ------------------------------------------------------------------------
     * Fase H: score estructural base y pisos por relaciones fuertes. El máximo
     * conserva la evidencia más concluyente sin sumar señales correlacionadas.
     * --------------------------------------------------------------------- */
    let structuralSpamSignal =
        Math.max(
            investmentPitchSignal * 0.92,
            jobPitchSignal * 0.92,
            gamblingSignal * 0.94,
            loanSignal * 0.94,
            giveawaySignal * 0.92,
            affiliateSignal * 0.96,
            socialGrowthSignal * 0.94,
            seoPromotionSignal * 0.96,
            adultContentSignal * 0.96,
            credentialHarvestingSignal,
            solicitationSignal * 0.84,
            offerSignal * 0.70,
            destinationSignal * 0.55,
            incentiveSignal * 0.56,
            moneyPromiseSignal * 0.70
        );

    if (promotionalCta || offerCta) {
        structuralSpamSignal = Math.max(
            structuralSpamSignal,
            0.90
        );
    }

    if (strongRelationalSolicitationUnprotected) {
        structuralSpamSignal = Math.max(
            structuralSpamSignal,
            0.92
        );
    }

    if (directImperativeOffer) {
        structuralSpamSignal = Math.max(
            structuralSpamSignal,
            0.90
        );
    }

    if (selfPromotion) {
        structuralSpamSignal = Math.max(
            structuralSpamSignal,
            0.94
        );
    }

    if (commercialSolicitation) {
        structuralSpamSignal = Math.max(
            structuralSpamSignal,
            0.90
        );
    }

    if (directCommercialLink) {
        structuralSpamSignal = Math.max(
            structuralSpamSignal,
            0.95
        );
    }

    if (
        trafficDiversion
        && (
            offerSignal >= 0.30
            || (
                hasExternalDestination
                && hasCta
            )
        )
    ) {
        structuralSpamSignal = Math.max(
            structuralSpamSignal,
            0.90
        );
    }

    if (strongMoneySolicitation) {
        structuralSpamSignal = Math.max(
            structuralSpamSignal,
            0.95
        );
    }

    if (credentialHarvesting) {
        structuralSpamSignal = Math.max(
            structuralSpamSignal,
            0.96
        );
    }

    if (adultPromotion) {
        structuralSpamSignal = Math.max(
            structuralSpamSignal,
            0.94
        );
    }

    /* ------------------------------------------------------------------------
     * Fase I: aplicación de contexto protector. Sólo reduce estructura cuando no
     * queda autoría, captación ni una relación comercial fuerte en el mismo alcance.
     * --------------------------------------------------------------------- */
    const informationalContext =
        scopeInformational
        && !authorialSolicitation
        && !hasContactAction
        && !hasCta
        && !hasOfferAuthorship
        && !hasExternalDestination;

    const protectedScope =
        scopeReported
        || scopeMeta
        || scopeRejection;

    /*
     * Una explicación, cita, denuncia o rechazo puede contener todo el léxico del
     * spam. Si el alcance no conserva autoría/captación, se limita la estructura.
     */
    if (
        protectedScope
        && !authorialSolicitation
        && !selfPromotion
        && !credentialHarvesting
        && !strongRelationalSolicitationUnprotected
        && !directImperativeOffer
    ) {
        structuralSpamSignal = Math.min(
            structuralSpamSignal,
            scopeRejection ? 0.16 : 0.26
        );
    } else if (
        informationalContext
        && !authorialSolicitation
        && !commercialSolicitation
    ) {
        structuralSpamSignal = Math.min(
            structuralSpamSignal,
            0.30
        );
    }

    /*
     * Canal, URL, dinero o producto aislados no constituyen intención de spam.
     */
    if (
        !hasContactAction
        && !hasCta
        && !authorialSolicitation
        && !hasPromotion
        && !hasMoneyPromise
        && !hasClaim
        && !hasAffiliate
        && !hasExternalDestination
        && !hasSecurityPretext
    ) {
        structuralSpamSignal = Math.min(
            structuralSpamSignal,
            0.30
        );
    }

    /* ------------------------------------------------------------------------
     * Fase J: etiquetas explicativas para auditoría. No intervienen en el score;
     * describen qué relaciones llevaron a la evidencia estructural final.
     * --------------------------------------------------------------------- */
    const spamTypes = [];

    if (promotionalCta) spamTypes.push('promotional-cta');
    if (strongRelationalSolicitationUnprotected) spamTypes.push('relational-solicitation');
    if (directImperativeOffer) spamTypes.push('imperative-offer');
    if (selfPromotion) spamTypes.push('self-promotion');
    if (commercialSolicitation) spamTypes.push('commercial-solicitation');
    if (strongMoneySolicitation) spamTypes.push('money-promise');
    if (investmentPitchSignal >= 0.70) spamTypes.push('investment-pitch');
    if (jobPitchSignal >= 0.70) spamTypes.push('job-pitch');
    if (gamblingSignal >= 0.70) spamTypes.push('gambling-promotion');
    if (loanSignal >= 0.70) spamTypes.push('loan-solicitation');
    if (giveawaySignal >= 0.70) spamTypes.push('giveaway-claim');
    if (affiliateSignal >= 0.70) spamTypes.push('affiliate-referral');
    if (directCommercialLink) spamTypes.push('commercial-link');
    if (trafficDiversion) spamTypes.push('traffic-diversion');
    if (socialGrowthSignal >= 0.70) spamTypes.push('social-growth-offer');
    if (seoPromotionSignal >= 0.70) spamTypes.push('seo-promotion');
    if (adultPromotion) spamTypes.push('adult-content-promotion');
    if (credentialHarvesting) spamTypes.push('credential-harvesting');
    if (hasContactAction && hasContactChannel) spamTypes.push('contact-request');

    return {
        structural_spam_signal:
            roundScore(structuralSpamSignal),
        spam_types:
            [...new Set(spamTypes)],
        solicitation_signal:
            roundScore(solicitationSignal),
        contact_signal:
            roundScore(contactSignal),
        cta_signal:
            roundScore(ctaSignal),
        promotion_signal:
            roundScore(
                clamp01(
                    (hasPromotion ? 0.58 : 0)
                    + (hasCommercialOffer ? 0.34 : 0)
                    + (hasOfferAuthorship ? 0.18 : 0)
                )
            ),
        offer_signal:
            roundScore(offerSignal),
        destination_signal:
            roundScore(destinationSignal),
        authorship_signal:
            roundScore(authorshipSignal),
        incentive_signal:
            roundScore(incentiveSignal),
        money_promise_signal:
            roundScore(moneyPromiseSignal),
        investment_pitch_signal:
            roundScore(investmentPitchSignal),
        job_pitch_signal:
            roundScore(jobPitchSignal),
        gambling_signal:
            roundScore(gamblingSignal),
        loan_signal:
            roundScore(loanSignal),
        giveaway_signal:
            roundScore(giveawaySignal),
        affiliate_signal:
            roundScore(affiliateSignal),
        link_signal:
            roundScore(linkSignal),
        social_growth_signal:
            roundScore(socialGrowthSignal),
        seo_promotion_signal:
            roundScore(seoPromotionSignal),
        adult_content_signal:
            roundScore(adultContentSignal),
        adult_promotion:
            adultPromotion,
        credential_harvesting_signal:
            roundScore(credentialHarvestingSignal),
        authorial_solicitation:
            authorialSolicitation,
        self_promotion:
            selfPromotion,
        commercial_solicitation:
            commercialSolicitation,
        strong_relational_solicitation:
            strongRelationalSolicitationUnprotected,
        multi_action_solicitation:
            multiActionSolicitation,
        direct_imperative_offer:
            directImperativeOffer,
        direct_commercial_link:
            directCommercialLink,
        traffic_diversion:
            trafficDiversion,
        strong_money_solicitation:
            strongMoneySolicitation,
        credential_harvesting:
            credentialHarvesting,
        scope_protected:
            protectedScope,
        scope_reported:
            scopeReported,
        scope_metalinguistic:
            scopeMeta,
        scope_rejection:
            scopeRejection,
        informational_context:
            informationalContext,
        url_count:
            urlCount,
        email_count:
            emailCount,
        phone_count:
            phoneCount,
        has_contact_channel:
            hasContactChannel,
        has_contact_action:
            hasContactAction,
        has_cta:
            hasCta,
        has_promotion:
            hasPromotion,
        has_commercial_offer:
            hasCommercialOffer,
        has_money:
            hasMoney,
        has_money_promise:
            hasMoneyPromise,
        has_job:
            hasJob,
        has_investment:
            hasInvestment,
        has_gambling:
            hasGambling,
        has_loan:
            hasLoan,
        has_giveaway:
            hasGiveaway,
        has_affiliate:
            hasAffiliate,
        negated_affiliate:
            negatedAffiliate,
        negated_commercial_mention:
            negatedCommercialMention,
        generic_possessive_offer:
            genericPossessiveOffer
    };
}


/* ============================================================================
 * 8. MODELO NEURONAL — SOFTMAX, CACHE Y BATCHES
 * ============================================================================ */

/**
 * Convierte logits del modelo en probabilidades normalizadas mediante softmax
 * numéricamente estable.
 *
 * @param {number[]} values
 * @returns {number[]}
 */
function softmax(values) {
    const maxValue =
        Math.max(...values);

    const exponentials =
        values.map(
            (value) =>
                Math.exp(
                    value - maxValue
                )
        );

    const total =
        exponentials.reduce(
            (sum, value) =>
                sum + value,
            0
        );

    return exponentials.map(
        (value) =>
            value / total
    );
}

/**
 * Normaliza las etiquetas declaradas por el modelo a las dos clases públicas
 * utilizadas por TRAMA: `spam` y `not_spam`.
 *
 * @param {string} label
 * @returns {string}
 */
function normalizeModelLabel(label) {
    return String(label ?? '')
        .trim()
        .toLocaleLowerCase('en')
        .replace(/[\s-]+/gu, '_');
}

/**
 * Construye la salida neuronal de una fila del batch ONNX.
 *
 * @param {number[]} probabilities
 * @returns {object}
 */
function buildModelResult(probabilities) {
    const rawScores = {};

    probabilities.forEach(
        (probability, index) => {
            const rawLabel =
                modelConfig.id2label?.[
                    String(index)
                ]
                ?? `label_${index}`;

            rawScores[
                normalizeModelLabel(
                    rawLabel
                )
            ] = probability;
        }
    );

    /*
     * El modelo elegido utiliza not_spam/spam. Los fallbacks de índice permiten
     * detectar una configuración defectuosa de etiquetas sin perder la salida.
     */
    const spamScore =
        Number(
            rawScores.spam
            ?? probabilities[1]
            ?? 0
        );

    const cleanScore =
        Number(
            rawScores.not_spam
            ?? rawScores.notspam
            ?? probabilities[0]
            ?? (1 - spamScore)
        );

    return {
        classification:
            spamScore >= cleanScore
                ? 'spam'
                : 'not_spam',
        spam_score:
            clamp01(spamScore),
        scores: {
            not_spam:
                clamp01(cleanScore),
            spam:
                clamp01(spamScore)
        }
    };
}

/*
 * Cache LRU compartida durante toda la vida del proceso Node.
 */
const modelInferenceCache = new Map();

/**
 * Recupera una inferencia exacta de la cache LRU y renueva su posición para que
 * los elementos usados recientemente permanezcan disponibles.
 *
 * @param {string} text
 * @returns {object|null}
 */
function getCachedModelResult(text) {
    if (!modelInferenceCache.has(text)) {
        return null;
    }

    const result =
        modelInferenceCache.get(text);

    modelInferenceCache.delete(text);
    modelInferenceCache.set(
        text,
        result
    );

    return result;
}

/**
 * Inserta o actualiza una inferencia en la cache LRU y elimina las entradas más
 * antiguas cuando se supera el límite configurado.
 *
 * @param {string} text
 * @param {object} result
 * @returns {void}
 */
function setCachedModelResult(
    text,
    result
) {
    if (modelInferenceCache.has(text)) {
        modelInferenceCache.delete(text);
    }

    modelInferenceCache.set(
        text,
        result
    );

    while (
        modelInferenceCache.size
        > MODEL_INFERENCE_CACHE_MAX
    ) {
        const oldestKey =
            modelInferenceCache
                .keys()
                .next()
                .value;

        if (oldestKey === undefined) {
            break;
        }

        modelInferenceCache.delete(
            oldestKey
        );
    }
}

/**
 * Tokeniza y aplica truncamiento defensivo preservando el último token especial
 * cuando la secuencia supera el máximo aceptado por DistilBERT.
 *
 * @param {string} text
 * @returns {object}
 */
function encodeText(text) {
    const encoded =
        tokenizer.encode(
            String(text ?? '')
        );

    if (
        encoded.ids.length
        <= MODEL_MAX_TOKENS
    ) {
        return encoded;
    }

    const keepFront =
        MODEL_MAX_TOKENS - 1;

    const ids = [
        ...encoded.ids.slice(
            0,
            keepFront
        ),
        encoded.ids[
            encoded.ids.length - 1
        ]
    ];

    const attentionMask = [
        ...encoded.attention_mask.slice(
            0,
            keepFront
        ),
        encoded.attention_mask[
            encoded.attention_mask.length - 1
        ] ?? 1
    ];

    return {
        ...encoded,
        ids,
        attention_mask:
            attentionMask
    };
}

/**
 * Ejecuta una sola llamada ONNX para varias secuencias tokenizadas.
 *
 * Las secuencias se rellenan solamente hasta la longitud máxima del lote. Si el
 * export ONNX no admite batch dinámico, la función degrada a inferencias de una
 * secuencia sin alterar la lógica de clasificación.
 *
 * @param {Array<object>} encodedEntries
 * @returns {Promise<{results: object[], run_count: number}>}
 */
async function inferEncodedBatch(
    encodedEntries
) {
    if (!encodedEntries.length) {
        return {
            results: [],
            run_count: 0
        };
    }

    /*
     * Se calcula una única longitud máxima por lote para minimizar padding sin
     * modificar el orden lógico de las entradas recibidas.
     */
    const batchSize =
        encodedEntries.length;

    const maxSequenceLength =
        Math.max(
            ...encodedEntries.map(
                (entry) =>
                    entry.encoded.ids.length
            )
        );

    /*
     * Los tensores se inicializan con PAD y luego se copian fila por fila los IDs
     * y máscaras reales de cada secuencia tokenizada.
     */
    const inputIds =
        new BigInt64Array(
            batchSize
            * maxSequenceLength
        );

    const attentionMask =
        new BigInt64Array(
            batchSize
            * maxSequenceLength
        );

    inputIds.fill(
        BigInt(PAD_TOKEN_ID)
    );

    encodedEntries.forEach(
        (entry, rowIndex) => {
            const ids =
                entry.encoded.ids;

            const mask =
                entry.encoded.attention_mask;

            const rowOffset =
                rowIndex
                * maxSequenceLength;

            for (
                let tokenIndex = 0;
                tokenIndex < ids.length;
                tokenIndex += 1
            ) {
                inputIds[
                    rowOffset + tokenIndex
                ] = BigInt(
                    ids[tokenIndex]
                );

                attentionMask[
                    rowOffset + tokenIndex
                ] = BigInt(
                    mask[tokenIndex]
                    ?? 1
                );
            }
        }
    );

    /*
     * Los nombres reales de entradas se toman del modelo ONNX para tolerar exports
     * que nombren los tensores de forma ligeramente distinta.
     */
    let result;

    /*
     * Una ejecución ONNX equivale a un batch físico. Si el modelo rechaza batch
     * dinámico, el fallback inferior repite la misma lógica con batch=1.
     */
    try {
        result = await session.run({
            input_ids:
                new ort.Tensor(
                    'int64',
                    inputIds,
                    [
                        batchSize,
                        maxSequenceLength
                    ]
                ),
            attention_mask:
                new ort.Tensor(
                    'int64',
                    attentionMask,
                    [
                        batchSize,
                        maxSequenceLength
                    ]
                )
        });
    } catch (error) {
        if (batchSize === 1) {
            throw error;
        }

        const fallbackResults = [];
        let fallbackRunCount = 0;

        for (const entry of encodedEntries) {
            const singleInference =
                await inferEncodedBatch([
                    entry
                ]);

            fallbackResults.push(
                singleInference.results[0]
            );

            fallbackRunCount +=
                singleInference.run_count;
        }

        return {
            results:
                fallbackResults,
            run_count:
                fallbackRunCount
        };
    }

    /*
     * Los logits se reconstruyen por fila y se transforman a probabilidades antes
     * de mapear cada resultado a `spam` / `not_spam`.
     */
    const outputTensor =
        result.logits
        ?? Object.values(result)[0];

    const logits =
        Array.from(
            outputTensor.data
        );

    const logitsDimensions =
        Array.from(
            outputTensor.dims
            ?? []
        );

    const classCount =
        Number(
            logitsDimensions[
                logitsDimensions.length - 1
            ]
            ?? Object.keys(
                modelConfig.id2label
                ?? {}
            ).length
            ?? 2
        ) || 2;

    return {
        results:
            encodedEntries.map(
                (_, rowIndex) => {
                    const start =
                        rowIndex
                        * classCount;

                    const rowLogits =
                        logits.slice(
                            start,
                            start + classCount
                        );

                    return buildModelResult(
                        softmax(rowLogits)
                    );
                }
            ),
        run_count: 1
    };
}

/**
 * Resuelve una colección de textos mediante cache LRU + deduplicación exacta +
 * batches ONNX ordenados por longitud.
 *
 * @param {string[]} texts
 * @returns {Promise<object>}
 */
async function inferTextsBatched(texts) {
    const normalizedTexts =
        texts.map(
            (text) =>
                String(text ?? '')
        );

    const results =
        new Array(
            normalizedTexts.length
        );

    /*
     * Primero se resuelven hits de cache y se agrupan duplicados exactos dentro de
     * la llamada. Un mismo texto pendiente se infiere una sola vez.
     */
    const unresolvedByText =
        new Map();

    let cacheHits = 0;

    normalizedTexts.forEach(
        (text, originalIndex) => {
            const cached =
                getCachedModelResult(text);

            if (cached) {
                results[originalIndex] =
                    cached;

                cacheHits += 1;
                return;
            }

            if (!unresolvedByText.has(text)) {
                unresolvedByText.set(
                    text,
                    {
                        text,
                        indexes: []
                    }
                );
            }

            unresolvedByText
                .get(text)
                .indexes
                .push(originalIndex);
        }
    );

    /*
     * Sólo los textos realmente nuevos se tokenizan. Luego se ordenan por longitud
     * para reducir el padding medio de los batches ONNX.
     */
    const unresolvedEntries =
        Array.from(
            unresolvedByText.values()
        )
            .map(
                (entry) => {
                    const encoded =
                        encodeText(
                            entry.text
                        );

                    return {
                        ...entry,
                        encoded,
                        sequence_length:
                            encoded.ids.length
                    };
                }
            )
            .sort(
                (left, right) =>
                    left.sequence_length
                    - right.sequence_length
            );

    let batchCount = 0;

    /*
     * Los pendientes se procesan en bloques del tamaño configurado; cada resultado
     * se guarda en cache y se replica a todas sus posiciones originales.
     */
    for (
        let start = 0;
        start < unresolvedEntries.length;
        start += MODEL_INFERENCE_BATCH_SIZE
    ) {
        const batch =
            unresolvedEntries.slice(
                start,
                start + MODEL_INFERENCE_BATCH_SIZE
            );

        const batchInference =
            await inferEncodedBatch(
                batch
            );

        batchCount +=
            batchInference.run_count;

        batch.forEach(
            (entry, batchIndex) => {
                const modelResult =
                    batchInference.results[
                        batchIndex
                    ];

                setCachedModelResult(
                    entry.text,
                    modelResult
                );

                entry.indexes.forEach(
                    (originalIndex) => {
                        results[originalIndex] =
                            modelResult;
                    }
                );
            }
        );
    }

    return {
        results,
        batch_count:
            batchCount,
        cache_hits:
            cacheHits,
        unique_inference_count:
            unresolvedEntries.length
    };
}

/**
 * Compara una representación con su versión en minúsculas.
 *
 * @param {string} analysisText
 * @returns {Promise<object>}
 */
async function inferTextWithCaseVariants(
    analysisText
) {
    const variants =
        uniqueStrings([
            analysisText,
            analysisText.toLocaleLowerCase('es')
        ]);

    const inference =
        await inferTextsBatched(
            variants
        );

    let strongest = null;

    for (const result of inference.results) {
        if (
            !strongest
            || result.spam_score
                > strongest.spam_score
        ) {
            strongest = result;
        }
    }

    return {
        result:
            strongest,
        variant_count:
            variants.length,
        batch_count:
            inference.batch_count,
        cache_hits:
            inference.cache_hits,
        unique_inference_count:
            inference.unique_inference_count
    };
}


/* ============================================================================
 * 9. UNIDADES DE ANÁLISIS POR ALCANCE
 * ============================================================================ */

/**
 * Decide si una cláusula local puede heredar únicamente el carácter informativo
 * del párrafo padre. Nunca se hereda protección fuerte (reporte/rechazo) y la
 * herencia se corta ante autoría, CTA o captación local.
 */
function canInheritInformationalFrame(text) {
    const canonical =
        canonicalizeForStructure(text);

    const grammatical =
        canonicalizeForGrammar(text);

    const localAuthorial =
        AUTHORIAL_SOLICITATION_PATTERN.test(canonical)
        || OFFER_AUTHORSHIP_PATTERN.test(canonical);

    const localDirective =
        CONTACT_ACTION_PATTERN.test(canonical)
        || CONTACT_ACTION_GRAMMATICAL_PATTERN.test(grammatical)
        || CTA_PATTERN.test(canonical)
        || CTA_GRAMMATICAL_PATTERN.test(grammatical)
        || NON_ACCENTED_MIRA_CTA_PATTERN.test(grammatical)
        || NON_ACCENTED_DIRECTED_ACTION_PATTERN.test(grammatical)
        || PLURAL_IMPERATIVE_GRAMMAR_PATTERN.test(grammatical);

    return !localAuthorial
    && !localDirective;
}

/**
 * Agrega una unidad conservando por separado el texto fuente, el texto protegido
 * enviado al modelo, el contexto local y la evidencia estructural local.
 */
function appendScopedUnit(
    units,
    seen,
    sourceText,
    kind,
    index,
    representation,
    parentContext = null
) {
    if (
        units.length
        >= MAX_ANALYSIS_UNITS
    ) {
        return;
    }

    const cleanSource =
        String(sourceText ?? '')
            .replace(/\s+/gu, ' ')
            .trim();

    if (!cleanSource) {
        return;
    }

    /*
     * Cada unidad recalcula su propio contexto. Nunca se reutiliza ciegamente la
     * protección del comentario completo sobre un fragmento local.
     */
    const localContext =
        prepareSingleContextRepresentation(
            cleanSource
        );

    if (
        parentContext
        && canInheritInformationalFrame(
            cleanSource
        )
        && (
            parentContext.informational_context
            || parentContext.reported_context
            || parentContext.metalinguistic_context
            || parentContext.rejection_context
        )
    ) {
        localContext.informational_context = true;
    }

    /*
     * Si todo el alcance está protegido, modelText queda vacío. La unidad se
     * conserva para contexto/auditoría, pero no se envía al tokenizer/modelo.
     */
    const residualText =
        String(localContext.text ?? '')
            .trim();

    const modelText =
        /[\p{L}\p{N}]/u.test(residualText)
            ? residualText
            : '';

    /*
     * La deduplicación considera conjuntamente fuente y residual enviado al modelo,
     * porque dos fuentes distintas pueden terminar con el mismo texto protegido.
     */
    const key =
        [
            canonicalizeForStructure(cleanSource),
            canonicalizeForStructure(modelText)
        ].join('|');

    if (!key || seen.has(key)) {
        return;
    }

    seen.add(key);

    /*
     * La estructura se calcula sobre el mismo alcance contextualizado que recibe
     * el modelo. Esto evita atribuir al comentarista un CTA, contacto u oferta que
     * sólo aparece dentro de una cita/reporte protegido. El texto fuente crudo se
     * conserva aparte exclusivamente para auditoría y diagnóstico.
     */
    const structural =
        analyzeSpamStructure(
            modelText,
            localContext
        );

    units.push({
        text:
            modelText,
        source_text:
            cleanSource,
        kind,
        index,
        representation,
        context:
            localContext,
        structural
    });
}

/**
 * Descompone una representación completa en comentario, cláusulas y ventanas,
 * agregando cada alcance con su propio contexto y evidencia estructural.
 *
 * @param {Array<object>} units
 * @param {Set<string>} seen
 * @param {string} sourceText
 * @param {string} representation
 * @returns {void}
 */
function appendScopedRepresentation(
    units,
    seen,
    sourceText,
    representation
) {
    const cleanText =
        String(sourceText ?? '')
            .replace(/\s+/gu, ' ')
            .trim();

    if (!cleanText) {
        return;
    }

    /*
     * El contexto padre se usa únicamente como marco informativo heredable. Antes
     * de heredarlo, cada segmento debe superar `canInheritInformationalFrame()`.
     */
    const parentContext =
        prepareSingleContextRepresentation(
            cleanText
        );

    appendScopedUnit(
        units,
        seen,
        cleanText,
        'full',
        0,
        representation,
        null
    );

    /*
     * Antes de segmentar neutralizamos únicamente las citas que tienen contexto
     * protector alrededor. Así una cita de spam no reaparece luego como segmento
     * aislado sin su fuente reportativa, mientras una cita no protegida continúa
     * analizándose normalmente.
     */
    const segmentationText =
        neutralizeProtectedQuotes(
            cleanText
        ).text;

    /*
     * Después de proteger citas se segmenta el residual. Los segmentos demasiado
     * largos se subdividen en ventanas superpuestas para detectar spam escondido.
     */
    const segments =
        splitSemanticClauses(
            segmentationText
        );

    segments.forEach(
        (segment, segmentIndex) => {
            const windows =
                splitLongSegmentIntoWindows(
                    segment
                );

            if (windows.length === 1) {
                appendScopedUnit(
                    units,
                    seen,
                    windows[0],
                    'segment',
                    segmentIndex,
                    representation,
                    parentContext
                );
                return;
            }

            windows.forEach(
                (windowText, windowIndex) => {
                    appendScopedUnit(
                        units,
                        seen,
                        windowText,
                        'window',
                        (
                            segmentIndex * 100
                        ) + windowIndex,
                        representation,
                        parentContext
                    );
                }
            );
        }
    );
}

/**
 * Construye el conjunto final de unidades de análisis para las representaciones
 * normalizada y deobfuscada, evitando duplicados exactos entre ambas.
 *
 * @param {object} normalizationBundle
 * @returns {Array<object>}
 */
function buildAnalysisUnits(
    normalizationBundle
) {
    const units = [];
    const seen = new Set();

    appendScopedRepresentation(
        units,
        seen,
        normalizationBundle.normalizedText,
        'normalized'
    );

    appendScopedRepresentation(
        units,
        seen,
        normalizationBundle.deobfuscatedText,
        'deobfuscated'
    );

    return units;
}

/**
 * Ejecuta todas las unidades mediante batches ONNX y agrega evidencia neuronal y
 * estructural por alcance. Un fragmento local sólo puede dominar si su propio
 * alcance contiene captación/oferta real y no está protegido como cita/reporte.
 */
async function inferAnalysisUnitsBatched(
    analysisUnits
) {
    /*
     * Se generan variantes de modelo únicamente para unidades con texto residual.
     * Las unidades totalmente protegidas permanecen fuera del tokenizer.
     */
    const entries = [];

    for (const unit of analysisUnits) {
        if (!String(unit.text ?? '').trim()) {
            continue;
        }

        const variants =
            uniqueStrings([
                unit.text,
                unit.text.toLocaleLowerCase('es')
            ]);

        for (const variant of variants) {
            entries.push({
                text:
                    variant,
                unit
            });
        }
    }

    /*
     * Todas las variantes de todas las unidades se resuelven en una sola operación
     * lógica para aprovechar cache, deduplicación y ordenamiento por longitud.
     */
    const inference =
        await inferTextsBatched(
            entries.map(
                (entry) =>
                    entry.text
            )
        );

    /*
     * Se mantienen máximos separados: neuronal bruto, alcance completo y evidencia
     * estructural. La fusión posterior elige el alcance decisivo de forma explícita.
     */
    let rawStrongestModelResult = null;
    let rawStrongestUnit = null;
    let fullModelResult = null;
    let fullUnit = null;

    let strongestStructural = null;
    let strongestStructuralUnit = null;

    for (const unit of analysisUnits) {
        const structure =
            unit.structural;

        if (
            !strongestStructural
            || structure.structural_spam_signal
                > strongestStructural.structural_spam_signal
        ) {
            strongestStructural =
                structure;

            strongestStructuralUnit =
                unit;
        }
    }

    inference.results.forEach(
        (result, index) => {
            const entry =
                entries[index];

            if (
                !rawStrongestModelResult
                || result.spam_score
                    > rawStrongestModelResult.spam_score
            ) {
                rawStrongestModelResult =
                    result;

                rawStrongestUnit =
                    entry?.unit ?? null;
            }

            if (
                entry?.unit?.kind === 'full'
                && (
                    !fullModelResult
                    || result.spam_score
                        > fullModelResult.spam_score
                )
            ) {
                fullModelResult =
                    result;

                fullUnit =
                    entry.unit;
            }
        }
    );

    const baseResult =
        fullModelResult
        ?? rawStrongestModelResult
        ?? {
            classification: 'not_spam',
            spam_score: 0,
            scores: {
                not_spam: 1,
                spam: 0
            }
        };

    const baseScore =
        clamp01(
            baseResult.spam_score
        );

    let selectedScore =
        baseScore;

    let selectedUnit =
        fullUnit
        ?? rawStrongestUnit;

    /*
     * El modo de agregación queda registrado para auditoría: indica si dominó el
     * comentario completo, un alcance local o una elevación estructural acotada.
     */
    let aggregationMode =
        'full-comment-anchor';

    /*
     * Cada predicción vuelve a asociarse con su unidad original para poder combinar
     * score neuronal, contexto local y estructura sin perder atribución.
     */
    for (
        let index = 0;
        index < inference.results.length;
        index += 1
    ) {
        const result =
            inference.results[index];

        const unit =
            entries[index]?.unit;

        if (
            !unit
            || unit.kind === 'full'
        ) {
            continue;
        }

        const localScore =
            clamp01(
                result?.spam_score
                ?? 0
            );

        if (
            localScore
            <= selectedScore + 0.000001
        ) {
            continue;
        }

        const localStructural =
            unit.structural;

        const structuralScore =
            localStructural
                .structural_spam_signal;

        const wordCount =
            String(unit.source_text)
                .trim()
                .split(/\s+/u)
                .filter(Boolean)
                .length;

        let weight = 0.10;

        if (
            localStructural.scope_protected
            && !localStructural.authorial_solicitation
            && !localStructural.self_promotion
        ) {
            weight = 0.025;
        } else if (structuralScore >= 0.88) {
            weight = 1;
        } else if (structuralScore >= 0.70) {
            weight = 0.94;
        } else if (structuralScore >= 0.52) {
            weight = 0.78;
        } else if (
            localStructural.authorial_solicitation
            || localStructural.commercial_solicitation
            || localStructural.direct_commercial_link
            || localStructural.traffic_diversion
            || localStructural.credential_harvesting
        ) {
            weight = 0.76;
        } else if (
            localStructural.informational_context
        ) {
            weight = 0.045;
        } else if (wordCount <= 3) {
            weight = 0.06;
        } else if (wordCount >= 12) {
            weight = 0.14;
        } else if (localScore >= 0.97) {
            weight = 0.12;
        }

        const contextualizedLocalScore =
            baseScore
            + (
                (
                    localScore
                    - baseScore
                )
                * weight
            );

        if (
            contextualizedLocalScore
            > selectedScore + 0.000001
        ) {
            selectedScore =
                contextualizedLocalScore;

            selectedUnit =
                unit;

            aggregationMode =
                weight >= 0.999
                    ? 'structurally-grounded-local'
                    : (
                        structuralScore >= 0.70
                            ? 'spam-structure-local-uplift'
                            : 'bounded-local-uplift'
                    );
        }
    }

    selectedScore =
        roundScore(
            selectedScore
        );

    return {
        strongest_model_result: {
            ...baseResult,
            classification:
                selectedScore
                >= SPAM_THRESHOLD
                    ? 'spam'
                    : 'not_spam',
            spam_score:
                selectedScore,
            scores: {
                not_spam:
                    roundScore(
                        1 - selectedScore
                    ),
                spam:
                    selectedScore
            }
        },
        strongest_unit:
            selectedUnit,
        raw_strongest_model_result:
            rawStrongestModelResult,
        raw_strongest_unit:
            rawStrongestUnit,
        full_model_result:
            fullModelResult,
        strongest_structural_evidence:
            strongestStructural,
        strongest_structural_unit:
            strongestStructuralUnit,
        aggregation_mode:
            aggregationMode,
        variant_count:
            entries.length,
        batch_count:
            inference.batch_count,
        cache_hits:
            inference.cache_hits,
        unique_inference_count:
            inference.unique_inference_count
    };
}


/* ============================================================================
 * 10. FUSIÓN DE EVIDENCIAS POR ALCANCE
 * ============================================================================ */

/**
 * Fusiona el score neuronal con evidencia estructural, contexto protector y
 * señales de evasión. La función no vuelve a analizar texto: trabaja únicamente
 * con evidencia ya calculada para el alcance decisivo.
 *
 * @param {object} params
 * @param {number} params.modelScore
 * @param {object} params.structural
 * @param {object} params.normalization
 * @param {object} params.context
 * @returns {object}
 */
function fuseEvidence({
    modelScore,
    structural,
    normalization,
    context
}) {
    /* ------------------------------------------------------------------------
     * Etapa 1: normalización de entradas. La fusión parte siempre del score del
     * modelo y sólo lo mueve cuando otra evidencia tiene suficiente fundamento.
     * --------------------------------------------------------------------- */
    const model =
        clamp01(modelScore);

    const structure =
        clamp01(
            structural
                .structural_spam_signal
        );

    let score = model;

    let structuralRaisedScore = false;
    let contextAdjustedScore = false;
    let obfuscationAdjustedScore = false;

    /*
     * Pisos estructurales: sólo combinaciones de intención suficientemente fuertes
     * pueden rescatar un falso negativo neuronal.
     */
    /* ------------------------------------------------------------------------
     * Etapa 2: pisos estructurales graduados. Cuanto más fuerte la estructura,
     * mayor autoridad tiene para rescatar un falso negativo neuronal.
     * --------------------------------------------------------------------- */
    if (structure >= 0.92) {
        const target =
            Math.max(
                0.90,
                structure * 0.97
            );

        if (target > score) {
            score +=
                (target - score) * 0.94;
            structuralRaisedScore = true;
        }
    } else if (structure >= 0.75) {
        const target =
            structure * 0.92;

        if (target > score) {
            score +=
                (target - score) * 0.80;
            structuralRaisedScore = true;
        }
    } else if (structure >= 0.55) {
        const target =
            structure * 0.84;

        if (target > score) {
            score +=
                (target - score) * 0.58;
            structuralRaisedScore = true;
        }
    }

    /*
     * Una relación estructural muy fuerte puede prevalecer aunque DistilBERT dé
     * un falso negativo extremo. Se exige interacción de señales; nunca una sola
     * palabra o temática.
     */
    if (
        structural.strong_relational_solicitation
        || structural.direct_imperative_offer
    ) {
        const target =
            structural.strong_relational_solicitation
                ? 0.88
                : 0.84;

        if (target > score) {
            score +=
                (target - score) * 0.96;
            structuralRaisedScore = true;
        }
    }

    /*
     * Dos acciones dirigidas al lector junto con una oferta/pretexto constituyen
     * evidencia relacional suficiente aunque el modelo tenga un falso negativo.
     */
    if (
        structural.multi_action_solicitation
        && !structural.scope_protected
    ) {
        const target = 0.82;

        if (target > score) {
            score +=
                (target - score) * 0.95;
            structuralRaisedScore = true;
        }
    }

    /*
     * La evasión no crea intención. Sólo refuerza una intención ya observada en
     * el mismo alcance.
     */
    if (
        normalization.obfuscation_signal
        >= 0.22
        && (
            structure >= 0.52
            || structural.destination_signal >= 0.52
            || structural.solicitation_signal >= 0.52
        )
    ) {
        const uplift =
            Math.min(
                0.12,
                normalization.obfuscation_signal
                * 0.13
            );

        score +=
            (1 - score) * uplift;

        obfuscationAdjustedScore = true;
    }

    /* ------------------------------------------------------------------------
     * Etapa 3: contexto protector del alcance decisivo. Se reduce score sólo si
     * no persiste una solicitud autoral/credencial inequívoca.
     * --------------------------------------------------------------------- */
    const protectedContext =
        Boolean(
            context?.quoted_context
            || context?.reported_context
            || context?.metalinguistic_context
            || context?.rejection_context
            || structural.scope_protected
        );

    /*
     * La protección pertenece al alcance decisivo, no al comentario entero.
     * Si ese alcance conserva autoría/captación fuerte, la protección no lo pisa.
     */
    if (
        protectedContext
        && !structural.authorial_solicitation
        && !structural.self_promotion
        && !structural.credential_harvesting
        && !structural.strong_relational_solicitation
        && !structural.direct_imperative_offer
    ) {
        const protection =
            clamp01(
                0.62
                + Number(
                    context?.protection_score
                    ?? 0
                ) * 0.28
            );

        score = Math.min(
            score * (1 - protection),
            structural.scope_rejection
                ? 0.20
                : 0.30
        );

        contextAdjustedScore = true;
    } else if (
        structural.informational_context
        && !structural.authorial_solicitation
        && !structural.commercial_solicitation
        && !structural.traffic_diversion
    ) {
        score = Math.min(
            score * 0.24,
            0.30
        );

        contextAdjustedScore = true;
    }

    score =
        clamp01(score);

    /* ------------------------------------------------------------------------
     * Etapa 4: diagnóstico de desacuerdo modelo-estructura. Este indicador no
     * cambia por sí solo la clase; sirve para certeza y revisión recomendada.
     * --------------------------------------------------------------------- */
    const modelStructuralConflict =
        (
            model >= 0.82
            && structure <= 0.28
            && (
                protectedContext
                || structural.informational_context
            )
        )
        || (
            model <= 0.18
            && structure >= 0.88
        );

    let certainty;

    if (score >= CLEAR_SPAM_MIN) {
        certainty = 'clear-spam';
    } else if (score <= CLEAR_NOT_SPAM_MAX) {
        certainty = 'clear-not-spam';
    } else {
        certainty = 'uncertain';
    }

    const uncertain =
        certainty === 'uncertain'
        || (
            score >= UNCERTAIN_LOW
            && score <= UNCERTAIN_HIGH
        )
        || modelStructuralConflict;

    const reviewRecommended =
        uncertain
        || (
            protectedContext
            && structure >= 0.42
        );

    return {
        score:
            roundScore(score),
        structural_raised_score:
            structuralRaisedScore,
        context_adjusted_score:
            contextAdjustedScore,
        obfuscation_adjusted_score:
            obfuscationAdjustedScore,
        model_structural_conflict:
            modelStructuralConflict,
        certainty:
            uncertain
                ? 'uncertain'
                : certainty,
        uncertain,
        review_recommended:
            reviewRecommended
    };
}


/* ============================================================================
 * 11. CLASIFICADOR PÚBLICO
 * ============================================================================ */

/**
 * Clasifica un comentario como SPAM o NOT_SPAM.
 *
 * Flujo:
 *
 * 1. conserva el texto original y crea copias normalizadas;
 * 2. excluye de inferencia spans/citas/reportes legítimos sin placeholders;
 * 3. genera comentario completo, segmentos y ventanas;
 * 4. ejecuta el modelo mediante cache + batches ONNX;
 * 5. analiza autoría, oferta, CTA, destino e incentivo en cada alcance;
 * 6. fusiona modelo y relaciones estructurales según confiabilidad del alcance;
 * 7. devuelve clasificación, score, certeza y metadatos de auditoría.
 *
 * La opción include_raw_model_audit=false omite la inferencia adicional sobre el
 * texto normalizado previo a la protección contextual. Esa inferencia es sólo de
 * auditoría y no participa en la clasificación final, por lo que puede apagarse
 * en baterías grandes para aumentar throughput.
 *
 * @param {string} text
 * @param {object} [options]
 * @param {boolean} [options.include_raw_model_audit=true]
 * @returns {Promise<object>}
 */
async function classify(
    text,
    options = {}
) {
    const includeRawModelAudit =
        options?.include_raw_model_audit
        !== false;

    const originalText =
        String(text ?? '').trim();

    /*
     * Caso base: un comentario vacío no necesita tokenización ni estructura. Se
     * devuelve una auditoría completa con valores neutrales por compatibilidad.
     */
    if (!originalText) {
        return {
            classification: 'not_spam',
            spam_score: 0,
            scores: {
                not_spam: 1,
                spam: 0
            },
            score_type: 'hybrid',

            /*
             * Contrato operativo para Laravel.
             *
             * Estas propiedades se exponen también en el nivel superior para que la
             * política approved / pending / rejected no dependa de leer detalles
             * internos del objeto `analysis`.
             */
            certainty: 'clear-not-spam',
            uncertain: false,
            review_recommended: false,

            analysis: {
                architecture_version:
                    'scope-intent-fusion-v4.3.2',
                model:
                    MODEL_NAME,
                model_spam_score: 0,
                raw_model_spam_score: 0,
                raw_model_audit_skipped: false,
                model_full_comment_score: 0,
                model_raw_segment_max_score: 0,
                model_aggregation_mode: 'empty',
                strongest_unit_kind: null,
                strongest_unit_index: null,
                strongest_unit_representation: null,
                strongest_structural_unit_kind: null,
                strongest_structural_unit_index: null,
                analysis_unit_count: 0,
                model_variant_count: 0,
                model_batch_count: 0,
                model_cache_hits: 0,
                model_unique_inference_count: 0,
                normalization_changed: false,
                obfuscation_signal: 0,
                obfuscation_types: [],
                context_adjusted: false,
                context_protection_score: 0,
                protected_quote_count: 0,
                protected_clause_count: 0,
                quoted_context: false,
                reported_context: false,
                metalinguistic_context: false,
                rejection_context: false,
                structural_spam_signal: 0,
                spam_types: [],
                solicitation_signal: 0,
                contact_signal: 0,
                cta_signal: 0,
                promotion_signal: 0,
                offer_signal: 0,
                destination_signal: 0,
                authorship_signal: 0,
                incentive_signal: 0,
                money_promise_signal: 0,
                investment_pitch_signal: 0,
                job_pitch_signal: 0,
                gambling_signal: 0,
                loan_signal: 0,
                giveaway_signal: 0,
                affiliate_signal: 0,
                link_signal: 0,
                social_growth_signal: 0,
                seo_promotion_signal: 0,
                adult_content_signal: 0,
                adult_promotion: false,
                credential_harvesting_signal: 0,
                authorial_solicitation: false,
                self_promotion: false,
                traffic_diversion: false,
                credential_harvesting: false,
                informational_context: false,
                commercial_solicitation: false,
                strong_relational_solicitation: false,
                direct_imperative_offer: false,
                direct_commercial_link: false,
                strong_money_solicitation: false,
                url_count: 0,
                email_count: 0,
                phone_count: 0,
                structural_reinforced: false,
                context_score_adjusted: false,
                obfuscation_score_adjusted: false,
                model_structural_conflict: false,
                certainty: 'clear-not-spam',
                uncertain: false,
                review_recommended: false
            }
        };
    }

    /* Paso 1: normalización robusta y deobfuscación de forma. */
    const normalization =
        buildNormalizationBundle(
            originalText
        );

    /* Paso 2: contexto global para auditoría; la decisión usa además contexto local. */
    const preparedContext =
        prepareContextForClassification(
            normalization
        );

    /* Paso 3: comentario, cláusulas y ventanas con contexto independiente. */
    const analysisUnits =
        buildAnalysisUnits(
            normalization
        );

    /* Paso 4: inferencia y agregación conjunta de todas las unidades de alcance. */
    const modelInference =
        await inferAnalysisUnitsBatched(
            analysisUnits
        );

    const strongestModelResult =
        modelInference
            .strongest_model_result;

    const strongestUnit =
        modelInference
            .strongest_unit;

    const rawStrongestModelResult =
        modelInference
            .raw_strongest_model_result;

    const fullModelResult =
        modelInference
            .full_model_result;

    const strongestStructuralUnit =
        modelInference
            .strongest_structural_unit;

    /*
     * Paso 5: se toma la evidencia estructural del alcance más fuerte; sólo si no
     * existe se calcula un fallback sobre la representación deobfuscada completa.
     */
    const structural =
        modelInference
            .strongest_structural_evidence
        ?? analyzeSpamStructure(
            normalization.deobfuscatedText,
            preparedContext
        );

    const modelSpamScore =
        strongestModelResult?.spam_score
        ?? 0;

    let modelBatchCount =
        modelInference.batch_count;

    let modelCacheHits =
        modelInference.cache_hits;

    let modelVariantCount =
        modelInference.variant_count;

    let modelUniqueInferenceCount =
        modelInference
            .unique_inference_count;

    /*
     * Auditoría opcional del texto antes de neutralización. No interviene en la
     * decisión final y se apaga en benchmarks masivos.
     */
    let rawModelSpamScore =
        rawStrongestModelResult?.spam_score
        ?? modelSpamScore;

    if (
        preparedContext.adjusted
        && includeRawModelAudit
    ) {
        const rawInference =
            await inferTextWithCaseVariants(
                normalization.normalizedText
            );

        rawModelSpamScore =
            rawInference.result?.spam_score
            ?? rawModelSpamScore;

        modelBatchCount +=
            rawInference.batch_count;

        modelCacheHits +=
            rawInference.cache_hits;

        modelVariantCount +=
            rawInference.variant_count;

        modelUniqueInferenceCount +=
            rawInference
                .unique_inference_count;
    }

    /*
     * El contexto decisivo pertenece al alcance que aporta la evidencia más útil.
     * Si existe estructura local clara, domina su contexto; en caso contrario se
     * usa el alcance neuronal seleccionado y finalmente el contexto global.
     */
    const decisiveUnit =
        structural.structural_spam_signal
        >= 0.42
            ? strongestStructuralUnit
            : strongestUnit;

    const decisiveContext =
        decisiveUnit?.context
        ?? preparedContext;

    const fusion =
        fuseEvidence({
            modelScore:
                modelSpamScore,
            structural,
            normalization,
            context:
                decisiveContext
        });

    const finalSpamScore =
        roundScore(
            fusion.score
        );

    const finalNotSpamScore =
        roundScore(
            1 - finalSpamScore
        );

    /* Paso 7: decisión binaria pública a partir del score híbrido final. */
    const classification =
        finalSpamScore
        >= SPAM_THRESHOLD
            ? 'spam'
            : 'not_spam';

    const hybridIntervention =
        normalization.changed
        || preparedContext.adjusted
        || fusion.structural_raised_score
        || fusion.context_adjusted_score
        || fusion.obfuscation_adjusted_score
        || modelInference.aggregation_mode
            !== 'full-comment-anchor';

    /*
     * Paso 8: salida estable para producción y benchmark. `analysis` conserva los
     * componentes que permiten explicar por qué se obtuvo la clasificación.
     */
    return {
        classification,
        spam_score:
            finalSpamScore,
        scores: {
            not_spam:
                finalNotSpamScore,
            spam:
                finalSpamScore
        },
        score_type:
            hybridIntervention
                ? 'hybrid'
                : 'model',

        /*
         * Contrato operativo para Laravel.
         *
         * `classification` conserva la decisión binaria del detector, mientras que
         * `review_recommended` permite separar el terreno dudoso sin alterar el
         * significado de SPAM / NOT_SPAM ni reinterpretar `spam_score` como una
         * probabilidad calibrada.
         */
        certainty:
            fusion.certainty,
        uncertain:
            fusion.uncertain,
        review_recommended:
            fusion.review_recommended,

        analysis: {
            architecture_version:
                'scope-intent-fusion-v4.3.2',
            model:
                MODEL_NAME,

            model_spam_score:
                roundScore(modelSpamScore),
            raw_model_spam_score:
                roundScore(rawModelSpamScore),
            raw_model_audit_skipped:
                preparedContext.adjusted
                && !includeRawModelAudit,
            model_full_comment_score:
                roundScore(
                    fullModelResult?.spam_score
                    ?? modelSpamScore
                ),
            model_raw_segment_max_score:
                roundScore(
                    rawStrongestModelResult?.spam_score
                    ?? modelSpamScore
                ),
            model_aggregation_mode:
                modelInference.aggregation_mode,

            strongest_unit_kind:
                strongestUnit?.kind
                ?? null,
            strongest_unit_index:
                strongestUnit?.index
                ?? null,
            strongest_unit_representation:
                strongestUnit?.representation
                ?? null,
            strongest_structural_unit_kind:
                strongestStructuralUnit?.kind
                ?? null,
            strongest_structural_unit_index:
                strongestStructuralUnit?.index
                ?? null,
            strongest_structural_unit_representation:
                strongestStructuralUnit?.representation
                ?? null,
            decisive_scope_protected:
                Boolean(
                    decisiveContext?.reported_context
                    || decisiveContext?.metalinguistic_context
                    || decisiveContext?.rejection_context
                ),
            analysis_unit_count:
                analysisUnits.length,
            model_variant_count:
                modelVariantCount,
            model_batch_count:
                modelBatchCount,
            model_cache_hits:
                modelCacheHits,
            model_unique_inference_count:
                modelUniqueInferenceCount,

            normalization_changed:
                normalization.changed,
            obfuscation_signal:
                normalization.obfuscation_signal,
            obfuscation_types:
                normalization.obfuscation_types,

            context_adjusted:
                preparedContext.adjusted,
            context_protection_score:
                preparedContext.protection_score,
            protected_quote_count:
                preparedContext.protected_quote_count,
            protected_clause_count:
                preparedContext.protected_clause_count,
            quoted_context:
                preparedContext.quoted_context,
            reported_context:
                preparedContext.reported_context,
            metalinguistic_context:
                preparedContext.metalinguistic_context,
            rejection_context:
                preparedContext.rejection_context,

            structural_spam_signal:
                structural.structural_spam_signal,
            spam_types:
                structural.spam_types,
            solicitation_signal:
                structural.solicitation_signal,
            contact_signal:
                structural.contact_signal,
            cta_signal:
                structural.cta_signal,
            promotion_signal:
                structural.promotion_signal,
            offer_signal:
                structural.offer_signal,
            destination_signal:
                structural.destination_signal,
            authorship_signal:
                structural.authorship_signal,
            incentive_signal:
                structural.incentive_signal,
            money_promise_signal:
                structural.money_promise_signal,
            investment_pitch_signal:
                structural.investment_pitch_signal,
            job_pitch_signal:
                structural.job_pitch_signal,
            gambling_signal:
                structural.gambling_signal,
            loan_signal:
                structural.loan_signal,
            giveaway_signal:
                structural.giveaway_signal,
            affiliate_signal:
                structural.affiliate_signal,
            link_signal:
                structural.link_signal,
            social_growth_signal:
                structural.social_growth_signal,
            seo_promotion_signal:
                structural.seo_promotion_signal,
            adult_content_signal:
                structural.adult_content_signal,
            adult_promotion:
                structural.adult_promotion,
            credential_harvesting_signal:
                structural.credential_harvesting_signal,
            authorial_solicitation:
                structural.authorial_solicitation,
            self_promotion:
                structural.self_promotion,
            traffic_diversion:
                structural.traffic_diversion,
            credential_harvesting:
                structural.credential_harvesting,
            informational_context:
                structural.informational_context,
            commercial_solicitation:
                structural.commercial_solicitation,
            strong_relational_solicitation:
                structural.strong_relational_solicitation,
            direct_imperative_offer:
                structural.direct_imperative_offer,
            direct_commercial_link:
                structural.direct_commercial_link,
            strong_money_solicitation:
                structural.strong_money_solicitation,
            url_count:
                structural.url_count,
            email_count:
                structural.email_count,
            phone_count:
                structural.phone_count,

            structural_reinforced:
                fusion.structural_raised_score,
            context_score_adjusted:
                fusion.context_adjusted_score,
            obfuscation_score_adjusted:
                fusion.obfuscation_adjusted_score,
            model_structural_conflict:
                fusion.model_structural_conflict,
            certainty:
                fusion.certainty,
            uncertain:
                fusion.uncertain,
            review_recommended:
                fusion.review_recommended
        }
    };
}


/* ============================================================================
 * 12. EXPORTS
 * ============================================================================ */

/*
 * `classify()` queda disponible para baterías y para la capa de moderación sin
 * necesidad de crear procesos Node separados ni volver a cargar el modelo.
 */
export {
    analyzeSpamStructure,
    buildNormalizationBundle,
    classify,
    normalizeTextForAnalysis
};




/* ============================================================================
 * 13. EJECUCIÓN DIRECTA DESDE TERMINAL
 * ============================================================================ */

/**
 * Permite ejecutar este archivo directamente desde la terminal para probar
 * manualmente un comentario sin necesidad de pasar por Laravel.
 *
 * Ejemplo:
 *
 * node scripts/comments/classify-comment-spam.mjs "comentario"
 *
 * La ejecución muestra directamente en la terminal la clasificación obtenida
 * y sus valores asociados, facilitando las pruebas manuales del detector.
 *
 * @returns {Promise<void>}
 */
async function runScript() {
    const text =
        process.argv
            .slice(2)
            .join(' ')
            .trim();

    if (!text) {
        console.error(
            JSON.stringify({
                error:
                    'No se recibió ningún comentario para clasificar.'
            })
        );

        process.exitCode = 1;
        return;
    }

    try {
        const result =
            await classify(text);

        /*
         * La ejecución manual muestra solamente la clasificación y las
         * probabilidades finales. Los metadatos completos de `analysis` se
         * conservan en el objeto devuelto por classify() para baterías y para
         * cualquier consumidor que importe el módulo directamente.
         */
        console.log(
            JSON.stringify({
                classification:
                    result.classification,
                spam_score:
                    result.spam_score,
                scores:
                    result.scores
            })
        );
    } catch (error) {
        console.error(
            JSON.stringify({
                error:
                    error instanceof Error
                        ? error.message
                        : 'Error desconocido durante la clasificación.'
            })
        );

        process.exitCode = 1;
    }
}

const isDirectExecution =
    Boolean(process.argv[1])
    && pathToFileURL(
        path.resolve(
            process.argv[1]
        )
    ).href === import.meta.url;

if (isDirectExecution) {
    await runScript();
}
