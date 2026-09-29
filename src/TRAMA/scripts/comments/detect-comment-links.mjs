/* ============================================================================
 * SCRIPT: detect-comment-links.mjs
 * ============================================================================
 *
 * Detecta enlaces, direcciones web y formas deliberadamente camufladas dentro
 * de comentarios de TRAMA.
 *
 * Este detector es completamente determinista: no utiliza modelos de IA,
 * porcentajes, thresholds neuronales ni inferencia ONNX. La política de TRAMA
 * para comentarios es binaria:
 *
 * - LINK    -> el comentario contiene una dirección web o un intento de ocultarla;
 * - NO_LINK -> no se detectó ninguna dirección web.
 *
 * La arquitectura contempla, entre otras cosas:
 *
 * - normalización Unicode NFKC;
 * - eliminación de caracteres Unicode invisibles utilizados para evasión;
 * - protocolos normales y camuflados: http, https, hxxp, hxxps;
 * - URLs con protocolo, www y dominios escritos sin protocolo;
 * - dominios con ruta, query, fragmento y puerto;
 * - IPv4 y localhost utilizados como destinos web;
 * - puntos camuflados mediante [.] / (.) / {.};
 * - dominios escritos como "ejemplo punto com" o "example dot com";
 * - espacios alrededor del punto: "ejemplo . com";
 * - dominios con letras separadas: "e j e m p l o . c o m";
 * - dominios Unicode cuando terminan en una extensión reconocida;
 * - deduplicación de hallazgos equivalentes.
 *
 * El comentario original nunca se modifica. Todas las transformaciones se
 * realizan sobre copias auxiliares utilizadas exclusivamente para detección.
 *
 * La responsabilidad de este archivo termina en LINK / NO_LINK y sus detalles.
 * Este detector no devuelve ni decide acciones como allow/reject.
 * La decisión final de aprobar, moderar o rechazar el comentario corresponde
 * exclusivamente a la capa de moderación de TRAMA que consume este resultado.
 * ============================================================================ */

import path from 'node:path';
import process from 'node:process';
import {
    domainToASCII,
    pathToFileURL
} from 'node:url';


/* ============================================================================
 * 1. CONFIGURACIÓN GENERAL
 * ============================================================================ */

/*
 * Extensiones utilizadas únicamente para reconocer dominios DESNUDOS, es decir,
 * direcciones escritas sin http/https ni www.
 *
 * Una URL con protocolo explícito no depende de esta lista: el protocolo ya es
 * evidencia inequívoca de que el usuario está intentando escribir un enlace.
 *
 * La lista prioriza TLD de uso común y códigos de país frecuentes. Se evita usar
 * cualquier palabra de 2+ letras como TLD porque eso produciría falsos positivos
 * con abreviaturas y puntuación normal del idioma.
 */
const COMMON_TLDS = new Set([
    'com', 'org', 'net', 'edu', 'gov', 'mil', 'int',
    'info', 'biz', 'name', 'pro', 'mobi', 'tel', 'asia',
    'app', 'dev', 'io', 'ai', 'me', 'tv', 'cc', 'co',
    'site', 'online', 'store', 'shop', 'blog', 'news', 'live',
    'cloud', 'digital', 'tech', 'website', 'world', 'xyz', 'link',
    'click', 'space', 'agency', 'media', 'network', 'social',
    'ar', 'br', 'cl', 'uy', 'py', 'bo', 'pe', 'ec', 'co', 've',
    'mx', 'us', 'ca', 'uk', 'es', 'fr', 'de', 'it', 'pt', 'nl',
    'be', 'ch', 'at', 'ie', 'se', 'no', 'fi', 'dk', 'pl', 'cz',
    'gr', 'ro', 'hu', 'ua', 'ru', 'tr', 'il', 'ae', 'sa', 'in',
    'pk', 'bd', 'lk', 'cn', 'jp', 'kr', 'tw', 'hk', 'sg', 'my',
    'id', 'ph', 'th', 'vn', 'au', 'nz', 'za', 'ng', 'ke', 'eg',
    'ma', 'ly'
]);


/*
 * Algunos ccTLD muy cortos también son palabras frecuentes del idioma
 * ("se", "me", "in", "at", etc.). Cuando el usuario deja un espacio SOLAMENTE
 * después del punto, esos sufijos son demasiado ambiguos para reconstruir una
 * URL sin otra señal web. Los TLD de esta lista sí son suficientemente
 * característicos para aceptar formas como `ejemplo. com`.
 */
const HIGH_CONFIDENCE_SPACED_TLDS = new Set([
    'com', 'org', 'net', 'edu', 'gov', 'mil', 'int',
    'info', 'biz', 'name', 'pro', 'mobi', 'tel', 'asia',
    'app', 'dev', 'io', 'ai',
    'site', 'online', 'store', 'shop', 'blog', 'news', 'live',
    'cloud', 'digital', 'tech', 'website', 'world', 'xyz', 'link',
    'click', 'space', 'agency', 'media', 'network', 'social'
]);

const WRITTEN_DOT_META_CONTEXT_PATTERN =
    /\b(?:palabras?|termino|t[eé]rmino|frase|expresi[oó]n|menci[oó]n(?:\s+textual)?|concepto|texto|literalmente)\b[^.!?\n]{0,45}$/iu;

const INVISIBLE_UNICODE_PATTERN =
    /[\u200B-\u200F\u202A-\u202E\u2060-\u206F\uFEFF]/gu;

const TRAILING_URL_PUNCTUATION_PATTERN =
    /[),.;!?¡¿:'"”’»\]}]+$/u;


/* ============================================================================
 * 2. UTILIDADES GENERALES
 * ============================================================================ */

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

function stripTrailingUrlPunctuation(value) {
    return String(value ?? '')
        .trim()
        .replace(TRAILING_URL_PUNCTUATION_PATTERN, '');
}


/*
 * Indica si una posición aparece inmediatamente después de una palabra escrita
 * letra por letra con espacios, guiones o guiones bajos. Se utiliza para evitar
 * que una normalización parcial convierta sólo la última letra de una evasión en
 * un dominio artificial, por ejemplo `e j e m p l o[.]com` -> `o.com`.
 */
function hasSeparatedLabelImmediatelyBefore(
    source,
    position
) {
    const prefix =
        String(source ?? '')
            .slice(
                Math.max(0, Number(position) - 160),
                Number(position)
            );

    return /(?:^|[^\p{L}\p{N}])(?:[\p{L}\p{N}][\s_-]+){1,}[\p{L}\p{N}]$/u
        .test(prefix);
}

function tldFromHost(hostname) {
    const cleanHost = String(hostname ?? '')
        .toLocaleLowerCase('en')
        .replace(/^\[|\]$/gu, '')
        .replace(/\.$/u, '');

    const labels = cleanHost.split('.').filter(Boolean);
    return labels.at(-1) ?? '';
}

function hasRecognizedTld(hostname) {
    const tld = tldFromHost(hostname);

    return COMMON_TLDS.has(tld)
        || /^xn--[a-z0-9-]{2,59}$/u.test(tld);
}

function safeDomainToASCII(hostname) {
    const value = String(hostname ?? '')
        .trim()
        .replace(/^\[|\]$/gu, '')
        .replace(/\.$/u, '');

    if (!value) {
        return '';
    }

    try {
        return domainToASCII(value)
            .toLocaleLowerCase('en');
    } catch {
        return value.toLocaleLowerCase('en');
    }
}

function normalizeHostAndSuffix(candidate) {
    let value = stripTrailingUrlPunctuation(candidate)
        .replace(/\s+/gu, '');

    if (!value) {
        return '';
    }

    /*
     * Para protocolos normales se utiliza URL cuando es posible. Si el texto no
     * es una URL completamente parseable, se conserva una forma canónica simple
     * para auditoría sin impedir que el hallazgo sea reportado.
     */
    if (/^\/\//u.test(value)) {
        try {
            const url = new URL(`https:${value}`);
            const asciiHost = safeDomainToASCII(url.hostname);
            const port = url.port ? `:${url.port}` : '';
            return `//${asciiHost}${port}${url.pathname}${url.search}${url.hash}`;
        } catch {
            return value;
        }
    }

    if (/^[a-z][a-z0-9+.-]{1,20}:\/\//iu.test(value)) {
        try {
            const url = new URL(value);
            const asciiHost = safeDomainToASCII(url.hostname);

            if (asciiHost) {
                url.hostname = asciiHost;
            }

            return url.href;
        } catch {
            return value;
        }
    }

    /*
     * www. y dominios desnudos se normalizan sin introducir artificialmente una
     * ruta o protocolo que el usuario no escribió.
     */
    const slashIndex = value.search(/[/?#]/u);
    const hostPort = slashIndex >= 0
        ? value.slice(0, slashIndex)
        : value;
    const suffix = slashIndex >= 0
        ? value.slice(slashIndex)
        : '';

    const [hostPart, ...portParts] = hostPort.split(':');
    const asciiHost = safeDomainToASCII(hostPart);
    const port = portParts.length
        ? `:${portParts.join(':')}`
        : '';

    return `${asciiHost}${port}${suffix}`;
}


/* ============================================================================
 * 3. NORMALIZACIÓN UNICODE Y FORMAS DE EVASIÓN
 * ============================================================================ */

/**
 * Reconstruye únicamente transformaciones de forma que poseen una relación
 * directa con sintaxis web. No intenta convertir palabras arbitrarias en URLs.
 *
 * @param {string} text
 * @returns {object}
 */
function buildNormalizationBundle(text) {
    const originalText =
        String(text ?? '').trim();

    const nfkcText =
        originalText.normalize('NFKC');

    const invisibleUnicode =
        INVISIBLE_UNICODE_PATTERN.test(nfkcText);

    /* Reinicia lastIndex por tratarse de un patrón global reutilizado. */
    INVISIBLE_UNICODE_PATTERN.lastIndex = 0;

    const normalizedText =
        nfkcText
            .replace(INVISIBLE_UNICODE_PATTERN, '')
            .replace(/\r\n?/gu, '\n')
            .trim();

    const normalizationTypes = [];

    if (nfkcText !== originalText) {
        normalizationTypes.push('unicode-compatibility');
    }

    if (invisibleUnicode) {
        normalizationTypes.push('invisible-unicode');
    }

    let deobfuscatedText = normalizedText;

    const protocolNormalized =
        deobfuscatedText
            .replace(/\bhxxps\b/giu, 'https')
            .replace(/\bhxxp\b/giu, 'http')
            .replace(
                /\b(https?|ftp)\s+(?:dos\s+puntos|colon)\s+(?:barra|slash)\s+(?:barra|slash)\b/giu,
                '$1://'
            )
            .replace(
                /\b(https?|ftp)\s*:\s*\/\s*\//giu,
                '$1://'
            );

    if (protocolNormalized !== deobfuscatedText) {
        normalizationTypes.push('obfuscated-protocol');
        deobfuscatedText = protocolNormalized;
    }

    const bracketDotNormalized =
        deobfuscatedText.replace(
            /\s*(?:\[\s*(?:\.|dot|punto)\s*\]|\(\s*(?:\.|dot|punto)\s*\)|\{\s*(?:\.|dot|punto)\s*\})\s*/giu,
            (match, offset, source) =>
                hasSeparatedLabelImmediatelyBefore(
                    source,
                    offset
                )
                    ? match
                    : '.'
        );

    if (bracketDotNormalized !== deobfuscatedText) {
        normalizationTypes.push('bracketed-dot');
        deobfuscatedText = bracketDotNormalized;
    }

    /*
     * "www punto ejemplo punto com" puede normalizarse de forma segura porque
     * el marcador www ya expresa una intención web inequívoca.
     */
    const writtenWwwNormalized =
        deobfuscatedText.replace(
            /\bwww\s+(?:punto|dot)\s+/giu,
            'www.'
        );

    if (writtenWwwNormalized !== deobfuscatedText) {
        normalizationTypes.push('written-dot');
        deobfuscatedText = writtenWwwNormalized;
    }

    /*
     * Cierra espacios alrededor del último punto SÓLO cuando a la derecha existe
     * un TLD reconocido. Esto evita convertir puntuación normal como "Sr. Pérez"
     * o iniciales como "A. B." en supuestos dominios.
     */
    const tldAlternation =
        [...COMMON_TLDS]
            .sort((a, b) => b.length - a.length)
            .join('|');

    const spacedDotPattern = new RegExp(
        `([\\p{L}\\p{N}][\\p{L}\\p{N}-]{0,60}[\\p{L}\\p{N}])([ \\t]*)\\.([ \\t]*)(${tldAlternation})(?=\\b|[/:?#])`,
        'giu'
    );

    const spacedDotNormalized =
        deobfuscatedText.replace(
            spacedDotPattern,
            (
                match,
                hostLabel,
                beforeDotSpace,
                afterDotSpace,
                tld
            ) => {
                /*
                 * Un punto normal al final de una oración seguido por una palabra
                 * corta puede parecer accidentalmente `web.se`, `url.se`, etc.
                 *
                 * - si hay espacio ANTES del punto, la separación es deliberada;
                 * - si sólo hay espacio DESPUÉS, se reconstruye únicamente para
                 *   TLD de alta confianza;
                 * - sin espacios, el texto ya estaba unido y se conserva igual.
                 */
                if (
                    !beforeDotSpace
                    && afterDotSpace
                    && !HIGH_CONFIDENCE_SPACED_TLDS.has(
                        String(tld).toLocaleLowerCase('en')
                    )
                ) {
                    return match;
                }

                return `${hostLabel}.${tld}`;
            }
        );

    if (spacedDotNormalized !== deobfuscatedText) {
        normalizationTypes.push('spaced-dot');
        deobfuscatedText = spacedDotNormalized;
    }

    const writtenDotPattern = new RegExp(
        `([\\p{L}\\p{N}][\\p{L}\\p{N}-]{0,60}[\\p{L}\\p{N}])\\s+(punto|dot)\\s+(${tldAlternation})(?=\\b|[/:?#])`,
        'giu'
    );

    const writtenDotNormalized =
        deobfuscatedText.replace(
            writtenDotPattern,
            (
                match,
                hostLabel,
                marker,
                tld,
                offset,
                source
            ) => {
                const contextualPrefix =
                    String(source)
                        .slice(
                            Math.max(
                                0,
                                Number(offset) - 70
                            ),
                            Number(offset)
                            + String(hostLabel).length
                        );

                /*
                 * "la expresión punto com", "las palabras dot com", etc. son
                 * menciones metalingüísticas del separador/TLD y no un host.
                 */
                if (
                    WRITTEN_DOT_META_CONTEXT_PATTERN.test(
                        contextualPrefix
                    )
                ) {
                    return match;
                }

                return `${hostLabel}.${tld}`;
            }
        );

    if (writtenDotNormalized !== deobfuscatedText) {
        normalizationTypes.push('written-dot');
        deobfuscatedText = writtenDotNormalized;
    }

    /*
     * Después de reconocer el último label/TLD, se puede cerrar de manera segura
     * "www ." porque el destino ya conserva forma de dominio.
     */
    const wwwSpacingNormalized =
        deobfuscatedText.replace(
            /\bwww\s*\.\s*(?=[\p{L}\p{N}])/giu,
            'www.'
        );

    if (wwwSpacingNormalized !== deobfuscatedText) {
        normalizationTypes.push('spaced-www');
        deobfuscatedText = wwwSpacingNormalized;
    }

    return {
        originalText,
        normalizedText,
        deobfuscatedText,
        changed:
            originalText !== normalizedText
            || normalizedText !== deobfuscatedText,
        normalization_types:
            uniqueStrings(normalizationTypes)
    };
}

function normalizeTextForAnalysis(text) {
    return buildNormalizationBundle(text)
        .deobfuscatedText;
}


/* ============================================================================
 * 4. PATRONES DE DIRECCIONES WEB
 * ============================================================================ */

/*
 * Protocolo explícito. Al existir "scheme://" no se exige un TLD concreto: una
 * dirección interna, un dominio nuevo o un host no convencional sigue siendo un
 * enlace y la política de comentarios de TRAMA lo rechaza igualmente.
 */
const EXPLICIT_SCHEME_PATTERN =
    /\b(?:https?|ftp|ftps|ws|wss):\/\/[^\s<>"'`]+/giu;

const PROTOCOL_RELATIVE_PATTERN =
    /(?<!:)\/\/(?:[^\s<>"'`/]+\.)+[^\s<>"'`/]+(?:\/[^\s<>"'`]*)?/giu;

const WWW_PATTERN =
    /\bwww\.[^\s<>"'`]+/giu;

const LOCALHOST_PATTERN =
    /\blocalhost(?::\d{1,5})?(?:\/[^\s<>"'`]*)?/giu;

const IPV4_PATTERN =
    /\b(?:(?:25[0-5]|2[0-4]\d|1?\d?\d)\.){3}(?:25[0-5]|2[0-4]\d|1?\d?\d)(?::\d{1,5})?(?:\/[^\s<>"'`]*)?/gu;

/*
 * El dominio desnudo exige un TLD reconocido para reducir falsos positivos.
 * Se permiten labels Unicode y punycode. La validación final se realiza fuera
 * del regex para mantener el patrón legible y reutilizable.
 */
const BARE_DOMAIN_CANDIDATE_PATTERN =
    /(?<![@\p{L}\p{N}_.\/:-])(?!www\.)(?:[\p{L}\p{N}](?:[\p{L}\p{N}-]{0,61}[\p{L}\p{N}])?\.)+[\p{L}\p{N}-]{2,63}(?::\d{1,5})?(?:\/[^\s<>"'`]*)?(?:\?[^\s<>"'`]*)?(?:#[^\s<>"'`]*)?/giu;


/*
 * Algunos TLD reales también son nombres de propiedades muy comunes en código.
 *
 * Ejemplos:
 *
 *     user.id
 *     article.name
 *     window.name
 *     config.id
 *
 * No se pueden eliminar `id` o `name` de COMMON_TLDS porque existen dominios
 * reales con esas extensiones. En cambio, se descarta el candidato únicamente
 * cuando concurren TODAS estas condiciones:
 *
 * - tiene forma simple `identificador.propiedad`;
 * - la propiedad pertenece a un vocabulario habitual de miembros de objetos;
 * - el contexto inmediato contiene señales inequívocas de código o de acceso a
 *   una propiedad;
 * - no existe antes del candidato una construcción positiva que lo presente
 *   explícitamente como URL, enlace, dominio, host o endpoint.
 *
 * De esta forma `user.id` puede ser código en una explicación técnica, mientras
 * que `visitá user.id`, `dominio user.id` o `https://user.id` siguen siendo
 * detectados como enlaces.
 */
const COMMON_CODE_MEMBER_NAMES = new Set([
    'id', 'name', 'value', 'status', 'length', 'type', 'key', 'data',
    'title', 'slug', 'count', 'index', 'email', 'role', 'state', 'result',
    'message', 'text', 'label', 'path', 'method', 'body', 'headers'
]);

const SIMPLE_CODE_MEMBER_PATTERN =
    /^[\p{L}_$][\p{L}\p{N}_$]*\.[\p{L}_$][\p{L}\p{N}_$]*$/u;

const POSITIVE_WEB_REFERENCE_CUE_PATTERN =
    /\b(?:url|enlace|link|sitio|web|dominio|domain|host|hostname|endpoint|direcci[oó]n\s+web)\b[^.!?\n]{0,45}(?:es|son|queda|est[aá]|apunta|dirige|lleva|abre|usa|utiliza|:|=)\s*$/iu;

const CODE_CONTEXT_CUE_PATTERN =
    /\b(?:c[oó]digo|code|javascript|typescript|php|vue|laravel|objeto|object|variable|propiedad|property|atributo|attribute|campo|field|miembro|member|expresi[oó]n|identifier|identificador)\b/iu;

const CODE_OPERATION_CUE_PATTERN =
    /(?:\b(?:const|let|var|return|typeof|console|if|while|for)\b|===|!==|==|!=|=>|\?\?|\|\||&&|\.\s*$)/iu;

function isLikelyCodeMemberReference(
    sourceText,
    candidate,
    candidateIndex
) {
    const raw =
        stripTrailingUrlPunctuation(candidate);

    if (
        !raw
        || !SIMPLE_CODE_MEMBER_PATTERN.test(raw)
    ) {
        return false;
    }

    const labels =
        raw
            .toLocaleLowerCase('en')
            .split('.');

    const memberName =
        labels[1] ?? '';

    if (
        !COMMON_CODE_MEMBER_NAMES.has(memberName)
    ) {
        return false;
    }

    const source =
        String(sourceText ?? '');

    const index =
        Math.max(
            0,
            Number(candidateIndex) || 0
        );

    const before =
        source.slice(
            Math.max(
                0,
                index - 180
            ),
            index
        );

    const after =
        source.slice(
            index + String(candidate ?? '').length,
            Math.min(
                source.length,
                index + String(candidate ?? '').length + 80
            )
        );

    /*
     * Una señal web explícita ANTES del candidato prevalece sobre el contexto de
     * código. Así, frases como "la URL es user.id" continúan bloqueándose.
     */
    if (
        POSITIVE_WEB_REFERENCE_CUE_PATTERN.test(
            before.slice(-120)
        )
    ) {
        return false;
    }

    const nearby =
        `${before.slice(-140)} ${after.slice(0, 60)}`;

    const hasCodeContext =
        CODE_CONTEXT_CUE_PATTERN.test(nearby);

    const hasCodeOperation =
        CODE_OPERATION_CUE_PATTERN.test(
            before.slice(-80)
        )
        || /^(?:\s*(?:[;,\)\]\}]|===|!==|==|!=|\?\?|\|\||&&))/u
            .test(after);

    return (
        hasCodeContext
        || hasCodeOperation
    );
}



/*
 * Contextos técnicos en los que una secuencia con puntos puede ser una
 * referencia estructurada y no un host. La exclusión se aplica al candidato
 * individual, nunca al comentario completo.
 */
const NON_WEB_DOTTED_CONTEXT_PATTERN =
    /\b(?:c[oó]digo|program[aá]tic[oa]|expresi[oó]n\s+program[aá]tica|consulta\s+sql|sql|alias\s+de\s+columna|columna|propiedad|property|campo|field|miembro|member|variable\s+simb[oó]lica|celda\s+simb[oó]lica|f[oó]rmula|m[oó]dulo\s+interno|m[oó]dulo|clave\s+de\s+configuraci[oó]n|configuraci[oó]n|identificador\s+t[eé]cnico|notaci[oó]n|se\s+accede\s+a|array|build|namespace|paquete|package)\b/iu;

const DOCUMENT_REFERENCE_CONTEXT_PATTERN =
    /\b(?:expediente(?:\s+interno)?|identificador\s+documental|referencia\s+documental|n[uú]mero\s+de\s+expediente)\b/iu;

const DOCUMENT_REFERENCE_SHAPE_PATTERN =
    /^(?:[\p{L}]{2,12}\.)\d{2,6}(?:\.\d{1,12})+\.[\p{L}]{2,8}$/iu;

const DOTTED_IDENTIFIER_SHAPE_PATTERN =
    /^[\p{L}_$][\p{L}\p{N}_$-]*(?:\.[\p{L}\p{N}_$-]+)+$/u;

/*
 * Una señal de uso web explícito prevalece sobre cualquier contexto técnico.
 * Esto evita convertir la protección contextual en una vía de evasión:
 *
 *     "en el módulo, visitá app.dev" -> LINK
 *     "la URL del módulo es app.dev" -> LINK
 */
const POSITIVE_LINK_USE_CUE_PATTERN =
    /(?:\b(?:url|enlace|link|sitio|web|dominio|domain|host|hostname|endpoint|direcci[oó]n\s+web|p[aá]gina)\b[^.!?\n]{0,55}(?:es|son|queda|est[aá]|apunta|dirige|lleva|abre|usa|utiliza|visita|consult[aá]|acced[eé]|:|=)\s*$|\b(?:visit[aá]|visita|entr[aá]|abr[ií]|mir[aá]|acced[eé]|naveg[aá]|and[aá]|ve)\s+(?:a|en)?\s*$)/iu;


function hasPositiveLinkUseCue(
    sourceText,
    candidateIndex
) {
    const source =
        String(sourceText ?? '');

    const index =
        Math.max(
            0,
            Number(candidateIndex) || 0
        );

    const before =
        source.slice(
            Math.max(
                0,
                index - 150
            ),
            index
        );

    return (
        POSITIVE_WEB_REFERENCE_CUE_PATTERN.test(
            before.slice(-120)
        )
        || POSITIVE_LINK_USE_CUE_PATTERN.test(
            before.slice(-140)
        )
    );
}


/**
 * Distingue dominios desnudos reales de referencias técnicas con forma de
 * dominio. Se exige contexto local fuerte y se respeta siempre una señal
 * explícita de uso web.
 */
function isLikelyNonWebDottedReference(
    sourceText,
    candidate,
    candidateIndex
) {
    const raw =
        stripTrailingUrlPunctuation(candidate);

    if (!raw) {
        return false;
    }

    /*
     * Ruta, query, fragmento o puerto aportan sintaxis web adicional suficiente.
     * No se neutralizan aunque alrededor haya vocabulario técnico.
     */
    if (/[/?#]|:\d{1,5}$/u.test(raw)) {
        return false;
    }

    if (
        hasPositiveLinkUseCue(
            sourceText,
            candidateIndex
        )
    ) {
        return false;
    }

    const source =
        String(sourceText ?? '');

    const index =
        Math.max(
            0,
            Number(candidateIndex) || 0
        );

    const nearby =
        source.slice(
            Math.max(
                0,
                index - 130
            ),
            Math.min(
                source.length,
                index
                + String(candidate ?? '').length
                + 100
            )
        );

    if (
        DOCUMENT_REFERENCE_SHAPE_PATTERN.test(raw)
        && DOCUMENT_REFERENCE_CONTEXT_PATTERN.test(nearby)
    ) {
        return true;
    }

    if (
        DOTTED_IDENTIFIER_SHAPE_PATTERN.test(raw)
        && NON_WEB_DOTTED_CONTEXT_PATTERN.test(nearby)
    ) {
        return true;
    }

    /*
     * Conserva la protección específica v1.1 para miembros simples de objetos.
     */
    return isLikelyCodeMemberReference(
        sourceText,
        candidate,
        candidateIndex
    );
}


/*
 * Cuatro grupos numéricos válidos también pueden ser numeración editorial,
 * versión o estructura jerárquica. Sólo se neutralizan con contexto local
 * inequívoco y sin ruta/puerto.
 */
const NON_WEB_IPV4_CONTEXT_PATTERN =
    /\b(?:numeraci[oó]n(?:\s+interna)?|estructura\s+del\s+borrador|estructura\s+jer[aá]rquica|versi[oó]n|secci[oó]n|apartado|cap[ií]tulo|esquema\s+de\s+numeraci[oó]n)\b/iu;

const POSITIVE_NETWORK_CUE_PATTERN =
    /\b(?:ip|ipv4|direcci[oó]n\s+ip|servidor|server|host|router|gateway|nodo|endpoint|socket|puerto|port|conectar|conexi[oó]n)\b/iu;


function isLikelyNonWebIpv4Reference(
    sourceText,
    candidate,
    candidateIndex
) {
    const raw =
        stripTrailingUrlPunctuation(candidate);

    if (
        !raw
        || /[/:?#]/u.test(raw)
    ) {
        return false;
    }

    const source =
        String(sourceText ?? '');

    const index =
        Math.max(
            0,
            Number(candidateIndex) || 0
        );

    const before =
        source.slice(
            Math.max(
                0,
                index - 120
            ),
            index
        );

    if (
        POSITIVE_NETWORK_CUE_PATTERN.test(before)
        || hasPositiveLinkUseCue(
            sourceText,
            candidateIndex
        )
    ) {
        return false;
    }

    const nearby =
        source.slice(
            Math.max(
                0,
                index - 120
            ),
            Math.min(
                source.length,
                index
                + String(candidate ?? '').length
                + 90
            )
        );

    return NON_WEB_IPV4_CONTEXT_PATTERN.test(
        nearby
    );
}


/* ============================================================================
 * 5. DETECCIÓN DE DOMINIOS CON LETRAS SEPARADAS
 * ============================================================================ */

/*
 * Captura evasiones como:
 *
 * e j e m p l o . c o m
 * e-j-e-m-p-l-o [.] c-o-m
 *
 * La coincidencia sólo se acepta cuando, después de colapsar los separadores,
 * la extensión final pertenece a COMMON_TLDS. Esto evita interpretar cualquier
 * secuencia de letras separadas como una dirección web.
 */
const SEPARATED_DOMAIN_PATTERN =
    /(?<![\p{L}\p{N}@])((?:(?:[\p{L}\p{N}][\s_-]+){2,}[\p{L}\p{N}])|(?:[\p{L}\p{N}](?:[\p{L}\p{N}-]{1,61}[\p{L}\p{N}])?))\s*(?:\.|\[\s*\.\s*\]|\(\s*\.\s*\)|\{\s*\.\s*\}|\bpunto\b|\bdot\b)\s*((?:(?:[\p{L}][\s_-]+){1,}[\p{L}])|(?:[\p{L}]{2,24}))(?=$|[^\p{L}\p{N}])/giu;

function collapseSeparatedLabel(value) {
    return String(value ?? '')
        .replace(/[\s_-]+/gu, '')
        .toLocaleLowerCase('en');
}

function extractSeparatedDomains(text) {
    const detections = [];

    for (const match of String(text ?? '').matchAll(SEPARATED_DOMAIN_PATTERN)) {
        const rawHost = String(match[1] ?? '');
        const rawTld = String(match[2] ?? '');
        const separatedLabelPattern =
            /^(?:[\p{L}\p{N}][\s_-]+){1,}[\p{L}\p{N}]$/u;

        const hasInternalSeparation =
            separatedLabelPattern.test(rawHost)
            || separatedLabelPattern.test(rawTld);

        /*
         * Si ninguna parte contiene separación interna, el dominio corresponde a
         * la detección normal y no se duplica como evasión.
         */
        if (!hasInternalSeparation) {
            continue;
        }

        const host = collapseSeparatedLabel(rawHost);
        const tld = collapseSeparatedLabel(rawTld);

        if (!host || !COMMON_TLDS.has(tld)) {
            continue;
        }

        detections.push({
            raw: match[0].trim(),
            normalized: `${safeDomainToASCII(host)}.${tld}`,
            domain: `${safeDomainToASCII(host)}.${tld}`,
            type: 'separated_domain',
            obfuscated: true
        });
    }

    return detections;
}


/* ============================================================================
 * 6. EXTRACCIÓN Y NORMALIZACIÓN DE HALLAZGOS
 * ============================================================================ */

function hostnameFromCandidate(candidate) {
    const value = stripTrailingUrlPunctuation(candidate)
        .trim();

    if (!value) {
        return '';
    }

    if (/^\/\//u.test(value)) {
        try {
            return safeDomainToASCII(
                new URL(`https:${value}`).hostname
            );
        } catch {
            return '';
        }
    }

    if (/^[a-z][a-z0-9+.-]{1,20}:\/\//iu.test(value)) {
        try {
            return safeDomainToASCII(
                new URL(value).hostname
            );
        } catch {
            const withoutScheme =
                value.replace(
                    /^[a-z][a-z0-9+.-]{1,20}:\/\//iu,
                    ''
                );

            return safeDomainToASCII(
                withoutScheme.split(/[/:?#]/u)[0]
            );
        }
    }

    const withoutWww =
        value.replace(/^www\./iu, '');

    return safeDomainToASCII(
        withoutWww.split(/[/:?#]/u)[0]
    );
}

function pushDetection(
    output,
    detection,
    seen
) {
    const normalized =
        normalizeHostAndSuffix(
            detection.normalized
            ?? detection.raw
        );

    const domain =
        detection.domain
        || hostnameFromCandidate(normalized);

    const key =
        `${detection.type}:${normalized}`
            .toLocaleLowerCase('en');

    if (!normalized || seen.has(key)) {
        return;
    }

    seen.add(key);

    output.push({
        raw:
            String(detection.raw ?? '').trim(),
        normalized,
        domain:
            domain || null,
        type:
            detection.type,
        obfuscated:
            Boolean(detection.obfuscated)
    });
}

function extractPatternMatches({
    text,
    pattern,
    type,
    obfuscated = false,
    validate = null
}) {
    const output = [];

    for (const match of String(text ?? '').matchAll(pattern)) {
        const raw = stripTrailingUrlPunctuation(match[0]);

        if (!raw) {
            continue;
        }

        if (
            typeof validate === 'function'
            && !validate(raw, match)
        ) {
            continue;
        }

        output.push({
            raw,
            normalized: raw,
            domain: hostnameFromCandidate(raw),
            type,
            obfuscated
        });
    }

    return output;
}

/**
 * Extrae todas las direcciones detectables del comentario y deduplica formas
 * equivalentes encontradas en las distintas representaciones auxiliares.
 *
 * @param {string} text
 * @returns {object}
 */
function extractLinks(text) {
    const normalization =
        buildNormalizationBundle(text);

    const detections = [];
    const seen = new Set();

    /*
     * Se analiza primero la representación original/normalizada para preservar
     * información sobre enlaces explícitos escritos sin evasión.
     */
    const normalized = normalization.normalizedText;
    const deobfuscated = normalization.deobfuscatedText;

    for (const item of extractPatternMatches({
        text: normalized,
        pattern: EXPLICIT_SCHEME_PATTERN,
        type: 'explicit_url'
    })) {
        pushDetection(detections, item, seen);
    }

    for (const item of extractPatternMatches({
        text: normalized,
        pattern: PROTOCOL_RELATIVE_PATTERN,
        type: 'explicit_url'
    })) {
        pushDetection(detections, item, seen);
    }

    for (const item of extractPatternMatches({
        text: normalized,
        pattern: WWW_PATTERN,
        type: 'www_url'
    })) {
        pushDetection(detections, item, seen);
    }

    for (const item of extractPatternMatches({
        text: normalized,
        pattern: LOCALHOST_PATTERN,
        type: 'localhost'
    })) {
        pushDetection(detections, item, seen);
    }

    for (const item of extractPatternMatches({
        text: normalized,
        pattern: IPV4_PATTERN,
        type: 'ipv4_address',
        validate: (raw, match) =>
            !isLikelyNonWebIpv4Reference(
                normalized,
                raw,
                match.index
            )
    })) {
        pushDetection(detections, item, seen);
    }

    for (const item of extractPatternMatches({
        text: normalized,
        pattern: BARE_DOMAIN_CANDIDATE_PATTERN,
        type: 'bare_domain',
        validate: (raw, match) => {
            const domain =
                hostnameFromCandidate(raw);

            return (
                hasRecognizedTld(domain)
                && !isLikelyNonWebDottedReference(
                    normalized,
                    raw,
                    match.index
                )
            );
        }
    })) {
        pushDetection(detections, item, seen);
    }

    /*
     * Si la normalización reconstruyó una evasión, los hallazgos recuperados en
     * esta segunda pasada quedan marcados como obfuscated.
     */
    if (deobfuscated !== normalized) {
        const obfuscationType =
            normalization.normalization_types.includes('written-dot')
                ? 'written_domain'
                : 'obfuscated_url';

        for (const item of extractPatternMatches({
            text: deobfuscated,
            pattern: EXPLICIT_SCHEME_PATTERN,
            type: 'obfuscated_url',
            obfuscated: true
        })) {
            pushDetection(detections, item, seen);
        }

        for (const item of extractPatternMatches({
            text: deobfuscated,
            pattern: PROTOCOL_RELATIVE_PATTERN,
            type: 'obfuscated_url',
            obfuscated: true
        })) {
            pushDetection(detections, item, seen);
        }

        for (const item of extractPatternMatches({
            text: deobfuscated,
            pattern: WWW_PATTERN,
            type: obfuscationType,
            obfuscated: true
        })) {
            pushDetection(detections, item, seen);
        }

        for (const item of extractPatternMatches({
            text: deobfuscated,
            pattern: BARE_DOMAIN_CANDIDATE_PATTERN,
            type: obfuscationType,
            obfuscated: true,
            validate: (raw, match) => {
                const domain =
                    hostnameFromCandidate(raw);

                return (
                    hasRecognizedTld(domain)
                    && !isLikelyNonWebDottedReference(
                        deobfuscated,
                        raw,
                        match.index
                    )
                );
            }
        })) {
            pushDetection(detections, item, seen);
        }
    }

    /*
     * La evasión mediante letras separadas necesita una detección específica. No
     * se colapsan indiscriminadamente todas las letras separadas del comentario.
     */
    for (const item of extractSeparatedDomains(normalized)) {
        pushDetection(detections, item, seen);
    }

    return {
        links: detections,
        normalization
    };
}


/* ============================================================================
 * 7. CLASIFICACIÓN FINAL
 * ============================================================================ */

/**
 * Clasifica el comentario de manera binaria según la presencia de enlaces.
 *
 * No existe un porcentaje de confianza porque la salida no representa una
 * probabilidad: el detector encontró o no encontró una estructura de enlace.
 *
 * Esta función no decide acciones de moderación. La capa consumidora determina
 * posteriormente si un resultado LINK debe rechazarse según la política de TRAMA.
 *
 * @param {string} text
 * @returns {object}
 */
function detectLinks(text) {
    const originalText =
        String(text ?? '').trim();

    if (!originalText) {
        return {
            classification: 'no_link',
            has_links: false,
            link_count: 0,
            links: [],
            reason: null,
            analysis: {
                architecture_version:
                    'deterministic-link-detection-v1.2.1',
                normalization_changed: false,
                normalization_types: [],
                direct_url: false,
                www_url: false,
                bare_domain: false,
                written_domain: false,
                separated_domain: false,
                obfuscated_url: false,
                ipv4_address: false,
                localhost: false
            }
        };
    }

    const extracted =
        extractLinks(originalText);

    const links =
        extracted.links;

    const types =
        new Set(
            links.map((item) => item.type)
        );

    const hasLinks =
        links.length > 0;

    return {
        classification:
            hasLinks
                ? 'link'
                : 'no_link',
        has_links:
            hasLinks,
        link_count:
            links.length,
        links,
        reason:
            hasLinks
                ? 'comment_contains_link'
                : null,
        analysis: {
            architecture_version:
                'deterministic-link-detection-v1.2.1',
            normalization_changed:
                extracted.normalization.changed,
            normalization_types:
                extracted.normalization.normalization_types,
            direct_url:
                types.has('explicit_url'),
            www_url:
                types.has('www_url'),
            bare_domain:
                types.has('bare_domain'),
            written_domain:
                types.has('written_domain'),
            separated_domain:
                types.has('separated_domain'),
            obfuscated_url:
                types.has('obfuscated_url')
                || links.some((item) => item.obfuscated),
            ipv4_address:
                types.has('ipv4_address'),
            localhost:
                types.has('localhost')
        }
    };
}


/* ============================================================================
 * 8. EXPORTS
 * ============================================================================ */

/*
 * Las funciones quedan disponibles para baterías y para la capa de moderación
 * sin necesidad de crear un proceso Node separado para cada comentario.
 */
export {
    buildNormalizationBundle,
    detectLinks,
    extractLinks,
    normalizeTextForAnalysis
};


/* ============================================================================
 * 9. EJECUCIÓN DIRECTA DESDE TERMINAL
 * ============================================================================ */

/**
 * Permite ejecutar este archivo directamente desde la terminal para probar
 * manualmente un comentario sin necesidad de pasar por Laravel.
 *
 * Ejemplo:
 *
 * node scripts/comments/detect-comment-links.mjs "comentario"
 *
 * La ejecución muestra directamente en la terminal la clasificación obtenida y
 * los enlaces detectados, facilitando las pruebas manuales del detector.
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
                    'No se recibió ningún comentario para analizar.'
            })
        );

        process.exitCode = 1;
        return;
    }

    try {
        const result =
            detectLinks(text);

        console.log(
            JSON.stringify({
                classification:
                    result.classification,
                has_links:
                    result.has_links,
                link_count:
                    result.link_count,
                links:
                    result.links,
                reason:
                    result.reason
            })
        );
    } catch (error) {
        console.error(
            JSON.stringify({
                error:
                    error instanceof Error
                        ? error.message
                        : 'Error desconocido durante la detección.'
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
