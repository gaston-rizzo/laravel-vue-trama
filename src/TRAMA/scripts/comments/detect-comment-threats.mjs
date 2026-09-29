/* ============================================================================
 * SCRIPT: detect-comment-threats.mjs
 * ============================================================================
 *
 * TRAMA — DETECTOR HÍBRIDO DE AMENAZAS Y VIOLENCIA DIRIGIDA — V4.4 FINAL
 *
 * OBJETIVO
 * ---------------------------------------------------------------------------
 * Detectar amenazas reales en comentarios de hasta 1.200 caracteres con foco en
 * español y español rioplatense, separando:
 *
 * - HIGH: violencia física dirigida, intención/promesa de daño, incitación o
 *   deseo explícito de violencia física;
 * - MEDIUM: intimidación, represalia, advertencia velada o deseo ambiguo de daño
 *   que conviene derivar a moderación humana;
 * - NONE: crítica, enojo, objetos, usos figurados, negaciones, citas, 
 *   discurso referido, educación, ficción y metalenguaje.
 *
 * ARQUITECTURA V4.4 FINAL
 * ---------------------------------------------------------------------------
 * 1. Normalización segura y deobfuscación controlada.
 * 2. Segmentación por cláusulas para evitar mezclar una negación con una amenaza
 *    posterior o una cita con una amenaza residual real.
 * 3. Scope contextual: citas, reporte, educación, ficción y negación/rechazo.
 * 4. Gramática composicional: objetivo humano + acción de daño + intención,
 *    incitación, condición, represalia, advertencia o deseo de daño.
 * 5. Detoxify/ONNX se usa como segunda opinión SELECTIVA y también puede rescatar
 *    a MEDIUM una semántica intimidatoria nueva cuando la estructura conocida no
 *    alcanza, siempre que exista objetivo humano y contexto no protegido.
 * 6. HIGH neuronal sigue exigiendo evidencia física estructural: un score alto del
 *    modelo por sí solo nunca convierte un comentario en amenaza grave.
 *
 * EFICIENCIA
 * ---------------------------------------------------------------------------
 * La V1 podía inferir comentario completo + oraciones + pares + múltiples
 * ventanas 80/120/360. La V4.4 construye como máximo un conjunto pequeño de
 * segmentos lingüísticamente candidatos. Los casos resueltos estructuralmente
 * no ejecutan ONNX.
 *
 * `threat_score` es un índice operativo, no una probabilidad estadística.
 *
 * Este script produce señales para la política de moderación de Laravel; no
 * decide directamente approved / pending / rejected.
 * ============================================================================ */

import {
    readFileSync
} from 'node:fs';

import process from 'node:process';

import {
    resolve
} from 'node:path';

import {
    pathToFileURL
} from 'node:url';

import * as ort from 'onnxruntime-node';

import {
    Tokenizer
} from '@huggingface/tokenizers';


/* ============================================================================
 * CONFIGURACIÓN GENERAL
 * ============================================================================ */

const APP_MAX_CHARACTERS = 1200;
const MAX_MODEL_TOKENS = 512;
const TOP_SEGMENTS_LIMIT = 8;
const MAX_NEURAL_CANDIDATES = 6;

const NEURAL_RESCUE_HIGH_THRESHOLD = 0.82;
const NEURAL_RESCUE_MEDIUM_THRESHOLD = 0.62;

/*
 * Rescate semántico conservador. Cuando la gramática conocida no reconoce una
 * amenaza concreta, Detoxify puede derivar el caso a MEDIUM/revisión humana si:
 *
 * - existe un objetivo humano o una segunda persona;
 * - la cláusula contiene un marco prospectivo/intimidatorio general;
 * - no está protegida por cita, reporte, ficción, negación ni objetivo no humano;
 * - el score neuronal es alto.
 *
 * Este umbral es deliberadamente más exigente que el rescate MEDIUM estructural.
 */
const NEURAL_SEMANTIC_RESCUE_MEDIUM_THRESHOLD = 0.82;

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

const DISABLE_NEURAL =
    process.env.TRAMA_THREAT_DISABLE_NEURAL === '1';


/* ============================================================================
 * MODELO LOCAL
 * ============================================================================ */

const MODEL_DIR =
    resolve(
        'resources/models/comment-threat-detoxify'
    );

const MODEL_PATH =
    resolve(
        MODEL_DIR,
        'model.quant.onnx'
    );

const TOKENIZER_PATH =
    resolve(
        MODEL_DIR,
        'tokenizer.json'
    );

const TOKENIZER_CONFIG_PATH =
    resolve(
        MODEL_DIR,
        'tokenizer_config.json'
    );

const MODEL_CONFIG_PATH =
    resolve(
        MODEL_DIR,
        'config.json'
    );


/* ============================================================================
 * VOCABULARIO SEMÁNTICO
 * ============================================================================ */

const HUMAN_ROLE_WORDS = new Set([
    'persona', 'personas', 'hombre', 'hombres', 'mujer', 'mujeres',
    'tipo', 'tipa', 'pibe', 'piba', 'flaco', 'flaca', 'señor', 'señora',
    'periodista', 'periodistas', 'autor', 'autora', 'autores', 'autoras',
    'editor', 'editora', 'editores', 'editoras', 'redactor', 'redactora',
    'redactores', 'redactoras', 'columnista', 'columnistas', 'analista',
    'analistas', 'vocero', 'vocera', 'voceros', 'voceras', 'moderador',
    'moderadora', 'moderadores', 'moderadoras', 'usuario', 'usuaria',
    'usuarios', 'usuarias', 'comentarista', 'comentaristas', 'funcionario',
    'funcionaria', 'funcionarios', 'funcionarias', 'politico', 'politica', 'responsable', 'diputado', 'diputada', 'senador',
    'senadora', 'ministro', 'ministra', 'juez', 'jueza', 'fiscal', 'abogado',
    'abogada', 'presidente', 'presidenta', 'gobernador', 'gobernadora',
    'intendente', 'dirigente', 'candidato', 'candidata', 'empleado', 'empleada',
    'trabajador', 'trabajadora', 'vecino', 'vecina', 'militante', 'manifestante',
    'reportero', 'reportera', 'cronista', 'fotógrafo', 'fotógrafa', 'testigo',
    'denunciante', 'acusado', 'acusada', 'victima',
    'panelista', 'panelistas', 'administrador', 'administradora',
    'administradores', 'administradoras', 'encargado', 'encargada',
    'encargados', 'encargadas', 'colaborador', 'colaboradora',
    'colaboradores', 'colaboradoras', 'portavoz', 'portavoces',
    'conductor', 'conductora', 'conductores', 'conductoras'
]);

const NON_HUMAN_WORDS = new Set([
    'computadora', 'ordenador', 'notebook', 'teclado', 'monitor', 'celular', 'telefono', 'impresora', 'archivo', 'documento', 'caja', 'cartel', 'maqueta', 'router', 'contenedor', 'rama', 'instalacion', 'respaldo', 'imagen',
    'puerta', 'mueble', 'planilla', 'disco', 'formulario', 'cable', 'version', 'cache', 'paquete', 'copia', 'sistema', 'servidor', 'proyecto',
    'acuerdo', 'rendimiento', 'productividad', 'comparación', 'comparacion',
    'ventas', 'consumo', 'flujo', 'estabilidad', 'interfaz', 'software', 'codigo', 'base', 'datos', 'tabla', 'registro', 'proceso', 'servicio', 'app',
    'aplicación', 'aplicacion', 'auto', 'coche', 'vehiculo', 'mesa',
    'ventana', 'pared', 'pelota', 'record', 'tiempo', 'inflacion', 'burocracia', 'latencia', 'humedad', 'deuda', 'demora',
    'memoria', 'impuesto', 'incertidumbre', 'sequia',
    'script', 'scripts', 'tarea', 'tareas', 'task', 'worker', 'workers', 'job', 'jobs',
    'hilo', 'thread', 'threads', 'timeout', 'daemon', 'instancia', 'instancias',
    'pipeline', 'build', 'deploy', 'commit', 'rama', 'ramas', 'endpoint', 'host',
    'stock', 'demanda', 'margen', 'configuracion', 'carga', 'cargas', 'bug', 'error',
    'nota', 'noticia', 'articulo', 'grafico', 'cobertura', 'titular', 'titulo', 'texto', 'pagina', 'compilador', 'boton', 'vista', 'paciencia', 'precio', 'precios', 'presupuesto', 'presupuestos',
    'boss', 'jefe', 'partida', 'partidas', 'combo', 'combos', 'marcador', 'goleada', 'partido',
    /* Objetos técnicos/físicos reales observados en regresión V4. */
    'carcasa', 'maquina', 'gabinete', 'mouse', 'switch', 'placa', 'entorno', 'ssd'
]);

const HARM_CANONICAL_WORDS = [
    'matar', 'asesinar', 'golpear', 'lastimar', 'herir', 'agredir',
    'apuñalar', 'acuchillar', 'disparar', 'fusilar', 'estrangular', 'torturar',
    'atropellar', 'reventar', 'golpes', 'paliza', 'trompadas', 'punetazos',
    'pina', 'pinas', 'pinazo', 'pinazos', 'hospital', 'moretones'
];


/* ============================================================================
 * NORMALIZACIÓN Y DEOBFUSCACIÓN
 * ============================================================================ */

/**
 * Normaliza espacios y saltos de línea sin alterar el contenido semántico.
 */
function normalizeSpacing(value) {
    return String(
        value
        ?? ''
    )
        .replace(/\r\n?/g, '\n')
        .replace(/[ \t]+/g, ' ')
        .replace(/\n{3,}/g, '\n\n')
        .trim();
}

/**
 * Valida el límite máximo de caracteres permitido por TRAMA.
 */
function validateApplicationLength(text) {
    if (
        text.length
        > APP_MAX_CHARACTERS
    ) {
        throw new Error(
            `El comentario tiene ${text.length} caracteres y supera el máximo de TRAMA (${APP_MAX_CHARACTERS}).`
        );
    }
}

/**
 * Recompone secuencias de letras separadas cuando forman vocabulario relevante conocido.
 */
function collapseSingleLetterRuns(text) {
    const known = [
        'matar', 'golpear', 'lastimar', 'agredir', 'asesinar', 'herir',
        'apunalar', 'acuchillar', 'disparar', 'reventar', 'cuidate',
        'voy', 'hay', 'vamos',
        'cuidarse', 'trompadas', 'punetazos', 'pinas', 'pinazos', 'palos'
    ];

    return text.replace(
        /(?:\b\p{L}\b[ \t]+){3,}\b\p{L}\b/gu,
        (match) => {
            const compact =
                match.replace(/[ \t]+/gu, '');

            for (const keyword of known) {
                const index = compact.indexOf(keyword);

                if (index < 0) {
                    continue;
                }

                const prefix = compact.slice(0, index);
                const suffix = compact.slice(index + keyword.length);

                return [
                    prefix,
                    keyword,
                    suffix
                ]
                    .filter(Boolean)
                    .join(' ');
            }

            return compact;
        }
    );
}

/**
 * Reduce escritura carácter-por-carácter con separadores cuando la forma global es claramente evasiva.
 */
function collapseGlobalCharacterSeparatedWriting(value) {
    const text = String(value ?? '');

    /*
     * Sólo entra cuando existe una secuencia larga de caracteres individuales
     * separados repetidamente. Así no tocamos puntuación normal entre palabras.
     */
    const suspiciousRun =
        /(?:[\p{L}\p{N}][._~|*\/\\:·-]+){4,}[\p{L}\p{N}]/u.test(text);

    if (!suspiciousRun) {
        return text;
    }

    let result = text;

    /*
     * En estilos generados carácter por carácter, el espacio original suele
     * quedar codificado como "separador + espacio + separador".
     */
    result = result.replace(
        /(?<=[\p{L}\p{N}])[._~|*\/\\:·-]+\s+[._~|*\/\\:·-]+(?=[\p{L}\p{N}])/gu,
        ' '
    );

    result = result.replace(
        /(?<=[\p{L}\p{N}])[._~|*\/\\:·-]+(?=[\p{L}\p{N}])/gu,
        ''
    );

    return result
        .replace(/[ \t]+/gu, ' ')
        .trim();
}


/**
 * Construye la representación de análisis normalizada y deobfuscada del comentario.
 */
function deobfuscateThreatText(value) {
    let text =
        String(value ?? '')
            .normalize('NFKC')
            .replace(/[\u200B-\u200D\u2060\uFEFF]/gu, '')
            .replace(/[“”„‟«»]/gu, '"')
            .replace(/[’‘]/gu, "'")
            .toLocaleLowerCase('es')
            .normalize('NFD')
            .replace(/\p{M}+/gu, '')
            .normalize('NFC');

    text = collapseGlobalCharacterSeparatedWriting(text);

    /* Repetición tipográfica deliberada: teee / haaay / alllguien. */
    text = text.replace(
        /(\p{L})\1{2,}/gu,
        '$1'
    );

    /*
     * Evasión con un mismo grupo de símbolos repetido entre cada letra:
     *
     *     v~~o~~y~~ ~~a~~ ~~m~~a~~t~~a~~r
     *     g__o__l__p__e__a__r
     *
     * Primero reconstruimos palabras de tres o más letras. Los espacios
     * originales siguen actuando como frontera y se resuelven en el paso
     * siguiente, por lo que no se concatena toda la oración.
     */
    text = text.replace(
        /\p{L}(?:[_~|*\/\\:·-]{2,}\p{L}){2,}/gu,
        (match) =>
            match.replace(
                /[_~|*\/\\:·-]{2,}/gu,
                ''
            )
    );

    /* Frontera entre palabras en estilos t-e- -v-o-y / t.e. .v.o.y. */
    text = text.replace(
        /(?<=\p{L})[._~|*\/\\:·-]+\s+[._~|*\/\\:·-]+(?=\p{L})/gu,
        ' '
    );

    /* g . o . l . p . e . a . r / c - u - i - d - a - t - e */
    text = text.replace(
        /\b\p{L}(?:\s+[._~|*\/\\:·-]\s+\p{L}){2,}\b/gu,
        (match) =>
            match.replace(/[\s._~|*\/\\:·-]/gu, '')
    );

    /* m_a_t_a_r / g-o-l-p-e-a-r / l·a·s·t·i·m·a·r */
    text = text.replace(
        /\b\p{L}(?:(?:[._~|*\/\\:·]\p{L})|(?:\s*-\s*\p{L})){2,}\b/gu,
        (match) =>
            match.replace(/[\s._~|*\/\\:·-]/gu, '')
    );

    /* Pronombres muy cortos obfuscados: t:e, l:o, l:a, l:e. */
    text = text.replace(
        /\b([tl])([._~|*\/\\:·-]+)([eoa])\b/gu,
        '$1$3'
    );

    text = text
        .replace(/\ba[._~|*\/\\:·-]+l\b/gu, 'al')
        .replace(/\bs[._~|*\/\\:·-]+i\b/gu, 'si')
        .replace(/\bn[._~|*\/\\:·-]+o\b/gu, 'no')
        .replace(/\by[._~|*\/\\:·-]+o\b/gu, 'yo');

    /* te...voy...a...matar -> te voy a matar */
    text = text.replace(
        /(?<=\p{L})[._~|*\/\\:·-]{2,}(?=\p{L})/gu,
        ' '
    );

    text = text.replace(
        /(?<=\p{L})\.\s+\.(?=\p{L})/gu,
        ' '
    );

    text = collapseSingleLetterRuns(text);

    text = text
        .replace(/\bv\s+o\s+y\b/gu, 'voy')
        .replace(/\bt\s+e\b/gu, 'te')
        .replace(/\bh\s+a\s+y\b/gu, 'hay')
        .replace(/\bl\s+o\b/gu, 'lo')
        .replace(/\bl\s+a\b/gu, 'la')
        .replace(/\bl\s+e\b/gu, 'le')
        .replace(/\bs\s+i\b/gu, 'si')
        .replace(/\bn\s+o\b/gu, 'no')
        .replace(/\by\s+o\b/gu, 'yo');

    text = text
        .replace(/\bha\s+que\b/gu, 'hay que')
        .replace(/\bvo\s+a\b/gu, 'voy a')
        .replace(/\b(lo|le|te)\s+oy\s+a\b/gu, '$1 voy a')
        .replace(/\baluien\b/gu, 'alguien');

    /*
     * Un salto de línea que no cierra una oración se trata como espacio. Esto
     * cubre evasiones del tipo `voy\na\ngolpearlo` sin mezclar párrafos cuyo
     * renglón anterior termina realmente en punto, pregunta o exclamación.
     */
    text = text.replace(
        /(?<![.!?;:])\n+(?=\p{L})/gu,
        ' '
    );

    /*
     * Cuando cada palabra fue separada por salto de línea, los saltos son parte
     * de la obfuscación y no límites semánticos. En comentarios normales con
     * párrafos largos se conservan.
     */
    if (text.includes('\n')) {
        const lines = text
            .split(/\n+/u)
            .map((line) => line.trim())
            .filter(Boolean);

        const shortLines = lines
            .filter((line) => line.length <= 18)
            .length;

        if (
            lines.length >= 3
            && shortLines / lines.length >= 0.60
        ) {
            text = text.replace(/\n+/gu, ' ');
        }
    }

    /* Variante de análisis para leetspeak. */
    text = text.replace(/[431057]/gu, (digit) => ({
        '4': 'a',
        '3': 'e',
        '1': 'i',
        '0': 'o',
        '5': 's',
        '7': 't'
    })[digit] ?? digit);

    text = text
        .replace(/[ \t]+/gu, ' ')
        .replace(/\n{3,}/gu, '\n\n')
        .trim();

    return text;
}

/**
 * Expone una única entrada canónica para las capas estructurales posteriores.
 */
function normalizeTextForAnalysis(value) {
    return deobfuscateThreatText(value);
}


/* ============================================================================
 * UTILIDADES LINGÜÍSTICAS
 * ============================================================================ */

/**
 * Extrae tokens alfanuméricos Unicode para comparaciones léxicas simples.
 */
function tokenizeWords(text) {
    return (
        String(text ?? '')
            .match(/[\p{L}\p{N}]+/gu)
        ?? []
    );
}

/**
 * Calcula distancia de edición acotada a una modificación; valores mayores se colapsan a 2.
 */
function levenshteinDistanceAtMostOne(left, right) {
    if (left === right) {
        return 0;
    }

    const a = Array.from(left);
    const b = Array.from(right);

    if (Math.abs(a.length - b.length) > 1) {
        return 2;
    }

    let i = 0;
    let j = 0;
    let edits = 0;

    while (i < a.length && j < b.length) {
        if (a[i] === b[j]) {
            i += 1;
            j += 1;
            continue;
        }

        edits += 1;

        if (edits > 1) {
            return 2;
        }

        if (a.length > b.length) {
            i += 1;
        } else if (b.length > a.length) {
            j += 1;
        } else {
            i += 1;
            j += 1;
        }
    }

    if (i < a.length || j < b.length) {
        edits += 1;
    }

    return edits;
}

/**
 * Busca vocabulario de daño con una sola alteración tipográfica tolerada.
 */
function containsFuzzyHarmWord(text) {
    const words =
        tokenizeWords(text)
            .map((word) =>
                word.replace(/(.)\1{2,}/gu, '$1')
            );

    for (const word of words) {
        if (word.length < 5) {
            continue;
        }

        for (const canonical of HARM_CANONICAL_WORDS) {
            if (
                Math.abs(word.length - canonical.length) <= 1
                && levenshteinDistanceAtMostOne(
                    word,
                    canonical
                ) <= 1
            ) {
                return true;
            }
        }
    }

    return false;
}

/**
 * Determina si el alcance contiene un objetivo explícitamente no humano.
 */
function hasNonHumanTarget(text) {
    const words =
        new Set(
            tokenizeWords(text)
        );

    for (const word of NON_HUMAN_WORDS) {
        if (words.has(word)) {
            return true;
        }
    }

    return false;
}

/**
 * Detecta acciones físicas dirigidas sintácticamente a objetos, procesos u otros blancos no humanos.
 */
function isPhysicalActionDirectedAtNonHuman(text) {
    const nonHumanAlternation =
        '(?:computadora|ordenador|teclado|monitor|celular|telefono|impresora|archivo|documento|caja|cartel|maqueta|puerta|mueble|planilla|disco|formulario|cable|version|cache|paquete|copia|sistema|servidor|proyecto|acuerdo|auto|coche|vehiculo|mesa|ventana|pared|pelota|mosquito|mosquitos|mosca|moscas|cucaracha|cucarachas|plaga|plagas|polilla|polillas|proceso|procesos|zombi|zombis|bacteria|bacterias|maleza|malezas|virus|script|scripts|tarea|tareas|task|worker|workers|job|jobs|hilo|thread|threads|servicio|servicios|daemon|instancia|instancias|pipeline|build|deploy|rama|ramas|endpoint|host|stock|demanda|margen|configuracion|carga|cargas|bug|error|boss|partida|partidas|combo|combos|marcador|goleada|partido)';

    const directPattern =
        new RegExp(
            `\\b(?:matar|golpear|pegar|patear|romper|destruir|lastimar|disparar)\\s+(?:a\\s+)?(?:el|la|los|las|un|una|unos|unas|este|esta|estos|estas)?\\s*${nonHumanAlternation}\\b`,
            'u'
        );

    return directPattern.test(text);
}


/**
 * Reconoce roles, profesiones y referencias nominales que representan personas.
 */
function hasHumanRole(text) {
    const words =
        new Set(
            tokenizeWords(text)
        );

    for (const word of HUMAN_ROLE_WORDS) {
        if (words.has(word)) {
            return true;
        }
    }

    return (
        /\b(?:el|la|al|a la|ese|esa|este|esta|aquel|aquella)\s+que\b/u.test(text)
        || /\b(?:quien|quién)\s+(?:escribi[oó]|public[oó]|respondi[oó]|defendi[oó])\b/u.test(text)
    );
}

/**
 * Reconoce destinatarios de segunda persona explícitos o codificados por voseo/imperativo.
 */
function hasSecondPersonTarget(text) {
    return (
        /\b(?:te|vos|usted|ustedes|contigo|con\s+vos|tu|tus)\b/u.test(text)
        || /\b(?:vas|sabes|tenes|podes|queres|haces|seguis|insistis|volves|salis|venis|andas|dormis|soles|llegas|apareces|terminas|estas|pasas|moves|esperas|entras|estacionas|quedas|cerras|tomas|caminas|frecuentas|paras|bajas|repetis|trabajas|publicas|republicas|expones|borras|etiquetas|contestas|respondes|subis|dejas|instalas|provocas|continuas|cortas|empujas|buscas|cruzas|nombras)\b/u.test(text)
        || /\b(?:si|cuando|apenas|en\s+cuanto|cada\s+vez\s+que|la\s+proxima\s+vez\s+que)\s+(?:publicas|republicas|repetis|borras|etiquetas|contestas|respondes|subis|dejas|instalas|provocas|empujas|buscas|pasas|cruzas|nombras|entras|salis|volves|apareces|venis|caminas)\b/u.test(text)
        || /\b(?:dormi|mira|cuidate|cuidese|pensalo|pensa|baja|deja|anda|veni|acordate|toma|guardate|presta|escucha|anota|borra|retira|saca|corregi|elimina|quita|cerra)\b/u.test(text)
        /* Enclíticos de segunda persona: verte, llenarte, cruzarte, romperte... */
        || /\b(?:ver|mir|dar|llen|marc|dej|hac|mand|romp|part|acomod|cruz|tir|baj|sac|peg|golpe|lastim|her|agred|revent)[\p{L}]{0,16}te\b/u.test(text)
        || /^(?:flac[oa]|amig[oa]|campeon(?:a)?|maestr[oa]|geni[oa]|herman[oa])\s*,/u.test(text)
    );
}

/**
 * Detecta clíticos de objeto ligados a verbos de daño o intención potencialmente humana.
 */
function hasCliticHumanTarget(text) {
    return (
        /\b(?:lo|la|los|las|le|les)\s+(?:voy|vamos|va|van|pienso|quiero)\s+a\b/u.test(text)
        || /\b(?:matar|golpear|lastimar|herir|agredir|asesinar|apunalar|acuchillar|reventar)(?:lo|la|los|las|le|les|te)\b/u.test(text)
        || /\b(?:pegar|disparar)(?:le|les|te)\b/u.test(text)
        || /\b(?:romper|dar|partir|bajar|mandar|hacer|dejar|llenar|marcar|acomodar|cruzar|tirar|sacar)(?:le|les|te|lo|la|los|las)\b/u.test(text)
        || /\b(?:matar|golpear|pegar|lastimar|herir|agredir|asesinar|apunalar|acuchillar|reventar|romper|dar|partir|bajar|mandar|hacer|dejar|llenar|marcar|acomodar|cruzar|tirar|sacar)[\p{L}]*(?:te|lo|la|los|las|le|les)\b/u.test(text)
        || /\b(?:ver|mir|llen|marc|dej|hac|mand|romp|part|acomod|cruz|tir|baj|sac|peg|golpe|lastim|her|agred|revent)[\p{L}]{0,16}(?:te|lo|la|los|las|le|les)\b/u.test(text)
    );
}

/**
 * Fusiona segunda persona, roles y clíticos, resolviendo antes los antecedentes no humanos.
 */
function hasHumanTarget(text) {
    const secondPerson =
        hasSecondPersonTarget(text);

    const role =
        hasHumanRole(text);

    const clitic =
        hasCliticHumanTarget(text);

    const nonHuman =
        hasNonHumanTarget(text);

    /*
     * Un clítico de objeto ("lo", "la") no implica que su antecedente sea humano.
     *
     * Ejemplo:
     *   "el proceso colgado: quiero reventarlo"
     *
     * V3 podía interpretar "reventarlo" como objetivo humano porque el clítico
     * activaba hasCliticHumanTarget(). V4.4 exige que, cuando hay un antecedente no
     * humano explícito y no existe segunda persona ni rol humano, el clítico se
     * resuelva como objeto.
     */
    if (
        nonHuman
        && !secondPerson
        && !role
    ) {
        return false;
    }

    return (
        secondPerson
        || role
        || clitic
    );
}

/**
 * Tolera una alteración tipográfica en causativos dirigidos del tipo “te dejo/te hago”.
 */
function hasFuzzyDirectedCausative(text) {
    const normalized = String(text ?? '');
    const match = /\bte\s+([\p{L}]{2,14})\b/u.exec(normalized);

    if (!match) {
        return false;
    }

    const candidate = match[1].replace(/(.)\1{2,}/gu, '$1');
    const canonicalForms = [
        'saco', 'lleno', 'desarmo', 'arruino', 'devuelvo', 'dejo',
        'hago', 'doy', 'mando', 'rompo', 'parto', 'acomodo', 'marco'
    ];

    return canonicalForms.some((canonical) =>
        Math.abs(candidate.length - canonical.length) <= 1
        && levenshteinDistanceAtMostOne(candidate, canonical) <= 1
    );
}

/**
 * Localiza evidencia de daño físico explícito, resultados corporales y eufemismos físicos.
 */
function findPhysicalHarmEvidence(text) {
    const normalized =
        String(text ?? '');

    /*
     * Nivel 1: verbos y sustantivos de violencia física inequívocos.
     * Se describen familias morfológicas, no oraciones de una batería.
     */
    const explicitPatterns = [
        /\b(?:matar|mato|mat[aá]s|mata|matan|mate|maten|matarlo|matarla|matarte|matarles)\b/u,
        /\basesin[\p{L}]*\b/u,
        /\bgolpe[\p{L}]*\b/u,
        /\bpegar(?:le|les|te|lo|la)?\b|\bpeg(?:o|as|a|an|ue|uen|ando|ado|ada|ados|adas)\b/u,
        /\blastim[\p{L}]*\b/u,
        /\bherir\b|\bhier(?:o|es|e|en|a|an)\b|\bherid[oa]s?\b/u,
        /\bagred[\p{L}]*\b/u,
        /\bapuñal[\p{L}]*\b/u,
        /\bacuchill[\p{L}]*\b/u,
        /\bdispar[\p{L}]*\b/u,
        /\bfusil[\p{L}]*\b/u,
        /\bestrangul[\p{L}]*\b/u,
        /\btortur[\p{L}]*\b/u,
        /\batropell[\p{L}]*\b/u,
        /\b(?:revent(?:ar|arlo|arla|arte|as|a|o|e|en)|revient(?:o|as|a|an|e|en))\b/u,
        /\b(?:paliza|golpiza|trompadas?|pinas?|pinazos?|punetazos?|cachetadas?|patadas?|balazos?|punaladas?|moretones?)\b/u,
        /\b(?:cagar|cago|cag[aá]s|caga|cague|caguen|cagarlo|cagarla|cagarte)\s+a\s+(?:palos|trompadas)\b/u,
        /\btir[\p{L}]*(?:te|lo|la|le|los|las|les)?\b[^.!?;:]{0,35}\bal\s+piso\b/u
    ];

    for (const pattern of explicitPatterns) {
        const match = pattern.exec(normalized);

        if (match) {
            return {
                found: true,
                text: match[0],
                index: match.index,
                fuzzy: false,
                kind: 'explicit-violence'
            };
        }
    }

    /*
     * Nivel 2: resultados corporales causados por una acción. Esta capa permite
     * entender formas nuevas como "te dejo sin poder caminar" o "te hago terminar
     * en una guardia" sin enumerar cada oración posible.
     */
    const causativeStem =
        String.raw`(?:dej|ejo|ha[gc]|ago|mand|llen|arruin|desarm|acomod|sac|part|romp|mol|dobl|tir|marc|ense[nñ]|explic|correg|devolv|castig|doy|dar|met|estamp)[\p{L}]*`;

    const bodilyOutcome =
        String.raw`(?:` +
        String.raw`sin\s+poder\s+(?:levant[\p{L}]*|seguir|contest[\p{L}]*|camin[\p{L}]*|mover[\p{L}]*)|` +
        String.raw`hasta\s+que\s+(?:no\s+puedas?\s+(?:seguir|levantarte|caminar|contestar|moverte)|caigas?|quedes?\s+tirad[oa])|` +
        String.raw`(?:en|a)\s+(?:(?:una|un|la|el)\s+)?(?:guardia|hospital)|` +
        String.raw`pidiendo\s+ayuda|` +
        String.raw`(?:hech[oa]\s+)?(?:bolsa|trapo)|` +
        String.raw`(?:en|contra)\s+el\s+piso|besar\s+el\s+piso|conocer\s+el\s+piso|` +
        String.raw`contando\s+los\s+dientes|sin\s+dientes|` +
        String.raw`doblad[oa]\s+del\s+dolor|` +
        String.raw`tirad[oa]|marcad[oa]|` +
        String.raw`la\s+cara\s+(?:rota|hinchada)|` +
        String.raw`dolor|moretones?` +
        String.raw`)`;

    const causativePattern =
        new RegExp(
            `\\b${causativeStem}\\b[^.!?;:]{0,70}\\b${bodilyOutcome}\\b`,
            'u'
        );

    const causativeMatch =
        causativePattern.exec(normalized);

    if (causativeMatch) {
        return {
            found: true,
            text: causativeMatch[0],
            index: causativeMatch.index,
            fuzzy: false,
            kind: 'bodily-outcome'
        };
    }

    /*
     * Nivel 3: construcciones violentas lexicalizadas y eufemismos físicos donde
     * la materialidad corporal es explícita aunque no aparezca "matar/golpear".
     */
    const physicalEuphemismPatterns = [
        /\b(?:aprender|entender|recordar)\b[^.!?;:]{0,60}\bcon\s+el\s+cuerpo\b/u,
        /\bsin\s+palabras\b[^.!?;:]{0,50}\bcon\s+las\s+manos\b/u,
        /\bde\s+(?:(?:una|la)\s+)?manera\s+fisica\b/u,
        /\b(?:respuesta|explicacion|clase)\b[^.!?;:]{0,70}\b(?:golpes|paliza|moretones|fisic[oa])\b/u,
        /\b(?:del|desde\s+el)\s+teclado\b[^.!?;:]{0,70}\ba\s+(?:los\s+golpes|una\s+golpiza|trompadas|punetazos|pinas|pinazos)\b/u,
        /\btermin[\p{L}]*\s+con\s+(?:vos|usted|el|ella)\s+en\s+el\s+piso\b/u,
        /\b(?:(?:a\s+los|a|con|por|a\s+fuerza\s+de)\s+(?:golpes|trompadas|punetazos|pinas|pinazos))\b/u,
        /\b(?:romp|part)[\p{L}]*(?:le|te)?\b[^.!?;:]{0,30}\b(?:cara|boca|huesos?)\b/u,
        /\b(?:baj|sac)[\p{L}]*(?:le|te)?\b[^.!?;:]{0,30}\b(?:dientes|ganas)\b[^.!?;:]{0,30}\ba\s+golpes\b/u,
        /\b(?:la\s+)?proxim[ao]\s+(?:explicacion|discusion|charla|respuesta|aclaracion)\b[^.!?;:]{0,80}\b(?:viene\s+en\s+forma\s+de|termina\s+en|va\s+a\s+ser\s+con)\s+(?:una\s+)?(?:golpiza|paliza|golpes|punetazos|trompadas|pinas|pu[nñ]os)\b/u,
        /\b(?:la\s+)?(?:discusion|respuesta|explicacion|charla)\s+(?:siguiente|que\s+sigue)\b[^.!?;:]{0,80}\b(?:la\s+)?(?:cierro|termino|resuelvo)\b[^.!?;:]{0,50}\b(?:a\s+)?(?:golpes|punetazos|trompadas|pinas|con\s+los\s+punos)\b/u,
        /\b(?:respuesta|mensaje|discusion|explicacion)\b[^.!?;:]{0,80}\b(?:no\s+se\s+escribe|se\s+pega|va\s+a\s+doler|la\s+vas\s+a\s+sentir\s+en\s+el\s+cuerpo)\b/u
    ];

    for (const pattern of physicalEuphemismPatterns) {
        const match = pattern.exec(normalized);

        if (match) {
            return {
                found: true,
                text: match[0],
                index: match.index,
                fuzzy: false,
                kind: 'physical-euphemism'
            };
        }
    }

    if (containsFuzzyHarmWord(normalized)) {
        return {
            found: true,
            text: null,
            index: -1,
            fuzzy: true,
            kind: 'fuzzy-harm-lexeme'
        };
    }

    return {
        found: false,
        text: null,
        index: -1,
        fuzzy: false,
        kind: null
    };
}

/**
 * Detecta intención futura o prospectiva del emisor respecto de una acción.
 */
function hasExplicitIntent(text) {
    return (
        /\b(?:yo\s+)?(?:voy|vamos)\s+a\b/u.test(text)
        || /\b(?:pienso|planeo|quiero|quisiera|tengo\s+pensado)\b/u.test(text)
        || /\b(?:dan|me\s+dan)\s+ganas\s+de\b/u.test(text)
        || /\b(?:mi\s+)?intencion\s+(?:real\s+)?(?:es|era)\b/u.test(text)
        || /\bno\s+(?:pienso|voy)(?:\s+a)?\s+parar\s+hasta\b/u.test(text)
        || /\bcuando\s+(?:te|lo|la|los|las|nos)\s+(?:vea|veamos|cruce|crucemos|encuentre|encontremos)\b/u.test(text)
        || /\b(?:apenas|en\s+cuanto|la\s+proxima\s+vez)\b/u.test(text)
    );
}

/**
 * Detecta pedidos, deseos o formulaciones que incitan a terceros a causar daño.
 */
function hasIncitement(text) {
    return (
        /\b(?:hay|habria)\s+que\b/u.test(text)
        || /\b(?:alguien|alguno|alguna)\s+(?:deberia|tendria)(?:\s+que)?\b/u.test(text)
        || /\b(?:deberian|tendrian)(?:\s+que)?\b/u.test(text)
        || /\bque\s+alguien\b/u.test(text)
        || /\bque\s+(?:le|lo|la|los|las)\s+(?:peguen|golpeen|lastimen|hieran|agredan|maten|revienten|rompan|bajen|manden)\b/u.test(text)
        || /\b(?:se\s+merece|merece)\s+que\b/u.test(text)
        || /\ble\s+vendria\s+bien\b/u.test(text)
        || /\ba\s+ver\s+(?:si\s+aparece\s+)?quien\b[^.!?;:]{0,140}\b(?:de|da|tire|tira|mande|manda|golpee|pegue|reviente|rompa)\b/u.test(text)
        || /\bquien\s+se\s+ocupa\s+de\b[^.!?;:]{0,140}\b(?:tirar|mandar|golpear|pegar|romper|reventar)\b/u.test(text)
        || /\b(?:ya\s+)?(?:deberia|tendria)\s+haber\s+quien\b/u.test(text)
        || /\b(?:ya\s+)?va\s+siendo\s+hora\s+de\s+que\s+(?:un\s+par|varios|algunos?)\b/u.test(text)
        || /\bque\s+(?:un\s+par|varios|algunos?)\b[^.!?;:]{0,100}\b(?:agarren|golpeen|peguen|hagan|dejen|tiren|ense[nñ]en)\b/u.test(text)
    );
}

/**
 * Reconoce marcos condicionales o temporales que pueden introducir una consecuencia amenazante.
 */
function hasConditionalThreatFrame(text) {
    return /\b(?:si|cuando|apenas|en\s+cuanto|la\s+proxima\s+vez)\b/u.test(text);
}

/**
 * Reconoce deseos explícitos de que otra persona sufra una consecuencia dañina.
 */
function hasExplicitHarmWish(text) {
    return (
        /\bojala\b/u.test(text)
        || /\b(?:espero|deseo)\s+que\b/u.test(text)
        || /\bme\s+gustaria\s+(?:que\b|ver(?:te|lo|la|los|las)?\b)/u.test(text)
    );
}

/**
 * Agrupa marcos prospectivos e intimidatorios generales usados por el rescate semántico.
 */
function hasGeneralSemanticThreatCue(text) {
    const normalized = String(text ?? '');

    const prospectivePersonalFrame =
        /\b(?:te|lo|la|le|les)\s+(?:(?:voy|vamos|va|van)\s+a|(?:pienso|quiero|planeo)\s+)[^.!?;:]{0,120}\b/u.test(normalized)
        || /\bvas\s+a\s+[^.!?;:]{1,120}\b/u.test(normalized)
        || /\b(?:cuando|apenas|en\s+cuanto|la\s+proxima\s+vez)\b[^.!?;:]{0,160}\b(?:te|vos|usted|ustedes|tu|tus|vas|salgas|aparezcas|vuelvas|cruces|encuentres)\b/u.test(normalized);

    const adverseOutcomeFrame =
        /\b(?:vas|va|van)\s+a\s+(?:terminar|acabar|quedar|aprender|entender|recordar|lamentar|descubrir|saber|ver)\b/u.test(normalized)
        || /\bno\s+(?:vas|va|van)\s+a\s+poder\b/u.test(normalized)
        || /\b(?:te|le)\s+puede\s+(?:pasar|ocurrir)\s+algo\b/u.test(normalized);

    return (
        prospectivePersonalFrame
        || adverseOutcomeFrame
        || hasRoutineKnowledgeCue(normalized)
        || hasImpliedGroupIntimidationCue(normalized)
        || hasOfflineIntimidationCue(normalized)
        || hasVeiledThreatCue(normalized)
        || hasCoerciveThreatCue(normalized)
        || hasRetaliationCue(normalized)
        || hasImpliedPersonalConsequenceCue(normalized)
        || hasUltimatumCue(normalized)
    );
}


/**
 * Detecta conocimiento de horarios, recorridos o rutinas personales con potencial intimidatorio.
 */
function hasRoutineKnowledgeCue(text) {
    const normalized = String(text ?? '');

    const knowledge =
        /\b(?:se|ya\s+(?:se|vi|registre|entendi|ubique)|tengo\s+(?:ubicad[oa]|vist[oa]|presente|claro|fichad[oa]|anotad[oa]|registrad[oa])|conozco|ubique|registre)\b/u.test(normalized);

    const routineDetail =
        /\b(?:hora|horario|franja|dias?|donde|por\s+donde|cuando|camino|recorrido|trayecto|zona|barrio|esquina|rutina|trabajo|colectivo|parada|lugar|sitio|entrada|salida|salis|volves|vivis|paras|bajas|llegas|apareces|pasas|moves|esperas|entras|estacionas|quedas|cerras|tomas|caminas|frecuentas|terminas|solo|sola|nadie\s+cerca|no\s+hay\s+nadie)\b/u.test(normalized);

    return (
        knowledge && routineDetail
        || /\b(?:tu|tus)\s+(?:casa|familia|hijos?|hijas?|rutina|horarios?|recorrido|trabajo|camino|parada)\b[^.!?;:]{0,100}\b(?:se|conozco|ubique|vi|tengo)\b/u.test(normalized)
    );
}


/**
 * Detecta desplazamiento intimidatorio desde la discusión online hacia encuentros presenciales.
 */
function hasOfflineIntimidationCue(text) {
    const normalized = String(text ?? '');

    return (
        /\b(?:cara\s+a\s+cara|en\s+persona|fuera\s+de\s+internet|fuera\s+de\s+la\s+pagina|sin\s+pantalla|en\s+la\s+calle|por\s+el\s+barrio|a\s+pocos\s+metros|cuando\s+nos\s+veamos|cuando\s+te\s+vea|cuando\s+te\s+cruce|cuando\s+te\s+encuentre|si\s+nos\s+vemos|si\s+nos\s+cruzamos)\b/u.test(normalized)
        || /\b(?:espera|esperame|aguarda|aguardame)\s+que\s+te\s+(?:encuentre|cruce|vea)(?:\s+y)?(?:\s+despues)?\s+(?:hablamos|lo\s+hablamos|arreglamos)\b/u.test(normalized)
        || /\b(?:mirar|miraria|mire|mira|empezaria\s+a\s+mirar)\s+(?:bien\s+|mejor\s+)?(?:alrededor|para\s+los\s+costados)\b/u.test(normalized)
        || /\b(?:prestar|prestaria|preste)\s+atencion\b[^.!?;:]{0,80}\b(?:al\s+salir|proximo\s+cruce|alrededor|despues\s+de\s+esto)\b/u.test(normalized)
        || /\b(?:dormi|duerme|duerman)\s+con\s+un\s+ojo\s+abierto\b/u.test(normalized)
        || /\b(?:la\s+)?pantalla\b[^.!?;:]{0,80}\b(?:protege|proteger|salva|alcanza)\b/u.test(normalized)
        || /\binternet\b[^.!?;:]{0,60}\bno\s+evita\b[^.!?;:]{0,60}\bencuentros?\b/u.test(normalized)
        || /\b(?:lugares?|zona|barrio)\b[^.!?;:]{0,100}\b(?:no\s+le\s+conviene|no\s+conviene|no\s+le\s+convenga)\b[^.!?;:]{0,60}\b(?:aparecer|pasar|volver)\b/u.test(normalized)
        || /\bno\s+(?:le\s+)?conviene\b[^.!?;:]{0,80}\b(?:seguir\s+caminando|aparecer|pasar|volver)\b/u.test(normalized)
        || /\bproximo\s+cruce\b[^.!?;:]{0,60}\bcordial\b/u.test(normalized)
        || /\bquien\s+puede\s+reconocer(?:te|lo|la|le)\b/u.test(normalized)
        || /\b(?:encuentro|cruce)\s+fuera\s+del\s+sitio\b/u.test(normalized)
        || /\bno\s+(?:daria|des)\s+por\s+(?:seguro|sentado)\b[^.!?;:]{0,100}\b(?:proximo\s+cruce|todo\s+quede\s+en\s+internet|siempre\s+habra\s+gente\s+cerca|proximo\s+contacto\s+sera\s+por\s+escrito)\b/u.test(normalized)
        || /\bpensaria\s+dos\s+veces\s+por\s+donde\s+caminar\b/u.test(normalized)
        || /\bprestaria\s+atencion\s+cuando\s+vuelvas\s+solo\b/u.test(normalized)
        || /\bno\s+asumiria\s+que\s+todo\s+queda\s+en\s+internet\b/u.test(normalized)
    );
}


/**
 * Reconoce advertencias veladas cuya intimidación surge de la relación entre contexto y consecuencia.
 */
function hasVeiledThreatCue(text) {
    const normalized = String(text ?? '');

    return (
        /\bmas\s+le\s+vale\b[^.!?;:]{0,120}\bno\s+aparecer\b/u.test(normalized)
        || /\ble\s+conviene\s+no\s+quedarse\s+hasta\s+tarde\b[^.!?;:]{0,100}\bvuelve\s+sin\s+problemas\b/u.test(normalized)
        || /\byo\s+que\b[^.!?;:]{0,100}\bmiraria\s+(?:dos\s+veces\s+|bien\s+|mejor\s+)?alrededor\b/u.test(normalized)
        || /\bcomentarios?\b[^.!?;:]{0,100}\brecordarse\s+cara\s+a\s+cara\b/u.test(normalized)
        || /\b(?:no\s+todos\s+los\s+)?avisos?\b[^.!?;:]{0,120}\bvan\s+a\s+venir\b[^.!?;:]{0,80}\bpalabras\s+tan\s+claras\b/u.test(normalized)
        || /\bcuentas?\b[^.!?;:]{0,100}\barregl[\p{L}]*\s+cara\s+a\s+cara\b/u.test(normalized)
        || /\bno\s+crea\b[^.!?;:]{0,100}\bpantalla\b[^.!?;:]{0,80}\bprotege\b/u.test(normalized)
        || /\bpuede\s+terminar\s+encontrando\s+respuesta\s+fuera\s+de\s+internet\b/u.test(normalized)
        || /\bno\s+se\s+confie\b[^.!?;:]{0,100}\bvuelva\s+a\s+pasar\b/u.test(normalized)
        || /\ble\s+convendria\s+aprender\s+a\s+mirar\s+alrededor\b/u.test(normalized)
        || /\bno\s+de\s+por\s+sentado\b[^.!?;:]{0,100}\bproximo\s+cruce\b[^.!?;:]{0,50}\bcordial\b/u.test(normalized)
        || /\binternet\b[^.!?;:]{0,60}\bno\s+evita\b[^.!?;:]{0,60}\bencuentros?\b/u.test(normalized)
        || /\ble\s+vendria\s+bien\s+prestar\s+atencion\b[^.!?;:]{0,100}\b(?:cuando|al)\b/u.test(normalized)
        || /\b(?:hay\s+)?lugares?\b[^.!?;:]{0,120}\bno\s+le\s+conv(?:iene|enga)\b[^.!?;:]{0,70}\baparecer\b/u.test(normalized)
        || /\bno\s+le\s+conviene\s+seguir\s+caminando\s+como\s+si\s+nada\b/u.test(normalized)
        || /\b(?:a|al)\s+(?:la|el)?\s*(?:persona|periodista|panelista|redactor|redactora|autor|autora|vocero|vocera|moderador|moderadora|usuario|usuaria|responsable|dirigente|conductora|conductor)\b[^.!?;:]{0,120}\ble\s+conviene\s+(?:evitar\s+ciertos\s+lugares|pensar\s+bien\s+por\s+donde\s+vuelve|mirar\s+quien\s+tiene\s+alrededor)\b/u.test(normalized)
        || /\bhay\s+respuestas\s+para\b[^.!?;:]{0,120}\b(?:pueden\s+llegar\s+cuando\s+no\s+este\s+conectad[oa]|no\s+necesitan\s+quedar\s+escritas)\b/u.test(normalized)
        || /\b(?:persona|periodista|panelista|redactor|redactora|autor|autora|vocero|vocera|moderador|moderadora|usuario|usuaria|responsable|dirigente|conductora|conductor)\b[^.!?;:]{0,100}\bno\s+(?:crea|suponga|de\s+por\s+hecho)\b[^.!?;:]{0,130}\b(?:distancia\s+de\s+una\s+pantalla\s+dura\s+para\s+siempre|una\s+cuenta\s+lo\s+mantiene\s+lejos\s+de\s+todos|siempre\s+habra\s+gente\s+cerca)\b/u.test(normalized)
        || /\bno\s+todos\s+los\s+cruces\s+con\b[^.!?;:]{0,120}\bvan\s+a\s+ser\s+por\s+comentarios\b/u.test(normalized)
        || /\bpuede\s+encontrarse\s+una\s+respuesta\s+donde\s+menos\s+la\s+espere\b/u.test(normalized)
        || /\bquizas?\s+deberia\s+cambiar\s+algunas\s+costumbres\s+por\s+unos\s+dias\b/u.test(normalized)
        || /\bsi\b[^.!?;:]{0,110}\b(?:insistis|volves|republicas|publicas|repetis|seguis|lo\s+haces|nombras|expones)\b[^.!?;:]{0,180}\b(?:miraria\s+(?:bien\s+|mejor\s+)?alrededor|prestaria\s+atencion\s+(?:al\s+proximo\s+cruce|cuando\s+vuelvas\s+solo)|no\s+ignoraria\s+quien\s+puede\s+reconocerte|no\s+asumiria\s+que\s+(?:la\s+distancia\s+de\s+la\s+pantalla\s+alcanza|todos\s+los\s+contactos\s+seran\s+por\s+pantalla|el\s+proximo\s+cruce\s+sera\s+tranquilo|todo\s+quede\s+en\s+internet)|no\s+daria\s+por\s+seguro\s+(?:el\s+proximo\s+cruce|que\s+todo\s+quede\s+en\s+internet)|mejor\s+no\s+des\s+por\s+sentado\s+que\s+siempre\s+habra\s+gente\s+cerca|no\s+descartaria\s+un\s+encuentro\s+(?:incomodo|fuera\s+del\s+sitio)|convendria\s+no\s+confiarse\s+demasiado\s+al\s+salir|cuidaria\s+un\s+poco\s+mas\s+los\s+recorridos|pensaria\s+dos\s+veces\s+por\s+donde\s+caminar|yo\s+no\s+caminaria\s+tan\s+confiado)\b/u.test(normalized)
        || /\byo\s+que\b[^.!?;:]{0,140}\b(?:no\s+descartaria\s+un\s+encuentro\s+fuera\s+del\s+sitio|no\s+daria\s+por\s+seguro\s+el\s+proximo\s+cruce|no\s+caminaria\s+tan\s+confiad[oa]|revisaria\s+cuanto\s+se\s+expone\s+en\s+persona)\b/u.test(normalized)
        || /\b(?:al|a\s+la)\b[^.!?;:]{0,140}\b(?:quizas?\s+deberia\s+cambiar\s+algunas\s+costumbres\s+por\s+unos\s+dias|le\s+conviene\s+pensar\s+bien\s+por\s+donde\s+vuelve(?:\s+despues\s+de\s+esto)?|le\s+conviene\s+mirar\s+quien\s+tiene\s+alrededor\s+cuando\s+salga|deberia\s+pensar\s+dos\s+veces\s+antes\s+de\s+volver\s+sol[oa]|le\s+conviene\s+evitar\s+ciertos\s+lugares\s+despues\s+de\s+esto)\b/u.test(normalized)
        || /\bque\b[^.!?;:]{0,140}\b(?:no\s+de\s+por\s+(?:hecho|sentado)\s+que\s+(?:siempre\s+habra\s+gente\s+cerca|el\s+proximo\s+contacto\s+sera\s+por\s+escrito)|no\s+suponga\s+que\s+una\s+cuenta\s+lo\s+mantiene\s+lejos\s+de\s+todos|recuerde\s+que\s+internet\s+no\s+evita\s+los\s+encuentros|no\s+se\s+confie\s+demasiado\s+con\s+sus\s+recorridos)\b/u.test(normalized)
        || /\b(?:no\s+todos\s+los\s+cruces|hay\s+respuestas|hay\s+cuentas)\b[^.!?;:]{0,170}\b(?:por\s+comentarios|no\s+necesitan\s+quedar\s+escritas|cara\s+a\s+cara)\b/u.test(normalized)
        || /\bmas\s+vale\s+que\b[^.!?;:]{0,140}\b(?:mire\s+alrededor\s+al\s+salir|tenga\s+presente\s+quien\s+puede\s+reconocer(?:lo|la|los|las|le))\b/u.test(normalized)
        || /\bno\s+caminaria\s+con\s+tanta\s+confianza\s+despues\s+de\s+hablar\s+asi\b/u.test(normalized)
        || /\bno\s+suponga\s+que\s+una\s+cuenta\s+(?:lo|la)\s+mantiene\s+lejos\s+de\s+todos\b/u.test(normalized)
        || /\bno\s+crea\s+que\s+la\s+distancia\s+de\s+una\s+pantalla\s+dura\s+para\s+siempre\b/u.test(normalized)
        || /\bno\s+daria\s+por\s+seguro\s+el\s+proximo\s+encuentro\b/u.test(normalized)
        || /\bno\s+asumiria\s+que\s+la\s+proxima\s+respuesta\s+sera\s+escrita\b/u.test(normalized)
        || /\bno\s+me\s+confiaria\s+en\s+el\s+proximo\s+encuentro\b/u.test(normalized)
        || /\bpensaria\s+bien\s+el\s+proximo\s+recorrido\b/u.test(normalized)
        || /\byo\s+tendria\s+mas\s+cuidado\s+al\s+salir\b/u.test(normalized)
    );
}


/**
 * Detecta cierres breves que refuerzan una lectura intimidatoria de la cláusula anterior.
 */
function hasIntimidatingTailCue(text) {
    const normalized = String(text ?? '');

    return (
        /\b(?:cuidate|cuidese|tenga\s+cuidado|tomaria\s+el\s+aviso\s+en\s+serio|leer\s+el\s+aviso|entienda\s+el\s+mensaje|no\s+(?:te|se)\s+confie|no\s+me\s+confiaria|no\s+te\s+confiaria|no\s+tendria\s+tanta\s+confianza|no\s+tendria\s+tanta\s+tranquilidad|bajaria\s+el\s+perfil|pensaria\s+(?:bien|dos\s+veces)|miraria\s+(?:bien\s+|mejor\s+)?alrededor|mirar\s+para\s+los\s+costados|no\s+daria\s+por\s+hecho|no\s+sigas?\s+provocando|tirando\s+de\s+la\s+cuerda|probaria\s+hasta\s+donde|no\s+conviene\s+ignorarlo|saque\s+(?:sus\s+)?conclusiones)\b/u.test(normalized)
        || /\b(?:yo\s+)?(?:empezaria\s+a\s+mirar\s+(?:bien\s+|mejor\s+)?alrededor|revisaria\s+ciertas\s+costumbres|prestaria\s+mas\s+atencion\s+cuando\s+salgo|no\s+jugaria\s+con\s+(?:esa\s+)?informacion|no\s+lo\s+ignoraria|no\s+jugaria\s+con\s+esa\s+posibilidad)\b/u.test(normalized)
        || /\b(?:conviene|convendria)\s+(?:que\s+)?(?:prestes?\s+atencion|no\s+confiarse|no\s+dar\s+nada\s+por\s+sentado)\b/u.test(normalized)
        || /\bquizas\s+te\s+convenga\s+cambiar\s+la\s+rutina\b/u.test(normalized)
        || /\bno\s+asumiria\b[^.!?;:]{0,100}\b(?:tranquil|nadie\s+te\s+esta\s+mirando|distancia\s+de\s+la\s+pantalla\s+alcanza)\b/u.test(normalized)
        || /\bcada\s+uno\s+(?:sabra\s+leer\s+el\s+aviso|vera)\b/u.test(normalized)
        || /\b(?:el\s+)?mensaje\s+esta\s+puesto\b/u.test(normalized)
        || /\b(?:mejor\s+)?interpretalo\s+a\s+tiempo\b/u.test(normalized)
        || /\bvos\s+sabras\s+que\s+hacer\s+con\s+el\s+aviso\b/u.test(normalized)
        || /\bno\s+hace\s+falta\s+(?:que\s+lo\s+detalle|desarrollar\s+mas)\b/u.test(normalized)
        || /\bno\s+lo\s+tomaria\s+(?:tan\s+livianamente|a\s+la\s+ligera)\b/u.test(normalized)
        || /\b(?:mejor\s+)?baja\s+un\s+poco\s+el\s+tono\b/u.test(normalized)
        || /\bconviene\s+que\s+no\s+te\s+confies\b/u.test(normalized)
        || /\bno\s+estaria\s+tan\s+relajad[oa]\s+despues\s+de\s+esto\b/u.test(normalized)
        || /\bno\s+seguiria\s+(?:tentando\s+la\s+suerte|empujando\s+(?:el\s+tema|la\s+situacion))\b/u.test(normalized)
        || /\bno\s+daria\s+por\s+seguro\s+que\s+siempre\s+sera\s+tranquil[oa]\b/u.test(normalized)
        || /\bno\s+conviene\s+tentar\s+(?:a\s+)?la\s+suerte\b/u.test(normalized)
        || /\bpensalo\s+antes\s+de\s+(?:otra|hacer\s+otra)\s+provocacion\b/u.test(normalized)
        || /\btomaria\s+en\s+serio\s+que\s+alguien\s+conozca\s+esos\s+horarios\b/u.test(normalized)
        || /\bmejor\s+no\s+ignores?\s+(?:el\s+dato|esto)\b/u.test(normalized)
        || /\bcada\s+cual\s+entiende\s+cuando\s+le\s+conviene\b/u.test(normalized)
        || /\bhay\s+avisos\s+que\s+alcanzan\s+con\s+una\s+vez\b/u.test(normalized)
        || /\bmejor\s+no\s+averiguar\s+que\s+significa\b/u.test(normalized)
        || /\bno\s+hace\s+falta\s+escribir\s+todo\s+de\s+manera\s+literal\b/u.test(normalized)
        || /\bhasta\s+ahi\s+llega\s+la\s+explicacion\b/u.test(normalized)
        || /\bmejor\s+no\s+sigas?\s+provocando\b/u.test(normalized)
        || /\byo\s+bajaria\s+el\s+perfil\b/u.test(normalized)
        || /\bpensaria\s+bien\s+antes\s+de\s+repetirlo\b/u.test(normalized)
        || /\b(?:mas\s+vale\s+que\s+)?prestes?\s+atencion\b/u.test(normalized)
        || /\byo\s+tendria\s+mas\s+cuidado(?:\s+al\s+volver)?\b/u.test(normalized)
        || /\bmejor\s+toma\s+en\s+serio\s+(?:el\s+dato|esto|el\s+aviso)\b/u.test(normalized)
        || /\bmejor\s+no\s+actues\s+con\s+tanta\s+confianza\b/u.test(normalized)
        || /\bno\s+conviene\s+tentar\s+(?:a\s+)?la\s+suerte\b/u.test(normalized)
        || /\bno\s+asumiria\s+que\s+todo\s+queda\s+en\s+internet\b/u.test(normalized)
        || /\bno\s+seguiria\s+actuando\s+con\s+tanta\s+confianza\b/u.test(normalized)
        || /\byo\s+no\s+seguiria\s+tentando\s+la\s+suerte\b/u.test(normalized)
        || /\byo\s+tendria\s+mas\s+cuidado\s+al\s+volver\b/u.test(normalized)
        || /\bno\s+asumiria\s+que\s+nadie\s+te\s+esta\s+mirando\b/u.test(normalized)
        || /\bmas\s+vale\s+que\s+prestes\s+atencion\b/u.test(normalized)
        || /\bno\s+estaria\s+tan\s+relajad[oa]\b/u.test(normalized)
        || /\bconviene\s+que\s+no\s+lo\s+olvides\b/u.test(normalized)
        || /\byo\s+bajaria\s+el\s+tono\b/u.test(normalized)
        || /\byo\s+no\s+daria\s+nada\s+por\s+sentado\b/u.test(normalized)
        || /\bmejor\s+no\s+sigas\s+empujando\s+la\s+situacion\b/u.test(normalized)
        || /\bmejor\s+no\s+te\s+confies\s+demasiado\b/u.test(normalized)
        || /\byo\s+prestaria\s+atencion\b/u.test(normalized)
    );
}


/**
 * Detecta intimidación grupal implícita que gana sentido amenazante al combinarse
 * con vigilancia/rutina, proximidad o una cola de advertencia.
 */
function hasImpliedGroupIntimidationCue(text) {
    const normalized = String(text ?? '');

    const groupCue =
        /\b(?:no\s+soy\s+el\s+unico\s+que\s+te\s+tiene\s+marcado|mas\s+de\s+uno\s+quiere\s+cruzarte|no\s+estoy\s+solo\s+en\s+esto|hay\s+varios\s+que\s+ya\s+se\s+cansaron|somos\s+varios|somos\s+suficientes\s+para\s+encontrarte|somos\s+mas\s+de\s+los\s+que\s+pensas|hay\s+gente\s+que\s+no\s+se\s+olvido|hay\s+varios\s+pendientes\s+de\s+vos|mas\s+de\s+uno\s+quedo\s+esperando|hay\s+un\s+grupo\s+que\s+tomo\s+nota|aca\s+somos\s+unos\s+cuantos|aca\s+hay\s+unos\s+cuantos\s+con\s+memoria|no\s+es\s+una\s+sola\s+persona\s+la\s+que\s+esta\s+enojada|varios\s+ya\s+sabemos\s+quien\s+sos|hay\s+mas\s+de\s+uno\s+pendiente\s+de\s+vos)\b/u.test(normalized);

    return groupCue && (
        hasRoutineKnowledgeCue(normalized)
        || hasOfflineIntimidationCue(normalized)
        || hasIntimidatingTailCue(normalized)
        || /\b(?:marcado|cruzarte|nadie\s+cerca|mirando|par[a|a]s|hora|horario|lugar|sitio)\b/u.test(normalized)
    );
}

/**
 * Amplía las colas intimidatorias usadas específicamente para recomponer coerción adyacente.
 */
function hasCoerciveIntimidatingTailCue(text) {
    const normalized = String(text ?? '');

    return (
        hasIntimidatingTailCue(normalized)
        || /\bdespues\s+sera\s+tarde\s+para\s+hacerse\s+el\s+distraido\b/u.test(normalized)
        || /\bmejor\s+que\s+no\s+lo\s+ponga\s+a\s+prueba\b/u.test(normalized)
        || /\bel\s+mensaje\s+esta\s+bastante\s+claro\b/u.test(normalized)
        || /\bno\s+hace\s+falta\s+que\s+explique\s+mas\b/u.test(normalized)
        || /\btomalo\s+como\s+quieras\b/u.test(normalized)
        || /\bcada\s+uno\s+sabra\s+leer\s+el\s+aviso\b/u.test(normalized)
    );
}

/**
 * Reconoce exigencias dirigidas de borrar, retirar, corregir o detener una acción.
 */
function hasCoerciveDemandCue(text) {
    const normalized = String(text ?? '');

    return /\b(?:borra|borr[aá]|retira|retir[aá]|saca|sac[aá]|deja|dej[aá]|corrige|correg[ií]|elimina|elimin[aá]|baja|baj[aá]|quita|quit[aá]|cierra|cerr[aá]|no\s+publiques|no\s+vuelvas\s+a|no\s+sigas|no\s+insistas)\b/u.test(normalized);
}

/**
 * Exige una demanda y una consecuencia intimidatoria compatibles dentro del mismo alcance.
 */
function hasCoerciveThreatCue(text) {
    const normalized = String(text ?? '');

    const demand =
        hasCoerciveDemandCue(normalized);

    const consequence =
        /\b(?:antes\s+de\s+que|si\s+quer[eé]s|si\s+no\s+quer[eé]s|para\s+que)\b[^.!?;:]{0,120}\b(?:tranquil[\p{L}]*|calma|problemas?|consecuencias?|encuentro|en\s+persona|personal|afuera|otra\s+cosa|mas\s+lejos|averiguar\s+que\s+sigue|quede\s+aca|se\s+complique)\b/u.test(normalized)
        || /\b(?:ahorrate|evitate)\s+(?:problemas?|consecuencias?|un\s+encuentro)\b/u.test(normalized)
        || /\bno\s+(?:habra|haya|vas\s+a\s+tener)\s+necesidad\b[^.!?;:]{0,80}\b(?:ir\s+mas\s+lejos|averiguar|buscar)\b/u.test(normalized)
        || /\bno\s+vas\s+a\s+tener\s+que\s+(?:averiguar|descubrir|ver)\b[^.!?;:]{0,60}\b(?:que\s+sigue|despues|lo\s+que\s+pasa)\b/u.test(normalized);

    return demand && consequence;
}


/**
 * Detecta consecuencias personales implícitas sin exigir vocabulario físico literal.
 */
function hasImpliedPersonalConsequenceCue(text) {
    const normalized = String(text ?? '');

    return (
        /\b(?:te|le|lo|la)\s+(?:dejo|deja|dejan|vamos\s+a\s+dejar)\s+sin\s+ganas\s+de\s+volver\b/u.test(normalized)
        || /\b(?:te|le)\s+(?:hago|hace|vamos\s+a\s+hacer)\s+cobrar(?:\s+cada\s+palabra|\s+en\s+persona)?\b/u.test(normalized)
        || /\b(?:cuando|si|apenas)\b[^.!?;:]{0,100}\b(?:te|vos|usted)\b[^.!?;:]{0,100}\b(?:cobrar|sin\s+ganas\s+de\s+volver)\b/u.test(normalized)
        || /\bte\s+vamos\s+a\s+(?:hacer\s+acordar|sacar\s+las\s+ganas)\b/u.test(normalized)
    );
}


/**
 * Reconoce marcos de represalia futura dirigidos contra una persona.
 */
function hasRetaliationCue(text) {
    const normalized = String(text ?? '');

    return (
        (
            /\b(?:cobrar|pagar|deuda|devolucion|respuesta\s+personal|gente\s+equivocada|no\s+termino\s+con\s+este\s+asunto|parte\s+complicada|cuando\s+corresponda|no\s+recibio\s+.*cuenta|(?:te|le)\s+va\s+a\s+llegar\s+la\s+cuenta|le\s+va\s+a\s+volver|le\s+llega|va\s+a\s+descubrir\s+cuanto\s+cuesta|no\s+celebrar\s+antes\s+de\s+tiempo|dia\s+(?:bastante\s+)?inolvidable)\b/u.test(normalized)
            && /\b(?:te|va|le|lo|la|el|ella|quien|que|autor|autora|periodista|columnista|usuario|usuaria|tipo|persona|panelista|funcionario|funcionaria|vocero|vocera|moderador|moderadora)\b/u.test(normalized)
        )
        || /\b(?:te|le)\s+(?:vamos\s+a|voy\s+a|van\s+a|va\s+a)?\s*hacer\s+cobrar\b/u.test(normalized)
    );
}

/**
 * Reconoce ultimátums y anuncios de escalada que justifican revisión humana.
 */
function hasUltimatumCue(text) {
    const normalized = String(text ?? '');

    return (
        /\b(?:ultimo\s+aviso|no\s+va\s+a\s+haber\s+(?:otro|un\s+tercer)\s+aviso|la\s+proxima\b[^.!?;:]{0,80}\b(?:no\s+va\s+a\s+ser|puede\s+tener|no\s+te\s+va\s+a\s+llegar)|despues\s+de\s+esto\s+no\s+garantizo|cierre\s+de\s+la\s+parte\s+tranquila|no\s+sigas\s+comprobando\s+cuanto\s+aguanto|se\s+vuelva\s+personal|ya\s+no\s+va\s+a\s+ser\s+una\s+discusion\s+normal|hasta\s+hoy\s+fueron\s+palabras|gastando\s+el\s+ultimo\s+margen|no\s+va\s+a\s+haber\s+un\s+tercer\s+aviso)\b/u.test(normalized)
    );
}


/**
 * Detecta castigos o escarmientos vagos cuya materialidad permanece ambigua.
 */
function hasVaguePunishmentCue(text) {
    const normalized = String(text ?? '');

    return (
        /\b(?:leccion|correctivo|escarmiento|susto|devolucion|respuesta|experiencia|limite|problema)\b[^.!?;:]{0,120}\b(?:cuerpo|dolor|dolorosa|por\s+las\s+malas|no\s+pueda\s+borrar|baje\s+el\s+tono|duela|no\s+se\s+arregla\s+hablando|menos\s+paciente|saque\s+las\s+ganas|cuando\s+menos\s+lo\s+espere|acomode\s+las\s+ideas)\b/u.test(normalized)
        || /\b(?:aprender|ense[nñ]ar)\b[^.!?;:]{0,100}\b(?:por\s+las\s+malas|con\s+el\s+cuerpo|de\s+la\s+forma\s+mas\s+incomoda)\b/u.test(normalized)
        || /\bva\s+camino\s+a\s+aprender\b[^.!?;:]{0,80}\b(?:forma\s+mas\s+incomoda|por\s+las\s+malas)\b/u.test(normalized)
        || /\b(?:necesita|le\s+hace\s+falta)\s+un\s+susto\b[^.!?;:]{0,80}\b(?:saque\s+las\s+ganas|aprenda|entienda)?\b/u.test(normalized)
        || /\ble\s+va\s+a\s+llegar\s+un\s+escarmiento\b/u.test(normalized)
        || /\ble\s+falta\s+encontrarse\s+con\s+alguien\s+menos\s+paciente\b/u.test(normalized)
        || /\bojala\s+reciba\s+un\s+correctivo\b/u.test(normalized)
        || /\balgun\s+dia\s+alguien\s+le\s+va\s+a\s+ense[nñ]ar\s+por\s+las\s+malas\b/u.test(normalized)
    );
}

/**
 * Identifica consecuencias legales, administrativas o de moderación que no son amenazas físicas.
 */
function hasInstitutionalOrLegalContext(text) {
    const normalized = String(text ?? '');

    return (
        /\b(?:justicia|juez|fiscal|denuncia|denunciar|investigacion|investigar|auditoria|congreso|renunciar|renuncia|elecciones|votar|sancion\s+administrativa|procedimiento|vias?\s+institucional|canales?\s+formal|debido\s+proceso|autoridad\s+competente|rectificacion|corregir\s+la\s+nota|explicaciones?\s+publicas?)\b/u.test(normalized)
        || /\b(?:cuenta|usuario|perfil)\b[^.!?;:]{0,80}\b(?:se\s+)?(?:suspende|suspendera|bloquea|bloqueara|inhabilita|inhabilitara)\b/u.test(normalized)
        || /\b(?:te\s+la|se\s+lo|lo|la)\s+(?:mando|envio|enviare|mandare)\s+por\s+(?:correo|email|mail|mensaje\s+privado)\b/u.test(normalized)
        || /\b(?:reglamento|normas?|moderacion|sancion|baneo|suspension)\b[^.!?;:]{0,100}\b(?:cuenta|usuario|perfil|comentario|publicacion)\b/u.test(normalized)
    );
}

/**
 * Reconoce una continuación administrativa inocua capaz de desambiguar un ultimátum aparente.
 */
function hasBenignAdministrativeContinuation(text) {
    const normalized = String(text ?? '');

    return (
        /\b(?:te\s+la|se\s+lo|lo|la)\s+(?:mando|envio|enviare|mandare)\s+por\s+(?:correo|email|mail|mensaje\s+privado)\b/u.test(normalized)
        || /\b(?:cuenta|usuario|perfil)\b[^.!?;:]{0,80}\b(?:se\s+)?(?:suspende|suspendera|bloquea|bloqueara|inhabilita|inhabilitara)\b/u.test(normalized)
        || /\b(?:sistema|plataforma|moderacion)\b[^.!?;:]{0,100}\b(?:suspende|bloquea|inhabilita|elimina|oculta)\b/u.test(normalized)
    );
}


/**
 * Detecta discurso que condena, reporta o recomienda moderar amenazas y violencia.
 */
function hasCounterSpeechContext(text) {
    const normalized = String(text ?? '');
    const violence = String.raw`(?:amenaz[\p{L}]*|golpe[\p{L}]*|peg[\p{L}]*|lastim[\p{L}]*|her[\p{L}]*|agred[\p{L}]*|mat[\p{L}]*|paliza|trompadas?|punetazos?|pinas?)`;
    const moderation = String.raw`(?:denunciar|reportar|moderar|eliminar|bloquear|rechazar|denunciarse|reportarse|moderarse|eliminarse|rechazarse)`;

    return (
        new RegExp(`\\bsi\\s+alguien\\b[^.!?;:]{0,150}\\b${violence}\\b[^.!?;:]{0,150}\\b(?:corresponde|hay\\s+que|deberia|debe)\\b[^.!?;:]{0,100}\\b${moderation}\\b`, 'u').test(normalized)
        || new RegExp(`\\b(?:amenaza|violencia|agresion)\\b[^.!?;:]{0,100}\\b(?:deberia|debe|corresponde)\\b[^.!?;:]{0,100}\\b${moderation}\\b`, 'u').test(normalized)
        || new RegExp(`\\b(?:no\\s+hay\\s+que|no\\s+se\\s+debe|no\\s+corresponde)\\b[^.!?;:]{0,120}\\b${violence}\\b`, 'u').test(normalized)
        || new RegExp(`\\b(?:eso|esto)\\s+no\\s+justifica\\b[^.!?;:]{0,120}\\b${violence}\\b`, 'u').test(normalized)
        || /\bquien\s+amenaza\s+con\s+golpes\b[^.!?;:]{0,120}\b(?:deberia|debe)\s+recibir\s+una\s+sancion\s+de\s+moderacion\b/u.test(normalized)
    );
}

/**
 * Reconoce uso-mención: frases citadas o analizadas como ejemplos, evidencia o material de moderación.
 */
function hasExplicitUseMentionContext(text) {
    const normalized = String(text ?? '');

    return (
        /\b(?:frase|oracion|comentario|mensaje|texto|captura|ticket(?:\s+de\s+abuso)?)\s+(?:denunciad[oa]|reportad[oa]|citad[oa]|copiad[oa]|reproducid[oa])\b/u.test(normalized)
        || /\b(?:la\s+)?frase\s+(?:denunciada|citada|reportada)\s+fue\b/u.test(normalized)
        || /\b(?:texto|mensaje|comentario)\s+reportado\s+fue\b/u.test(normalized)
        || /\b(?:pertenece|pertenecia)\s+al\s+(?:mensaje|comentario)\s+reportado\b/u.test(normalized)
        || /\b(?:se\s+conserva|se\s+incluye|se\s+reproduce|se\s+guarda)\b[^.!?;:]{0,120}\b(?:evidencia|ejemplo|prueba|moderacion|revision)\b/u.test(normalized)
        || /\b(?:ticket\s+de\s+abuso|evaluador|moderador)\b[^.!?;:]{0,140}\b(?:conserva|incluyo|incluye|reproduce|registro|cita)\b/u.test(normalized)
        || /\b(?:conjunto|bateria)\s+de\s+(?:ejemplos|pruebas|regresion)\b/u.test(normalized)
        || /\b(?:material\s+de\s+moderacion|documenta\s+un\s+caso\s+ajeno|estoy\s+reproduciendo\s+lo\s+que\s+escribio\s+otra\s+persona|la\s+estoy\s+citando,?\s+no\s+dirigiendo)\b/u.test(normalized)
        || /\b(?:estoy|estamos)\s+(?:describiendo|citando|reproduciendo|analizando)\b[^.!?;:]{0,140}\b(?:otra\s+persona|contenido\s+ajeno|mensaje|comentario|frase)\b/u.test(normalized)
        || /\b(?:es|era)\s+un\s+ejemplo\s+de\s+(?:moderacion|amenaza|violencia|clasificacion)\b/u.test(normalized)
        || /\b(?:contexto|uso)\s+(?:es|era)\s+de\s+(?:reporte|revision|moderacion|documentacion)\b/u.test(normalized)
        || /\b(?:reporte|reportee|reporte|denuncie)\s+el\s+(?:mensaje|comentario|texto)\b/u.test(normalized)
        || /\b(?:comentario|mensaje)\s+amenazante\s+deberia\s+(?:eliminarse|moderarse|revisarse)\b/u.test(normalized)
        || /\bla\s+palabra\s+\p{L}+\b[^.!?;:]{0,100}\b(?:explicacion\s+linguistica|verbos?\s+de\s+violencia|analisis\s+linguistico)\b/u.test(normalized)
        || (/[¿?]/u.test(normalized) && /\b(?:regla|detector|sistema|categoria|clasificacion|clasificar|nivel\s+de\s+riesgo|moderacion|revision)\b/u.test(normalized))
    );
}

/**
 * Identifica verbos violentos aplicados a procesos, servicios, tareas o infraestructura técnica.
 */
function hasTechnicalProcessContext(text) {
    const normalized = String(text ?? '');

    const technicalNoun =
        /\b(?:script|sesion|session|tarea|task|worker|job|proceso|servicio|servidor|daemon|hilo|thread|instancia|pipeline|build|deploy|contenedor|docker|cache|archivo|disco|base\s+de\s+datos|endpoint|host|timeout|bug|error|configuracion|rendimiento|latencia|memoria|stock|demanda|margen|ventas|proyecto|sistema|aplicacion|app)\b/u.test(normalized);

    const technicalAction =
        /\b(?:matar|tumbar|cortar|terminar|eliminar|reventar|romper|destruir|descartar|demoler|reiniciar|resetear|formatear|borrar|desarmar|patear|golpear|pegar|estrangular|asesinar)\b/u.test(normalized);

    return technicalNoun && technicalAction;
}

/**
 * Reconoce usos de vocabulario violento dentro de videojuegos, deportes o competencia.
 */
function hasGameOrSportContext(text) {
    const normalized = String(text ?? '');

    return (
        /\b(?:juego|videojuego|partida|boss|jefe\s+final|combo|nivel|ronda|equipo|partido|goleada|marcador|cancha|deportiv[oa]|torneo|liga|rival|escudo)\b/u.test(normalized)
        && /\b(?:mat[\p{L}]*|golpe[\p{L}]*|peg[\p{L}]*|paliza|revent[\p{L}]*|revient[\p{L}]*|romp[\p{L}]*|destroz[\p{L}]*|elimin[\p{L}]*|derrot[\p{L}]*|gan[\p{L}]*|perd[\p{L}]*|combo|goleada)\b/u.test(normalized)
    );
}

/**
 * Decide si una cláusula violenta pertenece claramente a un dominio técnico, lúdico o deportivo seguro.
 */
function isDomainSafeViolenceClause(text) {
    const normalized = String(text ?? '');

    if (
        hasTechnicalProcessContext(normalized)
        && !hasHumanRole(normalized)
    ) {
        return true;
    }

    if (hasGameOrSportContext(normalized)) {
        /*
         * Un contexto de juego/deporte deja de ser protector cuando el autor
         * formula una amenaza interpersonal explícita fuera del dominio lúdico.
         */
        if (
            hasSecondPersonTarget(normalized)
            && (
                /\b(?:voy|vamos)\s+a\b/u.test(normalized)
                || /\b(?:cuando|apenas|la\s+proxima\s+vez)\b[^.!?;:]{0,100}\b(?:te|vos|usted)\b/u.test(normalized)
                || hasOfflineIntimidationCue(normalized)
            )
        ) {
            return false;
        }

        return true;
    }

    return false;
}

/**
 * Agrupa usos figurados y objetivos no humanos que deben protegerse frente a falsos positivos.
 */
function hasFigurativeOrObjectContext(text) {
    return (
        isDomainSafeViolenceClause(text)
        || hasNonHumanTarget(text)
        || /\b(?:matar\s+el\s+tiempo|matar\s+el\s+proyecto|matar\s+el\s+acuerdo|golpeando\s+las\s+ventas|golpeando\s+la\s+estabilidad|rompiendo\s+el\s+flujo|destruyendo\s+la\s+comparaci[oó]n|destrozando\s+los\s+tiempos|hundiendo\s+el\s+consumo)\b/u.test(text)
    );
}

/**
 * Determina si el autor niega, rechaza o condena realmente la conducta amenazante.
 */
function hasNegatedOrRejectedThreat(text) {
    const normalized = String(text ?? '');
    const violence = String.raw`(?:mat[\p{L}]*|golpe[\p{L}]*|peg[\p{L}]*|lastim[\p{L}]*|her[\p{L}]*|agred[\p{L}]*|hacer\s+dano|dar(?:le)?\s+una\s+paliza|recibir\s+una\s+paliza|resolver\s+(?:esto|lo)\s+a\s+golpes|violencia|agresion|amenaza)`;

    if (
        /\bno\s+(?:pienso|voy)(?:\s+a)?\s+parar\s+hasta\b/u.test(normalized)
        || /\bno\s+quisiera\s+estar\s+en\s+el\s+lugar\s+de\b/u.test(normalized)
    ) {
        return false;
    }

    return (
        /\b(?:no|nunca|jamas)\s+(?:quiero|quisiera|queremos|apoyo|apoyaria|apoyamos|justifico|justificaria|defiendo|defenderia|propongo|propondria|pido|pediria|deseo|desearia)\b/u.test(normalized)
        || new RegExp(`\\b(?:no|nunca|jamas)\\s+(?:voy|vamos)\\s+a\\b[^.!?;:]{0,100}\\b${violence}\\b`, 'u').test(normalized)
        || /\b(?:no|nunca|jamas)\s+(?:hay|habria)\s+que\b/u.test(normalized)
        || /\bnadie\s+deberia\b/u.test(normalized)
        || new RegExp(`\\bno\\s+deberia(?:n)?\\b[^.!?;:]{0,80}\\b${violence}\\b`, 'u').test(normalized)
        || /\bno\s+(?:estoy|estamos)\s+(?:diciendo|pidiendo|proponiendo|afirmando|sugiriendo|ordenando|amenazando|alentando)\b/u.test(normalized)
        || new RegExp(`\\bno\\s+(?:corresponde|hace\\s+falta|se\\s+justifica|justifica)\\b[^.!?;:]{0,120}\\b${violence}\\b`, 'u').test(normalized)
        || /\bno\s+(?:es|constituye|representa)\s+una\s+amenaza(?:\s+propia)?\b/u.test(normalized)
        || new RegExp(`\\bno\\s+pienso\\b[^.!?;:]{0,100}\\b${violence}\\b`, 'u').test(normalized)
        || /\bno\s+quiero\s+ver(?:lo|la|los|las)?\s+herid[oa]s?\b/u.test(normalized)
        || new RegExp(`\\b(?:rechazo|condeno|repudio)\\b[^.!?;:]{0,180}\\b${violence}\\b`, 'u').test(normalized)
        || /\bnadie\s+merece\b[^.!?;:]{0,100}\b(?:paliza|golpes?|trompadas?|punetazos?|pinas?|violencia|agresion)\b/u.test(normalized)
        || /\bno\s+hay\s+motivo\s+para\s+(?:buscar(?:lo|la|los|las)?\s+en\s+persona|ir\s+a\s+buscar(?:lo|la|los|las)?)\b[^.!?;:]{0,100}\b(?:ni\s+)?intimidar(?:lo|la|los|las)?\b/u.test(normalized)
        || new RegExp(`\\b(?:eso|esto)\\s+no\\s+justifica\\b[^.!?;:]{0,120}\\b${violence}\\b`, 'u').test(normalized)
        || new RegExp(`\\bno\\s+que\\s+alguien\\s+vaya\\s+a\\s+${violence}\\b`, 'u').test(normalized)
        || /\b(?:sin|evitando)\s+(?:violencia|amenazas?|agresiones?)\b/u.test(normalized)
    );
}

/**
 * Detecta metalenguaje, reporte, documentación o análisis explícito de amenazas.
 */
function hasMetaOrReportingContext(text) {
    const normalized = String(text ?? '');

    return (
        /\b(?:frase|oracion|comentario|mensaje|texto|cadena|manual|moderacion|capacitacion|clase|prueba\s+automatizada|bateria\s+de\s+regresion|conjunto\s+de\s+regresion|conjunto\s+de\s+ejemplos|guia|documentacion|explicacion\s+tecnica|explicacion\s+linguistica|caso\s+de\s+prueba|entrada\s+de\s+una\s+prueba|probar\s+el\s+detector|detector|auditoria|captura|ticket\s+de\s+abuso|informe\s+interno|evaluador|transcribe|transcribir|figuraba|consulta|pregunta|reporte|reportes|reportado|reportada|regla|categoria|clasificacion|revision(?:\s+humana)?|material\s+de\s+moderacion|expresion|ejemplo|muestra\s+de\s+texto|linguistic[oa]|analizamos|analizar|documentar|documenta|reproduce|reproducir|incluye|incluir|contiene|conserva|conservar|citar|citado|citada|cita|registro|reporto|denuncio|recibi|evidencia)\b/u.test(normalized)
        || (/[¿?]/u.test(normalized) && /\b(?:amenaza|violencia|regla|detector|categoria|clasificar|clasificacion|revision|moderacion|cadena|frase|oracion|ejemplo)\b/u.test(normalized))
    );
}

/**
 * Reconoce marcos de ficción o narración histórica.
 */
function hasFictionHistoricalContext(text) {
    return /\b(?:pel[ií]cula|serie|episodio|novela|cuento|obra|trama|escena|relato|ficci[oó]n|personaje|protagonista|hist[oó]rico|hist[oó]rica|durante\s+la\s+guerra|en\s+la\s+historia)\b/u.test(text);
}

/**
 * Neutraliza únicamente citas cuyo contexto demuestra reporte, análisis o rechazo, preservando el resto.
 */
function maskProtectedQuotedText(text) {
    const chars = Array.from(text);
    const quotePattern = /(["«“])([^"»”]{1,500})(["»”])/gu;

    for (const match of text.matchAll(quotePattern)) {
        const index = match.index ?? 0;
        const end = index + match[0].length;
        const contextStart = Math.max(0, index - 220);
        const contextEnd = Math.min(text.length, end + 220);
        const context = text.slice(contextStart, contextEnd);

        /*
         * Preguntas de moderación/clasificación también son uso-mención. V3
         * protegía bien "dijo/citó", pero podía dejar expuesto el contenido de:
         *
         *   ¿La regla detecta algo como "..."?
         *
         * V4.4 protege la cita por su función, no por la presencia de comillas sola.
         */
        const moderationQuestion =
            /[¿?]/u.test(context)
            && /\b(?:regla|detector|moderacion|categoria|clasificacion|clasificar|revision\s+humana|cadena|frase|oracion|ejemplo|amenaza|violencia)\b/u.test(context);

        const protectedContext =
            hasMetaOrReportingContext(context)
            || hasFictionHistoricalContext(context)
            || moderationQuestion
            || /\b(?:dijo|decia|declaro|escribio|publico|transcribe|transcribio|conserva|incluyo)\b/u.test(context)
            || /\b(?:por\s+qu[eé]|c[oó]mo)\b[^?]{0,100}\b(?:decir|escribir|amenaza|clasificar|detectar)\b/u.test(context);

        if (!protectedContext) {
            continue;
        }

        for (let i = index; i < end; i += 1) {
            if (chars[i] !== '\n') {
                chars[i] = ' ';
            }
        }
    }

    return chars.join('');
}

/**
 * Determina si la cláusula completa describe o analiza una amenaza ajena en vez de formularla.
 */
function isEntirelyReportedOrMetalinguisticClause(text) {
    if (
        hasSecondPersonTarget(text)
        && /\b(?:voy|pienso|quiero)\s+a\b/u.test(text)
        && !hasMetaOrReportingContext(text)
    ) {
        return false;
    }

    return (
        /\b(?:el|un|este|ese)\s+(?:mensaje|comentario|texto|reporte|ticket)\s+(?:decia|dice|decian|incluye|contiene|reproduce|conserva)\b/u.test(text)
        || /\b(?:la|una|esta|esa)\s+(?:frase|captura)\s+(?:decia|dice|aparece|fue\s+citada|incluye|contiene|reproduce|muestra)\b/u.test(text)
        || /\brecib(?:i|io|ieron)\b[^.!?;:]{0,80}\b(?:mensaje|comentario|texto|reporte|captura)\b/u.test(text)
        || /\b(?:captura|reporte|mensaje|comentario|texto|ticket)\s+(?:contiene|incluye|reproduce|muestra|conserva)\b/u.test(text)
        || /\b(?:ticket\s+de\s+abuso|informe\s+interno|evaluador)\b[^.!?;:]{0,140}\b(?:conserva|incluyo|incluye|reproduce|registro)\b/u.test(text)
        || /\bla\s+palabra\s+\p{L}+\b[^.!?;:]{0,100}\b(?:explicacion\s+linguistica|verbos?\s+de\s+violencia)\b/u.test(text)
        || /\b(?:en\s+una\s+clase|en\s+la\s+capacitacion|en\s+esta\s+prueba|en\s+el\s+manual|la\s+guia|el\s+sistema)\b/u.test(text) && hasMetaOrReportingContext(text)
        || /\b(?:por\s+que|como|que\s+diferencia\s+hay)\b[^?]{0,160}\b(?:decir|escribir|amenazar|moderarse|considerarse|citar|reporte|incluye|reproducir)\b/u.test(text)
    );
}

/**
 * Protege ficción/historia sólo cuando la violencia pertenece al relato y no hay amenaza residual propia.
 */
function isFictionHistoricalSafeClause(text) {
    if (!hasFictionHistoricalContext(text)) {
        return false;
    }

    if (
        hasSecondPersonTarget(text)
        && /\b(?:voy|pienso|quiero)\s+a\b/u.test(text)
    ) {
        return false;
    }

    return (
        /\b(?:personaje|protagonista|soldado|ej[eé]rcito|rey|villano|actor|escena|episodio|trama|relato)\b/u.test(text)
        || /\b(?:dijo|dice|dec[ií]a|mata|mat[oó]|golpea|golpe[oó])\b/u.test(text)
    );
}

/**
 * Divide el comentario en cláusulas locales después de normalizar y proteger citas reportadas.
 */
function splitThreatClauses(text) {
    const normalized = normalizeTextForAnalysis(text);
    const masked = maskProtectedQuotedText(normalized);

    const primary = masked
        .replace(/\s+\/{2,}\s+/gu, '. ')
        .split(/[.!?;:\n]+/u)
        .map((part) => part.trim())
        .filter(Boolean);

    const clauses = [];

    for (const part of primary) {
        const secondary = part
            .split(/\b(?:pero|sin\s+embargo|dicho\s+eso|aun\s+asi|a[uú]n\s+asi|igual\s+que|igual)\b/u)
            .map((value) => value.trim())
            .filter(Boolean);

        clauses.push(...secondary);
    }

    return clauses.length > 0
        ? clauses
        : [masked.trim()].filter(Boolean);
}


/* ============================================================================
 * AMENAZAS INDIRECTAS / MEDIUM
 * ============================================================================ */

/**
 * Clasifica familias composicionales de intimidación indirecta que corresponden a riesgo MEDIUM.
 */
function analyzeIndirectThreat(text) {
    const normalized = String(text ?? '');

    /*
     * V4.4 incorpora familias composicionales de amenaza MEDIUM.
     * Las señales se construyen por función semántica, no por oraciones exactas.
     */
    if (hasCoerciveThreatCue(normalized)) {
        return { found: true, kind: 'coercive-intimidation', ambiguous: false };
    }

    if (hasImpliedGroupIntimidationCue(normalized)) {
        return { found: true, kind: 'implied-group-intimidation', ambiguous: false };
    }

    if (
        hasRoutineKnowledgeCue(normalized)
        && hasIntimidatingTailCue(normalized)
    ) {
        return { found: true, kind: 'routine-surveillance-intimidation', ambiguous: false };
    }

    if (hasVeiledThreatCue(normalized)) {
        return { found: true, kind: 'veiled-intimidation', ambiguous: false };
    }

    if (
        hasOfflineIntimidationCue(normalized)
        && (
            hasIntimidatingTailCue(normalized)
            || /\b(?:provoc[\p{L}]*|aviso|problema|respuesta|cruce|cordial|confie|pantalla|protege)\b/u.test(normalized)
        )
    ) {
        return { found: true, kind: 'veiled-offline-intimidation', ambiguous: false };
    }

    if (hasRetaliationCue(normalized)) {
        return { found: true, kind: 'retaliation', ambiguous: false };
    }

    if (hasImpliedPersonalConsequenceCue(normalized)) {
        return { found: true, kind: 'implied-personal-consequence', ambiguous: false };
    }

    if (hasUltimatumCue(normalized)) {
        return { found: true, kind: 'ultimatum', ambiguous: false };
    }

    if (hasVaguePunishmentCue(normalized)) {
        return { found: true, kind: 'vague-punishment', ambiguous: false };
    }

    const groups = [
        ['indirect-warning', [
            /\b(?:ya\s+vas?|ya\s+va)\s+a?\s*ver\b/u,
            /\b(?:te|se)\s+va\s+a\s+arrepentir\b/u,
            /\bva\s+a\s+lamentar(?:\s+haber\b[^.!?;:]*)?\b/u,
            /\b(?:te|le)\s+va\s+a\s+salir\s+caro\b/u,
            /\bse\s+busc[oó]\s+problemas\b/u,
            /\bno\s+sab(?:e|es)\s+con\s+qui[eé]n\s+(?:se|te)\s+(?:meti[oó]|esta\s+metiendo)\b/u,
            /\b(?:te|le)\s+conviene\s+cuidar(?:te|se)\b/u,
            /\b(?:que\s+)?(?:te|se)\s+cuide\b/u,
            /\btenga\s+cuidado\b/u,
            /\bdeberia\s+(?:empezar\s+a\s+)?tener\s+cuidado\b/u,
            /\bdeberia\s+(?:empezar\s+a\s+)?cuidarse\b/u,
            /\b(?:le|te)\s+conviene\s+bajar\s+un\s+cambio\b/u,
            /\bdeberia\s+pensar\s+bien\s+lo\s+que\s+hace\b/u,
            /\b(?:que\s+)?[^.!?;:]{0,80}\bmida(?:\s+bien)?\s+(?:sus|tus)\s+proximos\s+pasos\b/u,
            /\bmirar\s+por\s+donde\s+anda\b/u,
            /\bmirar\s+para\s+los\s+costados\b/u,
            /\btendria\s+que\s+andar\s+con\s+(?:mucho|muchisimo)\s+cuidado\b/u,
            /\ble\s+convendria\s+estar\s+atent[oa]\b/u,
            /\bempiece\s+a\s+cuidarse\b/u,
            /\bcuidate\b/u,
            /\bcuidese\b/u,
            /\bno\s+(?:te|se)\s+confie\b/u
        ]],
        ['retaliation', [
            /\bva\s+a\s+pagar(?:\s+caro)?(?:\s+por\s+esto)?\b/u,
            /\bva\s+a\s+terminar\s+pagando(?:\s+las\s+consecuencias)?\b/u,
            /\bterminara\s+pagando\b/u,
            /\b(?:te|le)\s+va\s+a\s+llegar\s+la\s+cuenta\b/u,
            /\bno\s+se\s+va\s+a\s+olvidar\s+de\s+esto\b/u,
            /\besto\s+recien\s+empieza\s+para\b/u,
            /\btodavia\s+va\s+a\s+sentir\s+las\s+consecuencias\b/u,
            /\besto\s+le\s+va\s+a\s+traer\s+consecuencias\b/u
        ]],
        ['veiled-encounter', [
            /\bno\s+cruzar(?:te|se)\s+conmigo\b/u,
            /\b(?:te|le)\s+conviene\s+no\s+cruzar(?:te|se)\s+conmigo\b/u,
            /\b(?:te|le)\s+conviene\s+evitarme\b/u,
            /\bcuando\s+nos\s+crucemos\b/u,
            /\bya\s+nos\s+vamos\s+a\s+encontrar\b/u,
            /\bno\s+vuelva\s+por\s+ac[aá]\b/u,
            /\bno\s+aparezca\b[^.!?;:]{0,80}\bdonde\s+estoy\s+yo\b/u,
            /\bpensar\s+dos\s+veces\s+antes\s+de\s+volver\b/u,
            /\btodavia\s+no\s+entendio\s+(?:el\s+)?aviso\b/u
        ]],
        ['coercive-intimidation', [
            /\bmas\s+vale\s+que\b[^.!?;:]{0,120}\b(?:antes\s+de\s+que|si\s+queres)\b[^.!?;:]{0,100}\b(?:pase\s+algo|problemas|se\s+complique|tranquilo)\b/u,
            /\bno\s+sigas\b[^.!?;:]{0,100}\bsi\s+no\s+queres\s+consecuencias\b/u,
            /\bno\s+vuelvas\b[^.!?;:]{0,100}\bsi\s+queres\s+evitar\s+un\s+mal\s+momento\b/u
        ]],
        ['ambiguous-escalation', [
            /\b(?:esta|sigue)\s+jugando\s+con\s+fuego\b/u,
            /\bestirando\s+la\s+cuerda\b/u,
            /\btentar\s+a\s+la\s+suerte\b/u,
            /\b(?:las\s+cosas|esto)\s+(?:pueden|puede|se\s+van\s+a)\s+poner(?:se)?\s+(?:muy\s+)?fe[ao]s?\b/u,
            /\bno\s+me\s+obliguen\s+a\s+llevar\s+esto\s+a\s+otro\s+nivel\b/u,
            /\btodavia\s+no\s+sabe\s+lo\s+que\s+le\s+espera\b/u,
            /\besto\s+no\s+va\s+a\s+quedar\s+asi\b/u,
            /\bva\s+a\s+tener\s+problemas\b/u,
            /\btodavia\s+esta\s+por\s+venir\b/u
        ]],
        ['vague-harm-wish', [
            /\bojala\b[^.!?;:]{0,140}\b(?:susto|algo\s+malo|pase\s+algo|ocurra\s+algo|experiencia\s+que\s+no\s+olvide|termine\s+lamentando|sorpresa\s+desagradable)\b/u,
            /\ble\s+vendria\s+bien\b[^.!?;:]{0,100}\b(?:susto|escarmiento|aprender\s+por\s+las\s+malas|pasar\s+un\s+mal\s+momento)\b/u,
            /\ble\s+hace\s+falta\s+(?:un\s+)?susto\b/u,
            /\bse\s+lleve\s+un\s+buen\s+susto\b/u,
            /\ble\s+pase\s+algo\s+que\s+le\s+ense[nñ]e\b/u,
            /\breciba\s+lo\s+que\s+se\s+merece\b/u
        ]]
    ];

    for (const [kind, patterns] of groups) {
        if (patterns.some((pattern) => pattern.test(normalized))) {
            return { found: true, kind, ambiguous: false };
        }
    }

    const ambiguous =
        /\b(?:la\s+proxima\s+conversacion\s+puede\s+ser\s+muy\s+distinta|la\s+paciencia\s+se\s+termina|pensalo\s+bien\s+antes\s+de\s+volver\s+a\s+nombrarme)\b/u.test(normalized);

    return { found: false, kind: null, ambiguous };
}

/**
 * Reconoce una cláusula vecina capaz de completar una advertencia indirecta incompleta.
 */
function hasIntimidatingContinuation(text) {
    const normalized = String(text ?? '');

    return (
        hasIntimidatingTailCue(normalized)
        || hasUltimatumCue(normalized)
        || hasOfflineIntimidationCue(normalized)
        || /\b(?:nadie|no)\s+le\s+avis[oó]\b/u.test(normalized)
        || /\bno\s+repetiria\s+el\s+mismo\s+error\b/u.test(normalized)
        || /\bno\s+es\s+buena\s+idea\s+seguir\s+provocando\b/u.test(normalized)
        || /\bno\s+sabe\s+hasta\s+donde\s+puede\s+llegar\s+esto\b/u.test(normalized)
        || /\bdespues\s+va\s+a\s+ser\s+tarde\s+para\s+arrepentirse\b/u.test(normalized)
        || /\bno\s+conviene\s+tentar\s+(?:a\s+)?la\s+suerte\b/u.test(normalized)
        || /\bno\s+sabe\s+con\s+quien\s+se\s+metio\b/u.test(normalized)
        || /\bhay\s+limites\s+que\s+no\s+conviene\s+cruzar\b/u.test(normalized)
    );
}


/**
 * Señal de revisión humana independiente de HIGH/MEDIUM/NONE.
 *
 * Esta capa NO convierte el comentario en amenaza ni modifica threat_score.
 * Su único propósito es derivar a moderación algunos NONE que conservan una
 * estructura suficientemente intimidatoria/prospectiva como para no aprobarlos
 * automáticamente. Es deliberadamente más amplia que MEDIUM, pero sigue
 * respetando negación, reporte, ficción y objetos.
 */
function hasReviewOnlyThreatCue(text) {
    const normalized = String(text ?? '');

    if (
        !normalized
        || hasNegatedOrRejectedThreat(normalized)
        || isEntirelyReportedOrMetalinguisticClause(normalized)
        || hasExplicitUseMentionContext(normalized)
        || hasCounterSpeechContext(normalized)
        || isFictionHistoricalSafeClause(normalized)
        || hasInstitutionalOrLegalContext(normalized)
        || isDomainSafeViolenceClause(normalized)
    ) {
        return false;
    }

    const humanContext =
        hasSecondPersonTarget(normalized)
        || hasHumanRole(normalized)
        || hasHumanTarget(normalized);

    const offlineOrPersonalEscalation =
        /\b(?:cara\s+a\s+cara|en\s+persona|fuera\s+(?:de|del)\s+(?:internet|sitio|hilo|comentario|pagina)|lejos\s+del\s+teclado|sin\s+(?:una\s+)?pantalla|sale\s+de\s+internet|sale\s+del\s+sitio|deja\s+de\s+ser\s+(?:solo\s+)?online|deja\s+la\s+pantalla|(?:pasa|pase)\s+a\s+otro\s+(?:plano|terreno)|cambia\s+de\s+terreno|cambia\s+el\s+modo\s+de\s+responder|lo\s+siguiente\s+(?:va\s+a\s+ser|sera|puede\s+ser)\s+personal|respuesta\s+(?:sera|puede\s+ser)\s+personal|proximo\s+(?:cruce|contacto|intercambio)|encuentro\s+pendiente|ir\s+a\s+buscarte|voy\s+a\s+buscarte|buscarte\s+para|ir\s+a\s+hablarte|acercarme\s+para\s+hablarlo\s+personalmente|hablarlo\s+personalmente|encontrarte\s+en\s+persona|encontrarnos\s+afuera|continuar\s+donde\s+estes)\b/u.test(normalized)
        || /\b(?:espera|esperame|aguarda|aguardame)\s+que\s+te\s+(?:encuentre|cruce|vea)(?:\s+y)?(?:\s+despues)?\s+(?:hablamos|lo\s+hablamos|arreglamos)\b/u.test(normalized)
        || /\bcuando\s+te\s+(?:encuentre|cruce|vea)(?:\s+y)?(?:\s+despues)?\s+(?:hablamos|lo\s+hablamos|arreglamos)\b/u.test(normalized);

    const retaliationOrPunishment =
        /\b(?:pagar\s+lo\s+que\s+hizo|devolucion\s+pendiente|consecuencia\s+(?:de\s+haber|por\s+recibir|bastante\s+seria)|recien\s+empieza\s+su\s+parte|quedo\s+anotad[oa]|respuesta\s+que\s+no\s+podra\s+ignorar|le\s+va\s+a\s+volver|cuenta\s+que\s+despues\s+se\s+cobra|respuesta\s+fuera\s+del\s+hilo|parte\s+incomoda|no\s+se\s+olvida\s+tan\s+facil|deuda\s+que\s+alguien\s+va\s+a\s+querer\s+cobrar|responder\s+personalmente|no\s+termina\s+en\s+este\s+comentario|problema\s+que\s+sigue\s+abierto|leccion\s+(?:bastante\s+)?dolorosa|correctivo\s+que\s+no\s+se\s+olvide|alguien\s+menos\s+paciente|susto\s+que|devolucion\s+(?:fea|dura)|aprender\s+de\s+una\s+manera\s+incomoda|escarmiento|no\s+se\s+resuelva\s+hablando|limite\s+que\s+se\s+sienta|por\s+las\s+malas|no\s+todas\s+las\s+respuestas\s+son\s+verbales|problema\s+personal|puede\s+terminar\s+mal|alguien\s+que\s+no\s+se\s+contenga|cuenta\s+que\s+despues\s+puede\s+pagarse|no\s+festejar\s+antes\s+de\s+tiempo|todavia\s+no\s+vio\s+la\s+parte\s+incomoda|va\s+a\s+volverle\s+de\s+una\s+forma\s+que\s+no\s+le\s+guste|va\s+a\s+aprender\s+que\s+pasa\s+cuando\s+cruza\s+ciertos\s+limites|le\s+falta\s+cruzarse\s+con\s+quien\s+no\s+tenga\s+tanta\s+paciencia|le\s+vendria\s+un\s+susto\s+para\s+que\s+deje\s+de\s+provocar)\b/u.test(normalized);

    const ultimatumEscalation =
        /\b(?:ultimo\s+(?:mensaje|aviso)|cierre\s+de\s+los\s+avisos|no\s+(?:va\s+a\s+existir|va\s+a\s+haber|queda)\s+otro\s+aviso|se\s+termino\s+el\s+margen|no\s+queda\s+margen|ultima\s+oportunidad|una\s+(?:repeticion|insistencia|mas)\s+mas|una\s+mas\s+y|despues\s+de\s+esto\s+no\s+esperes|no\s+cuentes\s+con\s+otra\s+advertencia|lo\s+siguiente\s+ya\s+puede\s+ser\s+personal|proxima\s+no\s+necesariamente\s+sera\s+con\s+palabras|no\s+voy\s+a\s+escribir\s+una\s+tercera\s+vez|asunto\s+sale\s+de\s+internet|etapa\s+de\s+hablar\s+por\s+comentarios)\b/u.test(normalized);

    const reviewDemand =
        hasCoerciveDemandCue(normalized)
        || /\b(?:rectifica|rectific[aá]|termina|termin[aá])\b/u.test(normalized);

    const coerciveEscalation =
        reviewDemand
        && /\b(?:escale|quede\s+en\s+palabras|respuesta\s+sea\s+personal|visita|encuentro|vaya\s+a\s+verte|buscarte|cosa\s+siga\s+tranquila|averiguar\s+que\s+viene|mantener\s+distancia|ir\s+en\s+persona|pase\s+a\s+la\s+calle|cada\s+uno\s+sigue\s+por\s+su\s+lado|pedirte\s+explicaciones|cruce|encontrarnos|consecuencias\s+personales|cara\s+a\s+cara|problema\s+quede\s+aca)\b/u.test(normalized);

    return (
        offlineOrPersonalEscalation
        || retaliationOrPunishment && humanContext
        || ultimatumEscalation
        || coerciveEscalation
        || /\bya\s+vi\s+por\s+donde\s+volves\b[^.!?;:]{0,120}\bno\s+asumiria\s+que\s+siempre\s+sera\s+tranquilo\b/u.test(normalized)
    );
}

/**
 * Decide si una cláusula está protegida también para la capa de revisión.
 *
 * `protected_context` del clasificador principal incluye `non_human`, pero para
 * review eso sería demasiado amplio: palabras como "página", "sitio",
 * "pantalla" o "paciencia" aparecen justamente en escaladas offline. Sólo
 * mantenemos la protección no-humana cuando además existe violencia física,
 * que es el caso donde necesitamos resolver el antecedente como objeto.
 */
function isReviewProtectedClause(result) {
    return (
        result.signals.negated
        || result.signals.meta_reported
        || result.signals.explicit_use_mention
        || result.signals.counter_speech
        || result.signals.fiction_historical
        || result.signals.domain_safe_violence
        || result.signals.institutional
        || result.signals.non_human && result.signals.physical_harm
    );
}

/**
 * Busca la primera cláusula NONE que amerita revisión. También prueba pares
 * adyacentes para recuperar construcciones partidas por punto y coma sin unir
 * secciones lejanas del comentario.
 */
function findReviewRecommendation(clauseResults) {
    for (let index = 0; index < clauseResults.length; index += 1) {
        const current = clauseResults[index];

        if (
            current.risk === 'none'
            && !isReviewProtectedClause(current)
            && hasReviewOnlyThreatCue(current.text)
        ) {
            return {
                recommended: true,
                reason: 'review-only-intimidation',
                clause_index: current.clause_index,
                clause_text: current.text
            };
        }

        const next = clauseResults[index + 1];

        if (
            next
            && current.risk === 'none'
            && next.risk === 'none'
            && !isReviewProtectedClause(current)
            && !isReviewProtectedClause(next)
        ) {
            const pairText = `${current.text}. ${next.text}`;

            if (hasReviewOnlyThreatCue(pairText)) {
                return {
                    recommended: true,
                    reason: 'review-only-adjacent-intimidation',
                    clause_index: current.clause_index,
                    clause_text: pairText
                };
            }
        }
    }

    return {
        recommended: false,
        reason: null,
        clause_index: null,
        clause_text: null
    };
}

/**
 * Analiza una cláusula y produce riesgo, score operativo, señales contextuales y evidencia física.
 */
function analyzeThreatClause(clause, clauseIndex) {
    const text = clause.trim();
    const physical = findPhysicalHarmEvidence(text);
    const secondPerson = hasSecondPersonTarget(text);
    const encounterTarget =
        /\b(?:cuando|si|apenas)\s+nos\s+(?:veamos|encontremos|crucemos|topemos)\b/u.test(text)
        || /\b(?:cuando|si|apenas)\b[^.!?;:]{0,80}\b(?:te\s+vea|te\s+cruce|te\s+encuentre|nos\s+veamos)\b/u.test(text);
    const directNonHumanTarget = isPhysicalActionDirectedAtNonHuman(text);
    const humanTarget = (
        hasHumanTarget(text)
        || encounterTarget
    )
        && (!directNonHumanTarget || secondPerson || encounterTarget);
    const intent = hasExplicitIntent(text);
    const incitement = hasIncitement(text);
    const condition = hasConditionalThreatFrame(text);
    const harmWish = hasExplicitHarmWish(text);
    const indirect = analyzeIndirectThreat(text);
    const negated = hasNegatedOrRejectedThreat(text);
    const metaReported = isEntirelyReportedOrMetalinguisticClause(text);
    const explicitUseMention = hasExplicitUseMentionContext(text);
    const counterSpeech = hasCounterSpeechContext(text);
    const fictionHistorical = isFictionHistoricalSafeClause(text);
    const institutional = hasInstitutionalOrLegalContext(text);
    const domainSafeViolence = isDomainSafeViolenceClause(text);
    const physicalEscalationFromOnline =
        /\b(?:del|desde\s+el)\s+teclado\b[^.!?;:]{0,80}\ba\s+(?:los\s+golpes|una\s+golpiza|trompadas|punetazos|pinas|pinazos)\b/u.test(text);

    const nonHuman =
        hasFigurativeOrObjectContext(text)
        && !humanTarget
        && physical.kind !== 'physical-euphemism'
        && !physicalEscalationFromOnline;

    /*
     * Dirección sintáctica de la violencia. V4.4 evita usar simplemente
     * "segunda persona + palabra violenta en cualquier sitio". Se busca una
     * relación causativa/promisoria/incitadora suficientemente cercana.
     */
    const directedPhysical =
        physical.found
        && humanTarget
        && (
            intent
            || incitement
            || harmWish
            || /\b(?:te|lo|la|le|les)\s+(?:voy|vamos|va|van)\s+a\b/u.test(text)
            || /\b(?:te|lo|la|le|les)\s+(?:mato|golpeo|pego|lastimo|hiero|agredo|reviento|dejo|ejo|hago|ago|doy|mando|rompo|parto|lleno|arruino|desarmo|acomodo|saco|ense[nñ]o|explico|corrijo|devuelvo|castigo)\b/u.test(text)
            || /\b(?:matar|golpear|pegar|lastimar|herir|agredir|asesinar|apuñalar|acuchillar|reventar|romper|dar|partir|bajar|mandar|hacer|dejar)[\p{L}]*(?:te|lo|la|los|las|le|les)\b/u.test(text)
            || condition && secondPerson
            || physical.kind === 'bodily-outcome' && secondPerson
            || physical.kind === 'physical-euphemism' && secondPerson
            || secondPerson
                && /\bvas\s+a\s+(?:aprender|entender|recordar)\b[^.!?;:]{0,80}\b(?:a\s+los\s+golpes|a\s+golpes|por\s+las\s+malas\s+y\s+a\s+los\s+golpes)\b/u.test(text)
            || secondPerson && hasFuzzyDirectedCausative(text)
        );

    const groupPhysical =
        physical.found
        && secondPerson
        && /\bte\s+vamos\s+a\b/u.test(text);

    const incitedPhysical =
        physical.found
        && humanTarget
        && (
            incitement
            || /\b(?:alguien|alguno|alguna|uno|una|un\s+par|varios|quien)\b[^.!?;:]{0,100}\b(?:deberia|tendria|que|vaya|agarr[\p{L}]*|golpe[\p{L}]*|peg[\p{L}]*|castig[\p{L}]*|mand[\p{L}]*|ha[gc][\p{L}]*|dej[\p{L}]*|romp[\p{L}]*|ense[nñ][\p{L}]*|pag[\p{L}]*)\b/u.test(text)
        );

    const directPresentThreat =
        directedPhysical
        || groupPhysical
        || incitedPhysical;

    /*
     * Si una cita/reporte contiene además una amenaza propia residual, la
     * amenaza dirigida manda. Si no, el contexto se protege normalmente.
     */
    const effectiveMetaReported =
        counterSpeech
        || (metaReported || explicitUseMention)
            && !directPresentThreat;

    const protectedContext =
        negated
        || effectiveMetaReported
        || fictionHistorical
        || domainSafeViolence
        || nonHuman;

    let risk = 'none';
    let score = 0;
    let reason = 'no-threat-structure';

    if (!protectedContext && directPresentThreat) {
        risk = 'high';
        score = physical.fuzzy
            ? 0.80
            : physical.kind === 'physical-euphemism'
                ? 0.84
                : physical.kind === 'bodily-outcome'
                    ? 0.88
                    : 0.90;
        reason = incitedPhysical
            ? 'incited-physical-harm'
            : groupPhysical
                ? 'group-physical-threat'
                : 'direct-physical-threat';
    } else if (
        !protectedContext
        && physical.found
        && humanTarget
        && /\b(?:se\s+merece|merece|le\s+vendr[ií]a\s+bien|dan\s+ganas\s+de|me\s+dan\s+ganas\s+de)\b/u.test(text)
    ) {
        risk = 'high';
        score = 0.82;
        reason = 'explicit-physical-harm-approval';
    } else if (
        !protectedContext
        && indirect.found
        && (
            humanTarget
            || hasUltimatumCue(text)
            || ['ultimatum', 'coercive-intimidation'].includes(indirect.kind)
        )
        && !institutional
    ) {
        risk = 'medium';
        score = (
            indirect.kind === 'vague-harm-wish'
            || indirect.kind === 'vague-punishment'
        )
            ? 0.54
            : 0.58;
        reason = indirect.kind;
    }

    const neuralWorthy =
        !protectedContext
        && risk === 'none'
        && (
            physical.found
            || indirect.found
            || indirect.ambiguous
            || humanTarget && (
                intent
                || incitement
                || condition
                || harmWish
                || hasGeneralSemanticThreatCue(text)
                || /\b(?:amenaza|amenazar|venganza|represalia|cuidate|cuidarse|aviso|problemas|pagar|arrepentir)\b/u.test(text)
            )
        );

    return {
        clause_index: clauseIndex,
        text,
        risk,
        threat_score: score,
        reason,
        signals: {
            physical_harm: physical.found,
            physical_harm_kind: physical.kind,
            physical_harm_fuzzy: physical.fuzzy,
            human_target: humanTarget,
            second_person: secondPerson,
            intent,
            incitement,
            condition,
            harm_wish: harmWish,
            indirect_threat: indirect.found,
            indirect_kind: indirect.kind,
            indirect_ambiguous: indirect.ambiguous,
            routine_knowledge: hasRoutineKnowledgeCue(text),
            implied_group_intimidation: hasImpliedGroupIntimidationCue(text),
            intimidating_tail: hasIntimidatingTailCue(text),
            offline_intimidation: hasOfflineIntimidationCue(text),
            coercive_threat: hasCoerciveThreatCue(text),
            retaliation: hasRetaliationCue(text),
            ultimatum: hasUltimatumCue(text),
            vague_punishment: hasVaguePunishmentCue(text),
            negated,
            meta_reported: effectiveMetaReported,
            explicit_use_mention: explicitUseMention,
            counter_speech: counterSpeech,
            fiction_historical: fictionHistorical,
            domain_safe_violence: domainSafeViolence,
            institutional,
            non_human: nonHuman || directNonHumanTarget,
            protected_context: protectedContext,
            neural_worthy: neuralWorthy
        },
        evidence: physical.text
    };
}

/**
 * Ejecuta el análisis estructural completo, incluida la composición local entre cláusulas adyacentes.
 */
function detectThreatsHeuristic(originalText) {
    const normalizedText =
        normalizeTextForAnalysis(originalText);

    if (!normalizedText) {
        throw new Error(
            'El comentario no contiene texto analizable después de normalizarlo.'
        );
    }

    validateApplicationLength(
        normalizeSpacing(originalText)
    );

    /*
     * Paso 1: resolver cada cláusula de forma independiente. La composición se
     * aplica recién después, para que una negación, una cita o un objeto local no
     * contaminen automáticamente todo el comentario.
     */
    const clauses =
        splitThreatClauses(normalizedText);

    const clauseResults =
        clauses.map(
            (clause, index) =>
                analyzeThreatClause(
                    clause,
                    index
                )
        );

    /*
     * Resuelve anáforas de objeto entre cláusulas contiguas. Si una cláusula
     * anterior nombra un objeto inequívocamente no humano y la siguiente sólo
     * usa un clítico ("lo/la") sin segunda persona ni rol humano, el clítico se
     * mantiene ligado al objeto.
     *
     * Ejemplo:
     *   "el proceso colgado: quiero reventarlo"
     */
    for (let index = 1; index < clauseResults.length; index += 1) {
        const previous = clauseResults[index - 1];
        const current = clauseResults[index];

        if (
            previous.signals.non_human
            && !current.signals.second_person
            && !hasHumanRole(current.text)
            && hasCliticHumanTarget(current.text)
        ) {
            current.risk = 'none';
            current.threat_score = 0;
            current.reason = 'non-human-anaphora';
            current.signals = {
                ...current.signals,
                human_target: false,
                non_human: true,
                protected_context: true,
                neural_worthy: false
            };
        }
    }

    /*
     * Un ultimátum aparente puede ser explicado inmediatamente por una
     * consecuencia administrativa o por un cambio inocuo del canal de
     * comunicación. La explicación debe estar en la cláusula contigua: no se
     * permite que una referencia lejana neutralice una amenaza.
     *
     * Ejemplos seguros:
     *   "No va a haber un tercer aviso: después la cuenta se suspende."
     *   "La próxima respuesta no te llega por acá; te la mando por correo."
     */
    for (let index = 0; index + 1 < clauseResults.length; index += 1) {
        const current = clauseResults[index];
        const next = clauseResults[index + 1];

        if (
            current.risk === 'medium'
            && current.reason === 'ultimatum'
            && !current.signals.physical_harm
            && !current.signals.routine_knowledge
            && hasBenignAdministrativeContinuation(next.text)
            && !next.signals.physical_harm
        ) {
            current.risk = 'none';
            current.threat_score = 0;
            current.reason = 'benign-administrative-continuation';
            current.signals = {
                ...current.signals,
                institutional: true,
                neural_worthy: false
            };
        }
    }

    /*
     * Coerción contextual V4.4: una exigencia puede ser inocua por sí sola, pero
     * pasar a MEDIUM cuando la cláusula inmediatamente contigua aporta una cola
     * intimidatoria. Se exige adyacencia y ausencia de contexto protegido.
     *
     * Ejemplo:
     *   "Eliminá esa acusación y cada uno sigue por su lado. Que saque sus conclusiones."
     */
    for (let index = 0; index + 1 < clauseResults.length; index += 1) {
        const current = clauseResults[index];
        const next = clauseResults[index + 1];

        if (
            current.risk === 'none'
            && !current.signals.protected_context
            && !current.signals.non_human
            && !current.signals.institutional
            && hasCoerciveDemandCue(current.text)
            && hasCoerciveIntimidatingTailCue(next.text)
            && !next.signals.protected_context
            && !next.signals.non_human
        ) {
            current.risk = 'medium';
            current.threat_score = 0.56;
            current.reason = 'coercive-demand-with-adjacent-intimidation';
            current.signals = {
                ...current.signals,
                coercive_threat: true,
                composed_from_adjacent_clauses: true
            };
        }
    }

    /*
     * V4.4 recompone únicamente pares ADYACENTES que quedaron sin resolver.
     *
     * Esto cubre estructuras naturales partidas por puntuación:
     *
     *   "si volvés a hacerlo. te dejo sin poder levantarte"
     *   "sé por dónde volvés; yo no me confiaría"
     *
     * No se crean ventanas arbitrarias ni combinaciones lejanas. También se
     * excluyen pares con contexto protegido u objetivo no humano, para no unir
     * una cita/negación/objeto con otra cláusula y fabricar una amenaza.
     */
    for (let index = 0; index + 1 < clauseResults.length; index += 1) {
        const left = clauseResults[index];
        const right = clauseResults[index + 1];

        if (
            left.signals.protected_context
            || right.signals.protected_context
            || left.signals.non_human
            || right.signals.non_human
        ) {
            continue;
        }

        if (
            left.risk !== 'none'
            || right.risk !== 'none'
        ) {
            continue;
        }

        const pair =
            analyzeThreatClause(
                `${left.text}. ${right.text}`,
                left.clause_index
            );

        if (pair.risk === 'high') {
            left.risk = 'high';
            left.threat_score = Math.max(0.86, pair.threat_score);
            left.reason = `adjacent-composition:${pair.reason}`;
            left.signals = {
                ...pair.signals,
                composed_from_adjacent_clauses: true
            };
            left.evidence = pair.evidence;
            continue;
        }

        if (pair.risk === 'medium') {
            left.risk = 'medium';
            left.threat_score = Math.max(0.54, pair.threat_score);
            left.reason = `adjacent-composition:${pair.reason}`;
            left.signals = {
                ...pair.signals,
                composed_from_adjacent_clauses: true
            };
        }
    }


    /*
     * Algunos redactores separan condición, consecuencia y daño con dos signos
     * consecutivos ("si insistís: la próxima respuesta: va a ser una paliza").
     * V4.4 permite una única composición de tres cláusulas adyacentes. Sigue siendo
     * O(n), no crea combinaciones arbitrarias y respeta los mismos bloqueos de
     * contexto/no-humano que la composición por pares.
     */
    for (let index = 0; index + 2 < clauseResults.length; index += 1) {
        const first = clauseResults[index];
        const second = clauseResults[index + 1];
        const third = clauseResults[index + 2];

        if (
            first.risk === 'high'
            || second.risk === 'high'
            || third.risk === 'high'
            || first.signals.protected_context
            || second.signals.protected_context
            || third.signals.protected_context
            || first.signals.non_human
            || second.signals.non_human
            || third.signals.non_human
        ) {
            continue;
        }

        const composed =
            analyzeThreatClause(
                `${first.text}. ${second.text}. ${third.text}`,
                first.clause_index
            );

        if (composed.risk === 'high') {
            first.risk = 'high';
            first.threat_score = Math.max(0.86, composed.threat_score);
            first.reason = `adjacent-triple-composition:${composed.reason}`;
            first.signals = {
                ...composed.signals,
                composed_from_adjacent_clauses: true,
                composed_clause_count: 3
            };
            first.evidence = composed.evidence;
        } else if (composed.risk === 'medium') {
            first.risk = 'medium';
            first.threat_score = Math.max(0.54, composed.threat_score);
            first.reason = `adjacent-triple-composition:${composed.reason}`;
            first.signals = {
                ...composed.signals,
                composed_from_adjacent_clauses: true,
                composed_clause_count: 3
            };
        }
    }


    /*
     * Límite superior de composición local: cuatro cláusulas consecutivas.
     * Sólo se usa cuando la puntuación fragmentó una única construcción
     * prospectiva (condición + anuncio + consecuencia + daño). El coste sigue
     * siendo lineal respecto del número de cláusulas.
     */
    for (let index = 0; index + 3 < clauseResults.length; index += 1) {
        const group = clauseResults.slice(index, index + 4);

        if (
            group.some((item) =>
                item.risk === 'high'
                || item.signals.protected_context
                || item.signals.non_human
            )
        ) {
            continue;
        }

        const composed =
            analyzeThreatClause(
                group.map((item) => item.text).join('. '),
                group[0].clause_index
            );

        if (composed.risk === 'high') {
            group[0].risk = 'high';
            group[0].threat_score = Math.max(0.86, composed.threat_score);
            group[0].reason = `adjacent-quad-composition:${composed.reason}`;
            group[0].signals = {
                ...composed.signals,
                composed_from_adjacent_clauses: true,
                composed_clause_count: 4
            };
            group[0].evidence = composed.evidence;
        } else if (
            composed.risk === 'medium'
            && group[0].risk === 'none'
        ) {
            group[0].risk = 'medium';
            group[0].threat_score = Math.max(0.54, composed.threat_score);
            group[0].reason = `adjacent-quad-composition:${composed.reason}`;
            group[0].signals = {
                ...composed.signals,
                composed_from_adjacent_clauses: true,
                composed_clause_count: 4
            };
        }
    }

    /*
     * Herencia de objetivo inmediata para amenazas indirectas. Se mantiene
     * deliberadamente local: una cláusula intimidatoria sólo puede heredar el
     * objetivo humano de la cláusula inmediatamente anterior.
     */
    for (let index = 0; index < clauseResults.length; index += 1) {
        const current = clauseResults[index];
        const previous =
            index > 0
                ? clauseResults[index - 1]
                : null;
        const next =
            index + 1 < clauseResults.length
                ? clauseResults[index + 1]
                : null;

        if (
            current.risk === 'none'
            && current.signals.indirect_threat
            && !current.signals.protected_context
            && !current.signals.institutional
            && previous?.signals.human_target
            && !previous.signals.protected_context
            && !previous.signals.non_human
        ) {
            current.risk = 'medium';
            current.threat_score = 0.55;
            current.reason = 'indirect-threat-with-adjacent-human-target';
            continue;
        }

        /*
         * Rutina/vigilancia y cola intimidatoria también pueden repartirse en
         * cláusulas contiguas. Exigimos ambas mitades para evitar que conocer un
         * horario, por sí solo, se convierta en amenaza.
         */
        if (
            current.risk === 'none'
            && current.signals.routine_knowledge
            && !current.signals.protected_context
            && (
                previous?.signals.intimidating_tail
                || next?.signals.intimidating_tail
                || previous && hasIntimidatingContinuation(previous.text)
                || next && hasIntimidatingContinuation(next.text)
            )
        ) {
            current.risk = 'medium';
            current.threat_score = 0.58;
            current.reason = 'routine-knowledge-with-adjacent-intimidation';
            continue;
        }

        if (
            current.risk === 'none'
            && current.signals.indirect_ambiguous
            && current.signals.human_target
            && !current.signals.protected_context
            && !current.signals.institutional
            && (
                previous && hasIntimidatingContinuation(previous.text)
                || next && hasIntimidatingContinuation(next.text)
            )
        ) {
            current.risk = 'medium';
            current.threat_score = 0.52;
            current.reason = 'ambiguous-intimidation-with-adjacent-cue';
        }
    }

    /*
     * Paso final estructural: elegir la evidencia de mayor riesgo ya resuelta.
     * El score sólo desempata dentro de una misma clase; nunca convierte por sí
     * solo NONE en MEDIUM ni MEDIUM en HIGH.
     */
    const riskRank = {
        none: 0,
        medium: 1,
        high: 2
    };

    const strongest =
        clauseResults.reduce(
            (best, current) => {
                if (!best) {
                    return current;
                }

                if (
                    riskRank[current.risk]
                    > riskRank[best.risk]
                ) {
                    return current;
                }

                if (
                    riskRank[current.risk]
                    === riskRank[best.risk]
                    && current.threat_score
                    > best.threat_score
                ) {
                    return current;
                }

                return best;
            },
            null
        );

    const risk = strongest?.risk ?? 'none';
    const threatScore = strongest?.threat_score ?? 0;

    /*
     * MEDIUM siempre va a moderación. Para NONE se calcula una segunda señal
     * independiente que no altera la clasificación ni el score. HIGH se bloquea
     * por política y no necesita review_recommended.
     */
    const reviewCandidate =
        risk === 'none'
            ? findReviewRecommendation(clauseResults)
            : {
                recommended: risk === 'medium',
                reason: risk === 'medium'
                    ? `medium-risk:${strongest?.reason ?? 'unspecified'}`
                    : null,
                clause_index: risk === 'medium'
                    ? strongest?.clause_index ?? null
                    : null,
                clause_text: risk === 'medium'
                    ? strongest?.text ?? null
                    : null
            };

    const reviewRecommended =
        risk !== 'high'
        && Boolean(reviewCandidate.recommended);

    /*
     * ONNX sólo recibe casos que TODAVÍA quedaron en NONE y contienen evidencia
     * semántica suficiente. Los HIGH/MEDIUM estructurales no gastan inferencia.
     */
    const neuralCandidates =
        risk === 'none'
            ? clauseResults
                .filter((clause) =>
                    clause.signals.neural_worthy
                )
                .sort((left, right) => {
                    const leftWeight =
                        Number(left.signals.physical_harm) * 4
                        + Number(left.signals.indirect_threat) * 3
                        + Number(left.signals.human_target) * 2
                        + Number(left.signals.intent || left.signals.incitement || left.signals.condition);

                    const rightWeight =
                        Number(right.signals.physical_harm) * 4
                        + Number(right.signals.indirect_threat) * 3
                        + Number(right.signals.human_target) * 2
                        + Number(right.signals.intent || right.signals.incitement || right.signals.condition);

                    return rightWeight - leftWeight;
                })
                .slice(0, MAX_NEURAL_CANDIDATES)
                .map((clause) => clause.text)
            : [];

    const neuralWorthy =
        risk === 'none'
        && neuralCandidates.length > 0;

    return {
        detected: risk !== 'none',
        classification: risk !== 'none'
            ? 'threat'
            : 'not-threat',
        risk,
        threat_score: threatScore,
        review_recommended: reviewRecommended,
        review_reason: reviewCandidate.reason,
        score_type: 'structural-operational-v4.4',
        risk_points: null,
        signals: strongest?.signals ?? {},
        matches: [],
        analysis: {
            architecture_version: 'contextual-threat-scope-fusion-v4.4',
            normalized_length: normalizedText.length,
            clause_count: clauseResults.length,
            strongest_clause_index: strongest?.clause_index ?? null,
            strongest_clause_text: strongest?.text ?? null,
            strongest_reason: strongest?.reason ?? null,
            review_recommended: reviewRecommended,
            review_reason: reviewCandidate.reason,
            review_clause_index: reviewCandidate.clause_index,
            review_clause_text: reviewCandidate.clause_text,
            neural_worthy: neuralWorthy,
            neural_candidate_count: neuralCandidates.length,
            neural_candidates: neuralCandidates,
            clauses: clauseResults
        }
    };
}

/* ============================================================================
 * ESTADO REUTILIZABLE V4.4
 * ============================================================================ */

/*
 * Estas referencias deben vivir a nivel de módulo. V4 había conservado las
 * funciones de carga pero perdió sus declaraciones durante la refactorización,
 * provocando `tokenizerInstance is not defined` al entrar al fallback ONNX.
 */
let tokenizerInstance = null;
let tokenizerJsonInstance = null;
let sessionPromise = null;
let modelConfigInstance = null;
let threatLabelIndex = null;
let padTokenId = null;

/*
 * null  -> todavía no sabemos si el export admite batch > 1
 * true  -> batch dinámico confirmado
 * false -> el export exige batch=1
 */
let dynamicBatchSupported = null;

/* Cache LRU global del proceso. */
const threatInferenceCache = new Map();


/**
 * Convierte un logit del modelo neuronal a un valor entre 0 y 1.
 */
function sigmoid(value) {
    return 1 / (1 + Math.exp(-value));
}


/**
 * Redondea números para que la salida del benchmark sea legible.
 */
function roundNumber(
    value,
    decimals = 6
) {
    const factor =
        10 ** decimals;

    return (
        Math.round(
            Number(value)
            * factor
        )
        / factor
    );
}


/**
 * Carga una sola vez el tokenizer local.
 */
function getTokenizer() {
    if (tokenizerInstance) {
        return tokenizerInstance;
    }

    const tokenizerJson =
        JSON.parse(
            readFileSync(
                TOKENIZER_PATH,
                'utf8'
            )
        );

    tokenizerJsonInstance =
        tokenizerJson;

    const tokenizerConfig =
        JSON.parse(
            readFileSync(
                TOKENIZER_CONFIG_PATH,
                'utf8'
            )
        );

    tokenizerInstance =
        new Tokenizer(
            tokenizerJson,
            tokenizerConfig
        );

    return tokenizerInstance;
}


/**
 * Carga una sola vez la sesión ONNX.
 */
function getSession() {
    if (!sessionPromise) {
        sessionPromise =
            ort.InferenceSession.create(
                MODEL_PATH
            );
    }

    return sessionPromise;
}


/**
 * Carga config.json una sola vez.
 */
function getModelConfig() {
    if (
        modelConfigInstance
    ) {
        return modelConfigInstance;
    }

    modelConfigInstance =
        JSON.parse(
            readFileSync(
                MODEL_CONFIG_PATH,
                'utf8'
            )
        );

    return modelConfigInstance;
}


/**
 * Busca dinámicamente el índice de `threat` en config.json.
 *
 * En el modelo actual es 5, pero no se fija a mano para evitar errores si una
 * futura versión cambia el orden de etiquetas.
 */
function getThreatLabelIndex() {
    if (threatLabelIndex !== null) {
        return threatLabelIndex;
    }

    const config =
        getModelConfig();

    const id2label =
        config.id2label
        ?? {};

    for (const [index, label] of Object.entries(id2label)) {
        if (
            String(label)
                .trim()
                .toLowerCase()
            === 'threat'
        ) {
            threatLabelIndex =
                Number(index);

            return threatLabelIndex;
        }
    }

    throw new Error(
        'No se encontró la etiqueta `threat` en config.json.'
    );
}


/**
 * Resuelve el token de padding desde la configuración local.
 *
 * Se evita fijarlo manualmente para mantener compatibilidad con futuras
 * variantes del modelo/tokenizer.
 */
function getPadTokenId() {
    if (
        padTokenId !== null
    ) {
        return padTokenId;
    }

    /*
     * Fuerza la carga del tokenizer para disponer también de tokenizer.json.
     */
    getTokenizer();

    const config =
        getModelConfig();

    padTokenId =
        Number(
            config.pad_token_id
            ?? tokenizerJsonInstance?.padding?.pad_id
            ?? 0
        );

    return padTokenId;
}


/**
 * Tokeniza un texto.
 */
function encodeText(text) {
    return getTokenizer()
        .encode(
            text
        );
}


/**
 * Recorta únicamente como mecanismo defensivo si un segmento supera el límite
 * del modelo.
 *
 * Conserva los tokens especiales inicial y final.
 */
function fitEncodingToModel(encoding) {
    if (
        encoding.ids.length
        <= MAX_MODEL_TOKENS
    ) {
        return {
            ids:
                encoding.ids,
            attentionMask:
                encoding.attention_mask,
            truncated:
                false
        };
    }

    const firstId =
        encoding.ids[0];

    const lastId =
        encoding.ids[
            encoding.ids.length - 1
        ];

    const innerIds =
        encoding.ids.slice(
            1,
            MAX_MODEL_TOKENS - 1
        );

    const ids = [
        firstId,
        ...innerIds,
        lastId
    ];

    return {
        ids,
        attentionMask:
            new Array(
                ids.length
            )
                .fill(1),
        truncated:
            true
    };
}


/*
 * Cache LRU
 * ---------------------------------------------------------------------------
 *
 * Cada entrada contiene únicamente la parte neuronal de la inferencia. Los
 * metadatos propios del segmento (tipo, posición, índice) se agregan después.
 */

/**
 * Obtiene una inferencia cacheada y refresca su posición LRU.
 */
function getCachedThreatInference(
    text
) {
    if (
        !threatInferenceCache.has(
            text
        )
    ) {
        return null;
    }

    const result =
        threatInferenceCache.get(
            text
        );

    threatInferenceCache.delete(
        text
    );

    threatInferenceCache.set(
        text,
        result
    );

    return result;
}


/**
 * Guarda una inferencia y limita el tamaño de la cache.
 */
function setCachedThreatInference(
    text,
    result
) {
    if (
        threatInferenceCache.has(
            text
        )
    ) {
        threatInferenceCache.delete(
            text
        );
    }

    threatInferenceCache.set(
        text,
        result
    );

    while (
        threatInferenceCache.size
        > MODEL_INFERENCE_CACHE_MAX
    ) {
        const oldestKey =
            threatInferenceCache
                .keys()
                .next()
                .value;

        if (
            oldestKey === undefined
        ) {
            break;
        }

        threatInferenceCache.delete(
            oldestKey
        );
    }
}


/**
 * Convierte una fila de logits del modelo en la señal `threat`.
 */
function buildThreatInferenceResult(
    rowLogits,
    fitted
) {
    const threatIndex =
        getThreatLabelIndex();

    if (
        threatIndex < 0
        || threatIndex >= rowLogits.length
    ) {
        throw new Error(
            `Índice threat inválido: ${threatIndex}/${rowLogits.length}.`
        );
    }

    const threatLogit =
        Number(
            rowLogits[
                threatIndex
            ]
        );

    return {
        token_count:
            fitted.ids.length,

        truncated:
            fitted.truncated,

        threat_logit:
            roundNumber(
                threatLogit
            ),

        threat_score:
            roundNumber(
                sigmoid(
                    threatLogit
                )
            )
    };
}


/**
 * Ejecuta una llamada ONNX para varias secuencias tokenizadas.
 *
 * Las filas se rellenan únicamente hasta la longitud máxima del lote. La
 * attention_mask mantiene el padding fuera de la atención del modelo.
 *
 * Si la primera prueba con batch > 1 falla, se marca el export como
 * `batch=1-only` y el resto del proceso continúa directamente con inferencias
 * individuales, evitando repetir excepciones en cada lote.
 */
async function inferThreatEncodedBatch(
    encodedEntries
) {
    if (
        encodedEntries.length === 0
    ) {
        return {
            results: [],
            run_count: 0
        };
    }

    /*
     * Si ya sabemos que este ONNX exige batch=1, evitamos intentar batches
     * dinámicos nuevamente.
     */
    if (
        dynamicBatchSupported === false
        && encodedEntries.length > 1
    ) {
        const fallbackResults = [];
        let fallbackRunCount = 0;

        for (const entry of encodedEntries) {
            const single =
                await inferThreatEncodedBatch([
                    entry
                ]);

            fallbackResults.push(
                single.results[0]
            );

            fallbackRunCount +=
                single.run_count;
        }

        return {
            results:
                fallbackResults,

            run_count:
                fallbackRunCount
        };
    }

    const session =
        await getSession();

    const batchSize =
        encodedEntries.length;

    const maxSequenceLength =
        Math.max(
            ...encodedEntries.map(
                (entry) =>
                    entry.fitted.ids.length
            )
        );

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
        BigInt(
            getPadTokenId()
        )
    );

    /*
     * attentionMask nace en cero. Solo se activan las posiciones reales.
     */
    encodedEntries.forEach(
        (
            entry,
            rowIndex
        ) => {
            const ids =
                entry.fitted.ids;

            const mask =
                entry.fitted.attentionMask;

            const rowOffset =
                rowIndex
                * maxSequenceLength;

            for (
                let tokenIndex = 0;
                tokenIndex < ids.length;
                tokenIndex += 1
            ) {
                inputIds[
                    rowOffset
                    + tokenIndex
                ] =
                    BigInt(
                        ids[
                            tokenIndex
                        ]
                    );

                attentionMask[
                    rowOffset
                    + tokenIndex
                ] =
                    BigInt(
                        mask[
                            tokenIndex
                        ]
                        ?? 1
                    );
            }
        }
    );

    let result;

    try {
        result =
            await session.run({
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

        if (
            batchSize > 1
        ) {
            dynamicBatchSupported =
                true;
        }
    } catch (error) {
        /*
         * Un error en batch=1 es un error real y se propaga.
         */
        if (
            batchSize === 1
        ) {
            throw error;
        }

        /*
         * El modelo probablemente fija batch=1. Lo recordamos para no repetir
         * este intento fallido durante los siguientes comentarios.
         */
        dynamicBatchSupported =
            false;

        const fallbackResults = [];
        let fallbackRunCount = 0;

        for (const entry of encodedEntries) {
            const single =
                await inferThreatEncodedBatch([
                    entry
                ]);

            fallbackResults.push(
                single.results[0]
            );

            fallbackRunCount +=
                single.run_count;
        }

        return {
            results:
                fallbackResults,

            run_count:
                fallbackRunCount
        };
    }

    const logits =
        Array.from(
            result.logits.data
        );

    const logitsDimensions =
        Array.from(
            result.logits.dims
            ?? []
        );

    const configuredClassCount =
        Object.keys(
            getModelConfig().id2label
            ?? {}
        ).length;

    const classCount =
        Number(
            logitsDimensions[
                logitsDimensions.length - 1
            ]
            ?? configuredClassCount
        );

    if (
        !Number.isInteger(
            classCount
        )
        || classCount <= 0
    ) {
        throw new Error(
            'No fue posible determinar la cantidad de etiquetas de Detoxify.'
        );
    }

    return {
        results:
            encodedEntries.map(
                (
                    entry,
                    rowIndex
                ) => {
                    const start =
                        rowIndex
                        * classCount;

                    const rowLogits =
                        logits.slice(
                            start,
                            start
                            + classCount
                        );

                    return buildThreatInferenceResult(
                        rowLogits,
                        entry.fitted
                    );
                }
            ),

        run_count:
            1
    };
}


/**
 * Resuelve todos los segmentos de un comentario mediante:
 *
 * 1. cache LRU;
 * 2. deduplicación exacta;
 * 3. tokenización;
 * 4. orden por longitud;
 * 5. batches ONNX de hasta 8 unidades;
 * 6. fallback automático a batch=1.
 */
async function inferThreatSegmentsBatched(
    segments
) {
    const results =
        new Array(
            segments.length
        );

    const unresolvedByText =
        new Map();

    let cacheHits = 0;

    segments.forEach(
        (
            segment,
            originalIndex
        ) => {
            const cached =
                getCachedThreatInference(
                    segment.text
                );

            if (
                cached
            ) {
                results[
                    originalIndex
                ] = {
                    ...segment,
                    ...cached
                };

                cacheHits +=
                    1;

                return;
            }

            if (
                !unresolvedByText.has(
                    segment.text
                )
            ) {
                unresolvedByText.set(
                    segment.text,
                    {
                        text:
                            segment.text,

                        indexes:
                            []
                    }
                );
            }

            unresolvedByText
                .get(
                    segment.text
                )
                .indexes
                .push(
                    originalIndex
                );
        }
    );

    const unresolvedEntries =
        Array.from(
            unresolvedByText.values()
        )
            .map(
                (entry) => {
                    const encoding =
                        encodeText(
                            entry.text
                        );

                    const fitted =
                        fitEncodingToModel(
                            encoding
                        );

                    return {
                        ...entry,

                        fitted,

                        sequence_length:
                            fitted.ids.length
                    };
                }
            )
            .sort(
                (
                    left,
                    right
                ) =>
                    left.sequence_length
                    - right.sequence_length
            );

    let batchCount = 0;

    for (
        let start = 0;
        start < unresolvedEntries.length;
        start += MODEL_INFERENCE_BATCH_SIZE
    ) {
        const batch =
            unresolvedEntries.slice(
                start,
                start
                + MODEL_INFERENCE_BATCH_SIZE
            );

        const batchInference =
            await inferThreatEncodedBatch(
                batch
            );

        batchCount +=
            batchInference.run_count;

        batch.forEach(
            (
                entry,
                batchIndex
            ) => {
                const modelResult =
                    batchInference.results[
                        batchIndex
                    ];

                setCachedThreatInference(
                    entry.text,
                    modelResult
                );

                entry.indexes.forEach(
                    (
                        originalIndex
                    ) => {
                        results[
                            originalIndex
                        ] = {
                            ...segments[
                                originalIndex
                            ],

                            ...modelResult
                        };
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
            unresolvedEntries.length,

        dynamic_batch_supported:
            dynamicBatchSupported
    };
}


/**
 * Compatibilidad interna para cualquier prueba puntual que quiera inferir un
 * único segmento.
 */
async function inferThreatSegment(
    segment
) {
    const inference =
        await inferThreatSegmentsBatched([
            segment
        ]);

    return inference.results[0];
}





/* ============================================================================
 * SEGMENTACIÓN NEURONAL SELECTIVA
 * ============================================================================ */

/**
 * Filtra segmentos que contienen señales suficientes para justificar inferencia neuronal.
 */
function isPotentialNeuralThreatSegment(text) {
    const normalized =
        normalizeTextForAnalysis(text);

    if (!normalized) {
        return false;
    }

    if (
        hasNegatedOrRejectedThreat(normalized)
        || isEntirelyReportedOrMetalinguisticClause(normalized)
        || isFictionHistoricalSafeClause(normalized)
    ) {
        return false;
    }

    const physical =
        findPhysicalHarmEvidence(normalized).found;

    const indirect =
        analyzeIndirectThreat(normalized).found;

    return (
        physical
        || indirect
        || hasHumanTarget(normalized)
            && (
                hasGeneralSemanticThreatCue(normalized)
                || /\b(?:voy|vamos|deber[ií]a|tendria|ojal[aá]|si|cuando|cuid|pagar|arrepent|problemas|aviso|mensaje)\b/u.test(normalized)
            )
    );
}

/**
 * Construye y deduplica el comentario completo y los candidatos lingüísticos enviados al modelo.
 */
function buildSegments(inputText) {
    const text =
        normalizeSpacing(inputText);

    if (!text) {
        return [];
    }

    validateApplicationLength(text);

    const candidates = [
        {
            type: 'full-comment',
            source_index: 0,
            start_char: 0,
            end_char: text.length,
            text
        }
    ];

    const clauses =
        splitThreatClauses(text);

    let sourceIndex = 0;

    for (const clause of clauses) {
        if (!isPotentialNeuralThreatSegment(clause)) {
            continue;
        }

        candidates.push({
            type: 'candidate-clause',
            source_index: sourceIndex,
            start_char: null,
            end_char: null,
            text: clause
        });

        sourceIndex += 1;

        if (
            sourceIndex
            >= MAX_NEURAL_CANDIDATES
        ) {
            break;
        }
    }

    const seen = new Set();
    const segments = [];

    for (const candidate of candidates) {
        const key = candidate.text.trim();

        if (!key || seen.has(key)) {
            continue;
        }

        seen.add(key);

        segments.push({
            segment_index: segments.length,
            ...candidate,
            text: key
        });
    }

    return segments;
}


/* ============================================================================
 * ANÁLISIS NEURONAL SELECTIVO
 * ============================================================================ */

/**
 * Ejecuta Detoxify sobre el comentario y sus segmentos candidatos y devuelve auditoría neuronal.
 */
async function classifyThreatDetoxify(inputText) {
    const text =
        normalizeSpacing(inputText);

    if (!text) {
        throw new Error(
            'El comentario está vacío.'
        );
    }

    validateApplicationLength(text);

    const fullEncoding =
        encodeText(text);

    const segments =
        buildSegments(text);

    const inference =
        await inferThreatSegmentsBatched(
            segments
        );

    const results = inference.results;
    const ranked =
        [...results]
            .sort(
                (left, right) =>
                    right.threat_score
                    - left.threat_score
            );

    const best = ranked[0] ?? null;

    return {
        model: 'gravitee-io/detoxify-onnx',
        model_file: 'model.quant.onnx',
        score_type: 'sigmoid-threat',
        threat_label: 'threat',
        threat_label_index: getThreatLabelIndex(),
        final_risk_assigned: false,
        application_limits: {
            max_characters: APP_MAX_CHARACTERS
        },
        segmentation: {
            strategy: 'full-comment-plus-linguistic-candidates-v4.4',
            max_model_tokens: MAX_MODEL_TOKENS,
            max_candidate_clauses: MAX_NEURAL_CANDIDATES,
            model_inference_batch_size: MODEL_INFERENCE_BATCH_SIZE,
            model_inference_cache_max: MODEL_INFERENCE_CACHE_MAX,
            model_batch_count: inference.batch_count,
            model_cache_hits: inference.cache_hits,
            model_unique_inference_count: inference.unique_inference_count,
            dynamic_batch_supported: inference.dynamic_batch_supported
        },
        input: {
            character_count: text.length,
            remaining_characters: APP_MAX_CHARACTERS - text.length,
            token_count: fullEncoding.ids.length
        },
        summary: {
            segment_count: results.length,
            max_threat_score: best?.threat_score ?? 0,
            best_segment_index: best?.segment_index ?? null,
            best_segment_type: best?.type ?? null,
            best_segment_token_count: best?.token_count ?? null,
            best_segment_truncated: best?.truncated ?? false,
            best_segment_start_char: best?.start_char ?? null,
            best_segment_end_char: best?.end_char ?? null,
            best_segment_text: best?.text ?? null
        },
        top_segments: ranked
            .slice(0, TOP_SEGMENTS_LIMIT)
            .map((segment) => ({
                segment_index: segment.segment_index,
                type: segment.type,
                threat_score: segment.threat_score,
                threat_logit: segment.threat_logit,
                token_count: segment.token_count,
                truncated: segment.truncated,
                start_char: segment.start_char,
                end_char: segment.end_char,
                text: segment.text
            }))
    };
}



/* ============================================================================
 * FUSIÓN HEURÍSTICA + DETOXIFY
 * ============================================================================ */

/**
 * Mapea el score neuronal al rango operativo reservado para la clase estructural seleccionada.
 */
function mapNeuralScoreToOperationalRisk(risk, neuralScore) {
    const score =
        Math.min(
            1,
            Math.max(
                0,
                Number(neuralScore ?? 0)
            )
        );

    if (risk === 'high') {
        return Math.round(
            (0.70 + score * 0.30)
            * 10000
        ) / 10000;
    }

    if (risk === 'medium') {
        return Math.round(
            (0.40 + score * 0.29)
            * 10000
        ) / 10000;
    }

    return Math.round(
        Math.min(0.20, score)
        * 10000
    ) / 10000;
}

/**
 * Revalida contexto y estructura del mejor segmento neuronal antes de permitir un rescate.
 */
function analyzeNeuralBestSegment(text) {
    const normalized =
        normalizeTextForAnalysis(text);

    /*
     * Las citas protegidas se enmascaran también en la validación del rescate
     * neuronal. De esta forma un score alto causado por una amenaza citada no
     * habilita por accidente una amenaza propia del autor.
     */
    const contextPrepared =
        maskProtectedQuotedText(normalized)
            .replace(/\s+/gu, ' ')
            .trim();

    const physical =
        findPhysicalHarmEvidence(contextPrepared);

    const indirect =
        analyzeIndirectThreat(contextPrepared);

    const humanTarget =
        hasHumanTarget(contextPrepared);

    const semanticCue =
        hasGeneralSemanticThreatCue(contextPrepared);

    const suppressed =
        hasNegatedOrRejectedThreat(contextPrepared)
        || isEntirelyReportedOrMetalinguisticClause(contextPrepared)
        || isFictionHistoricalSafeClause(contextPrepared)
        || hasFigurativeOrObjectContext(contextPrepared) && !humanTarget;

    const structuredEligible =
        !suppressed
        && humanTarget
        && (physical.found || indirect.found);

    const semanticEligible =
        !suppressed
        && humanTarget
        && semanticCue;

    return {
        eligible: structuredEligible || semanticEligible,
        structured_eligible: structuredEligible,
        semantic_eligible: semanticEligible,
        semantic_cue: semanticCue,
        physical_harm: physical.found,
        indirect_threat: indirect.found,
        human_target: humanTarget,
        suppressed,
        context_prepared_text: contextPrepared
    };
}


/**
 * Fusiona la decisión estructural con un rescate neuronal selectivo cuando todavía existe incertidumbre.
 */
async function detectThreats(originalText) {
    /*
     * Primero se agota la capa estructural. ONNX sólo interviene cuando el caso
     * permanece en NONE y las señales estructurales justifican una segunda opinión.
     */
    const heuristic =
        detectThreatsHeuristic(
            originalText
        );

    let finalRisk = heuristic.risk;
    let finalThreatScore = heuristic.threat_score;
    let finalReviewRecommended = Boolean(heuristic.review_recommended);
    let finalReviewReason = heuristic.review_reason ?? null;
    let decisionSource = 'structural-v4.4';
    let detoxify = null;
    let neuralContext = null;

    if (
        heuristic.risk === 'none'
        && heuristic.analysis.neural_worthy
        && !DISABLE_NEURAL
    ) {
        detoxify =
            await classifyThreatDetoxify(
                originalText
            );

        const neuralScore =
            detoxify.summary.max_threat_score;

        neuralContext =
            analyzeNeuralBestSegment(
                detoxify.summary.best_segment_text
                ?? originalText
            );

        if (
            neuralContext.eligible
            && neuralContext.physical_harm
            && neuralScore
                >= NEURAL_RESCUE_HIGH_THRESHOLD
        ) {
            finalRisk = 'high';
            finalThreatScore =
                mapNeuralScoreToOperationalRisk(
                    'high',
                    neuralScore
                );
            decisionSource = 'detoxify-selective-high';
        } else if (
            neuralContext.structured_eligible
            && neuralScore
                >= NEURAL_RESCUE_MEDIUM_THRESHOLD
        ) {
            finalRisk = 'medium';
            finalThreatScore =
                mapNeuralScoreToOperationalRisk(
                    'medium',
                    neuralScore
                );
            decisionSource = 'detoxify-selective-medium';
        } else if (
            neuralContext.semantic_eligible
            && neuralScore
                >= NEURAL_SEMANTIC_RESCUE_MEDIUM_THRESHOLD
        ) {
            /*
             * Rescate semántico conservador de V4. La red puede advertir una forma de
             * intimidación que la gramática no conocía, pero sin evidencia física
             * estructural se deriva a MEDIUM/revisión humana y nunca directamente
             * a HIGH.
             */
            finalRisk = 'medium';
            finalThreatScore =
                mapNeuralScoreToOperationalRisk(
                    'medium',
                    neuralScore
                );
            decisionSource = 'detoxify-semantic-medium';
        }
    }

    /*
     * Política final de señal: MEDIUM siempre recomienda revisión, HIGH se bloquea
     * y NONE conserva únicamente la recomendación estructural independiente.
     */
    if (finalRisk === 'high') {
        finalReviewRecommended = false;
        finalReviewReason = null;
    } else if (finalRisk === 'medium') {
        finalReviewRecommended = true;

        if (!finalReviewReason) {
            finalReviewReason = `medium-risk:${decisionSource}`;
        }
    }

    const detected =
        finalRisk !== 'none';

    const roundedNotThreatScore =
        Math.round(
            (1 - finalThreatScore)
            * 10000
        ) / 10000;

    return {
        detected,
        classification: detected
            ? 'threat'
            : 'not-threat',
        risk: finalRisk,
        threat_score: finalThreatScore,
        review_recommended: finalReviewRecommended,
        review_reason: finalReviewReason,
        score_type: 'hybrid-operational-v4.4',
        scores: {
            'not-threat': roundedNotThreatScore,
            threat: finalThreatScore
        },
        risk_points: heuristic.risk_points,
        signals: heuristic.signals,
        matches: heuristic.matches,
        analysis: {
            ...heuristic.analysis,
            hybrid: {
                decision_source: decisionSource,
                heuristic_risk: heuristic.risk,
                heuristic_score: heuristic.threat_score,
                neural_evaluated: Boolean(detoxify),
                neural_raw_score: detoxify
                    ? detoxify.summary.max_threat_score
                    : null,
                neural_best_segment_type: detoxify
                    ? detoxify.summary.best_segment_type
                    : null,
                neural_best_segment_text: detoxify
                    ? detoxify.summary.best_segment_text
                    : null,
                neural_context: neuralContext,
                review_recommended: finalReviewRecommended,
                review_reason: finalReviewReason
            }
        },
        components: {
            heuristic: {
                risk: heuristic.risk,
                threat_score: heuristic.threat_score,
                score_type: heuristic.score_type
            },
            detoxify: detoxify
                ? {
                    raw_threat_score: detoxify.summary.max_threat_score,
                    score_type: detoxify.score_type,
                    best_segment_type: detoxify.summary.best_segment_type,
                    best_segment_text: detoxify.summary.best_segment_text,
                    segment_count: detoxify.summary.segment_count,
                    model_batch_count: detoxify.segmentation.model_batch_count,
                    model_cache_hits: detoxify.segmentation.model_cache_hits,
                    model_unique_inference_count: detoxify.segmentation.model_unique_inference_count,
                    dynamic_batch_supported: detoxify.segmentation.dynamic_batch_supported
                }
                : null
        }
    };
}


/* ============================================================================
 * EXPORTS
 * ============================================================================ */

export {
    detectThreats,
    detectThreatsHeuristic
};


/* ============================================================================
 * EJECUCIÓN DIRECTA DESDE TERMINAL
 * ============================================================================ */

const isDirectRun =
    Boolean(process.argv[1])
    && pathToFileURL(
        resolve(process.argv[1])
    ).href
    === import.meta.url;

if (isDirectRun) {
    const text =
        process.argv
            .slice(2)
            .join(' ')
            .trim();

    if (!text) {
        console.error(
            JSON.stringify({
                error: 'No se recibió ningún comentario para analizar.'
            })
        );
        process.exitCode = 1;
    } else {
        try {
            const result =
                await detectThreats(text);

            process.stdout.write(
                `${JSON.stringify(result)}\n`
            );
        } catch (error) {
            console.error(
                JSON.stringify({
                    error: error instanceof Error
                        ? error.message
                        : String(error)
                })
            );
            process.exitCode = 1;
        }
    }
}
