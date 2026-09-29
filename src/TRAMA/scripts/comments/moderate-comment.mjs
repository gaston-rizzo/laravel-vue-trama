/* ============================================================================
 * SCRIPT: moderate-comment.mjs
 * ============================================================================
 *
 * TRAMA — COORDINADOR DE MODERACIÓN AUTOMÁTICA DE COMENTARIOS
 * ---------------------------------------------------------------------------
 *
 * Este archivo NO es un servidor HTTP y NO escucha puertos.
 *
 * Laravel lo ejecuta directamente desde el Job ModerateComment mediante
 * Process::run(), de la misma forma que TRAMA ejecuta sus procesos Node para
 * clasificación/procesamiento de imágenes.
 *
 * Entrada recibida por argumentos:
 *
 *     node scripts/comments/moderate-comment.mjs <comment_id> <revision> <text>
 *
 * Ejemplo:
 *
 *     node scripts/comments/moderate-comment.mjs 845 2 "Excelente nota."
 *
 * Este proceso coordina los cinco detectores dentro de ESTA ejecución.
 *
 * Laravel NO llama directamente a cada detector. El recorrido completo es:
 *
 *     ModerateComment.php
 *            |
 *            | Process::run(...)
 *            v
 *     node scripts/comments/moderate-comment.mjs <id> <revision> <texto>
 *            |
 *            v
 *     moderate-comment.mjs recibe los argumentos con process.argv
 *            |
 *            v
 *     validateModerationInput() valida ID, revisión y texto
 *            |
 *            v
 *     moderateComment() ejecuta el pipeline
 *            |
 *            +--> detectCommentLanguage()
 *            |      archivo: detect-comment-language.mjs
 *            |
 *            +--> detectLinks()
 *            |      archivo: detect-comment-links.mjs
 *            |
 *            +--> detectThreats()
 *            |      archivo: detect-comment-threats.mjs
 *            |
 *            +--> classifyToxicity()
 *            |      archivo: classify-comment-toxicity.mjs
 *            |
 *            +--> classifySpam()
 *                   archivo: classify-comment-spam.mjs
 *            |
 *            v
 *     buildResponse() prepara UN único resultado JSON
 *            |
 *            v
 *     process.stdout.write(...) devuelve ese JSON a Laravel
 *
 * Pipeline:
 *
 *     idioma -> links -> amenazas -> toxicidad -> spam
 *
 * IMPORTANTE:
 *
 * El pipeline NO ejecuta siempre los cinco detectores completos.
 *
 * Si una etapa obtiene una decisión terminal, el script devuelve el resultado
 * inmediatamente y NO ejecuta los detectores siguientes.
 *
 * Ejemplos:
 *
 * - si links encuentra un enlace:
 *
 *       idioma -> links -> "rejected"
 *
 *   amenazas, toxicidad y spam ya no se ejecutan.
 *
 * - si amenazas devuelve riesgo "high":
 *
 *       idioma -> links -> amenazas -> "rejected"
 *
 *   toxicidad y spam ya no se ejecutan.
 *
 * - si una etapa sólo recomienda revisión humana, se guarda una razón
 *   provisional en pendingReasons y el pipeline continúa, porque un detector
 *   posterior todavía puede encontrar una causa de rechazo automático.
 *
 * Los detectores de idioma y links se importan al cargar este archivo porque
 * son las primeras etapas y siempre se utilizan. Amenazas, toxicidad y spam se
 * importan con await import(...) únicamente cuando el pipeline llega a ellos.
 * Así no se cargan sus modelos si una etapa anterior ya resolvió el comentario.
 *
 * Política:
 *
 * - link detectado                         -> rejected
 * - idioma extranjero o mezcla confirmada  -> pending y corta
 * - idioma indeterminado / insuficiente     -> pending provisional y continúa
 * - amenaza HIGH                            -> rejected
 * - amenaza MEDIUM/review                 -> pending provisional
 * - toxicidad review_recommended          -> pending provisional
 * - toxicidad >= 0.70 sin review          -> rejected
 * - spam review_recommended               -> pending
 * - spam >= 0.78 sin review               -> rejected
 * - sin señales de revisión/rechazo       -> approved
 *
 * "review_recommended" tiene prioridad sobre los porcentajes de toxicidad y
 * spam. Una señal pending provisional no impide que un detector posterior eleve
 * el resultado final a rejected.
 *
 * SALIDA
 * ---------------------------------------------------------------------------
 * stdout contiene exclusivamente el JSON consolidado cuando el proceso termina
 * correctamente. Los errores técnicos se escriben por stderr y el proceso
 * termina con código distinto de cero para que Laravel pueda reintentar el Job.
 * ============================================================================ */

/*
 * "process" permite leer los argumentos que Laravel envía al ejecutar Node:
 *
 *     process.argv[2] -> ID del comentario
 *     process.argv[3] -> revisión del comentario
 *     process.argv[4] -> texto del comentario
 *
 * También se utiliza al final para escribir el JSON por stdout, escribir
 * errores técnicos por stderr y establecer un código de salida distinto de 0.
 */
import process from 'node:process';

/*
 * "performance" se utiliza únicamente para medir cuánto tarda cada detector
 * y cuánto tarda el pipeline completo.
 */
import {
    performance
} from 'node:perf_hooks';

/*
 * IDIOMA se importa al iniciar este archivo porque siempre es la primera
 * comprobación semántica del comentario.
 *
 * Cuando moderateComment() llega al Paso 1 llama directamente:
 *
 *     detectCommentLanguage(text)
 */
import {
    detectCommentLanguage
} from './detect-comment-language.mjs';

/*
 * LINKS también se importa al iniciar este archivo porque siempre se ejecuta,
 * incluso cuando el idioma no está confirmado como español.
 *
 * Cuando moderateComment() llega al Paso 2 llama directamente:
 *
 *     detectLinks(text)
 */
import {
    detectLinks
} from './detect-comment-links.mjs';


/* ============================================================================
 * 1. CONFIGURACIÓN
 * ============================================================================ */

/*
 * Identifica la versión del contrato que este coordinador devuelve a Laravel.
 * Se incluye dentro de "schema_version" para que PHP pueda conservar qué
 * versión del pipeline produjo cada resultado guardado.
 */
const SCRIPT_VERSION =
    'trama-comment-moderation-cli-v1.1.0';

/*
 * Umbral utilizado por ESTE coordinador para convertir una toxicidad clara
 * en rechazo automático.
 *
 * El detector puede devolver además "review_recommended". Esa señal se revisa
 * antes que el porcentaje y, cuando está activa, el comentario no se rechaza
 * automáticamente por este umbral: queda marcado para moderación humana.
 */
const TOXICITY_REJECT_MIN =
    0.70;

/*
 * Umbral utilizado por ESTE coordinador para convertir spam claro en rechazo
 * automático. Igual que en toxicidad, "review_recommended" tiene prioridad.
 */
const SPAM_REJECT_MIN =
    0.78;


/* ============================================================================
 * 2. UTILIDADES
 * ============================================================================ */

/**
 * Limita un número al rango comprendido entre 0 y 1.
 *
 * Los scores de amenazas, toxicidad y spam se guardan como valores entre
 * 0 y 1. Esta función impide que un valor numérico válido pero fuera de rango
 * termine formando parte del JSON final.
 *
 * Ejemplos:
 *
 *     clamp01(-0.20) -> 0
 *     clamp01(0.75)  -> 0.75
 *     clamp01(1.40)  -> 1
 *
 * @param {*} value Valor que debe convertirse y limitarse.
 * @returns {number} Número comprendido entre 0 y 1.
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
 * Valida y normaliza un score devuelto por uno de los detectores.
 *
 * Primero convierte el valor a Number. Si el resultado no es un número finito,
 * lanza un error técnico porque el detector rompió el contrato esperado.
 *
 * Si es válido:
 *
 *     1. clamp01() lo limita entre 0 y 1;
 *     2. toFixed(6) lo redondea a seis decimales;
 *     3. Number() vuelve a dejarlo como número y no como texto.
 *
 * El error lanzado acá sube hasta la entrada principal del script. Allí se
 * escribe por stderr y Node termina con código 1 para que Laravel considere
 * fallido el intento del Job.
 *
 * @param {*} value Score recibido desde el detector.
 * @param {string} fieldName Nombre utilizado para identificar el campo si falla.
 * @returns {number} Score validado entre 0 y 1.
 */
function roundScore(
    value,
    fieldName = 'score'
) {
    const numericValue =
        Number(value);

    if (!Number.isFinite(numericValue)) {
        throw new Error(
            `El detector devolvió ${fieldName} inválido.`
        );
    }

    return Number(
        clamp01(numericValue)
            .toFixed(6)
    );
}

/**
 * Normaliza una duración medida con performance.now().
 *
 * Evita tiempos negativos, convierte el valor a número y conserva dos
 * decimales. El resultado se guarda dentro de "timings_ms" para poder saber
 * cuánto tardó cada etapa del análisis.
 *
 * @param {*} value Duración en milisegundos.
 * @returns {number} Duración no negativa con dos decimales.
 */
function roundMilliseconds(value) {
    return Number(
        Math.max(0, Number(value) || 0)
            .toFixed(2)
    );
}

/**
 * Valida los tres datos que Laravel entrega a este script por línea de comandos.
 *
 * La entrada se construye al final del archivo utilizando process.argv:
 *
 *     comment_id <- process.argv[2]
 *     revision   <- process.argv[3]
 *     text       <- process.argv[4]
 *
 * Se comprueba que:
 *
 *     - comment_id sea un entero positivo;
 *     - revision sea un entero positivo;
 *     - text tenga entre 8 y 1200 caracteres.
 *
 * Si algo es inválido se lanza un error ANTES de ejecutar cualquier detector.
 * Ese error llega al catch final, se escribe por stderr y Node termina con
 * código 1. Laravel entonces recibe un fallo técnico y puede reintentar el Job.
 *
 * Si todo es correcto, devuelve un objeto limpio que se entrega a:
 *
 *     moderateComment(input)
 *
 * @param {object} payload Datos recibidos desde process.argv.
 * @returns {{comment_id: number, revision: number, text: string}}
 */
function validateModerationInput(payload) {
    const commentId =
        Number(payload?.comment_id);

    const revision =
        Number(payload?.revision);

    const text =
        String(payload?.text ?? '')
            .trim();

    if (
        !Number.isSafeInteger(commentId)
        || commentId <= 0
    ) {
        throw Object.assign(
            new Error(
                'comment_id debe ser un entero positivo.'
            ),
            {
                statusCode: 422
            }
        );
    }

    if (
        !Number.isSafeInteger(revision)
        || revision <= 0
    ) {
        throw Object.assign(
            new Error(
                'revision debe ser un entero positivo.'
            ),
            {
                statusCode: 422
            }
        );
    }

    if (
        text.length < 8
        || text.length > 1200
    ) {
        throw Object.assign(
            new Error(
                'text debe contener entre 8 y 1200 caracteres.'
            ),
            {
                statusCode: 422
            }
        );
    }

    return {
        comment_id:
            commentId,
        revision,
        text
    };
}

/**
 * Comprueba y reduce la respuesta completa del detector de idioma.
 *
 * Esta función recibe exactamente lo que devolvió:
 *
 *     detectCommentLanguage(text)
 *
 * No vuelve a detectar el idioma. Su trabajo es verificar que el detector
 * devolvió las propiedades mínimas esperadas y conservar sólo los datos que
 * este coordinador necesita para decidir si el pipeline puede continuar.
 *
 * El resultado reducido se guarda en:
 *
 *     pipeline.language
 *
 * Si el contrato es inválido se lanza un error técnico y NO se toma una
 * decisión de contenido sobre el comentario.
 *
 * @param {object} result Resultado devuelto por detect-comment-language.mjs.
 * @returns {object} Resumen del resultado de idioma.
 */
function compactLanguage(result) {
    if (
        !result
        || typeof result !== 'object'
        || typeof result.continue_pipeline !== 'boolean'
        || typeof result.is_spanish !== 'boolean'
    ) {
        throw new Error(
            'El detector de idioma devolvió un contrato inválido.'
        );
    }

    return {
        is_spanish:
            Boolean(
                result?.is_spanish
            ),
        continue_pipeline:
            Boolean(
                result?.continue_pipeline
            ),
        language:
            result?.language
            ?? 'unknown',
        detected_language:
            result?.detected_language
            ?? 'und',
        reason:
            result?.reason
            ?? null
    };
}

/**
 * Comprueba y reduce la respuesta completa del detector de links.
 *
 * Recibe el resultado de:
 *
 *     detectLinks(text)
 *
 * Verifica que "has_links" sea booleano y que "link_count" sea numérico.
 * Además obtiene los tipos de enlace detectados y elimina tipos repetidos.
 *
 * El resumen final se guarda en:
 *
 *     pipeline.links
 *
 * Si "pipeline.links.has_links" queda en true, moderateComment() devuelve
 * inmediatamente "rejected" y amenazas, toxicidad y spam NO se ejecutan.
 *
 * @param {object} result Resultado devuelto por detect-comment-links.mjs.
 * @returns {object} Resumen del análisis de enlaces.
 */
function compactLinks(result) {
    if (
        !result
        || typeof result !== 'object'
        || typeof result.has_links !== 'boolean'
        || !Number.isFinite(
            Number(result.link_count)
        )
    ) {
        throw new Error(
            'El detector de links devolvió un contrato inválido.'
        );
    }

    const detectedTypes =
        Array.from(
            new Set(
                Array.isArray(result?.links)
                    ? result.links
                        .map(
                            (item) =>
                                item?.type
                                ?? null
                        )
                        .filter(Boolean)
                    : []
            )
        );

    return {
        classification:
            result?.classification
            ?? 'no_link',
        has_links:
            Boolean(
                result?.has_links
            ),
        link_count:
            Number(
                result?.link_count
                ?? 0
            ) || 0,
        detected_types:
            detectedTypes,
        reason:
            result?.reason
            ?? null
    };
}

/**
 * Obtiene la señal "review_recommended" del detector de amenazas.
 *
 * El detector puede exponer esa señal en distintas partes de su respuesta.
 * Esta función busca las ubicaciones admitidas en orden y devuelve siempre
 * un booleano simple para que el resto del coordinador no tenga que conocer
 * la estructura interna completa del detector.
 *
 * @param {object} result Resultado completo del detector de amenazas.
 * @returns {boolean} true cuando amenazas recomienda revisión humana.
 */
function threatReviewRecommended(result) {
    return Boolean(
        result?.review_recommended
        ?? result?.analysis?.review_recommended
        ?? result?.analysis?.hybrid?.review_recommended
        ?? false
    );
}

/**
 * Obtiene el motivo asociado a la recomendación de revisión del detector
 * de amenazas.
 *
 * Igual que threatReviewRecommended(), oculta al coordinador las distintas
 * ubicaciones internas en las que el detector puede devolver esa información.
 *
 * @param {object} result Resultado completo del detector de amenazas.
 * @returns {*|null} Motivo de revisión o null cuando no existe.
 */
function threatReviewReason(result) {
    return result?.review_reason
        ?? result?.analysis?.review_reason
        ?? result?.analysis?.hybrid?.review_reason
        ?? null;
}

/**
 * Comprueba y reduce la respuesta del detector de amenazas.
 *
 * Esta función se ejecuta después de:
 *
 *     const { detectThreats } =
 *         await import("./detect-comment-threats.mjs");
 *
 *     const threatResult =
 *         await detectThreats(text);
 *
 * Valida principalmente que "risk" sea uno de:
 *
 *     "high"
 *     "medium"
 *     "none"
 *
 * También normaliza "threat_score" y conserva las señales que este
 * coordinador necesita para decidir rechazo automático o revisión humana.
 *
 * El resultado reducido se guarda en:
 *
 *     pipeline.threats
 *
 * @param {object} result Resultado devuelto por detect-comment-threats.mjs.
 * @returns {object} Resumen del análisis de amenazas.
 */
function compactThreat(result) {
    if (
        !result
        || typeof result !== 'object'
        || ![
            'high',
            'medium',
            'none'
        ].includes(result.risk)
    ) {
        throw new Error(
            'El detector de amenazas devolvió un contrato inválido.'
        );
    }

    return {
        classification:
            result?.classification
            ?? 'not-threat',
        risk:
            result?.risk
            ?? 'none',
        threat_score:
            roundScore(
                result?.threat_score,
                'threat_score'
            ),
        score_type:
            result?.score_type
            ?? null,
        review_recommended:
            threatReviewRecommended(
                result
            ),
        review_reason:
            threatReviewReason(
                result
            ),
        decision_source:
            result?.analysis?.hybrid?.decision_source
            ?? null,
        neural_evaluated:
            Boolean(
                result?.analysis?.hybrid?.neural_evaluated
            )
    };
}

/**
 * Comprueba y reduce la respuesta del detector de toxicidad.
 *
 * Recibe el resultado devuelto por:
 *
 *     classifyToxicity(text, { include_raw_model_audit: false })
 *
 * Valida que "classification" sea "toxic" o "not-toxic", normaliza
 * "toxicity_score" y conserva las señales de certeza y revisión humana.
 *
 * El resultado reducido se guarda en:
 *
 *     pipeline.toxicity
 *
 * @param {object} result Resultado de classify-comment-toxicity.mjs.
 * @returns {object} Resumen del análisis de toxicidad.
 */
function compactToxicity(result) {
    if (
        !result
        || typeof result !== 'object'
        || ![
            'toxic',
            'not-toxic'
        ].includes(result.classification)
    ) {
        throw new Error(
            'El detector de toxicidad devolvió un contrato inválido.'
        );
    }

    return {
        classification:
            result?.classification
            ?? 'not-toxic',
        toxicity_score:
            roundScore(
                result?.toxicity_score,
                'toxicity_score'
            ),
        score_type:
            result?.score_type
            ?? null,
        certainty:
            result?.certainty
            ?? result?.analysis?.certainty
            ?? null,
        uncertain:
            Boolean(
                result?.uncertain
                ?? result?.analysis?.uncertain
                ?? false
            ),
        review_recommended:
            Boolean(
                result?.review_recommended
                ?? result?.analysis?.review_recommended
                ?? false
            )
    };
}

/**
 * Comprueba y reduce la respuesta del detector de spam.
 *
 * Recibe el resultado devuelto por:
 *
 *     classifySpam(text, { include_raw_model_audit: false })
 *
 * Valida que "classification" sea "spam" o "not_spam", normaliza
 * "spam_score" y conserva las señales necesarias para decidir si el comentario
 * debe aprobarse, rechazarse o enviarse a moderación humana.
 *
 * El resultado reducido se guarda en:
 *
 *     pipeline.spam
 *
 * @param {object} result Resultado de classify-comment-spam.mjs.
 * @returns {object} Resumen del análisis de spam.
 */
function compactSpam(result) {
    if (
        !result
        || typeof result !== 'object'
        || ![
            'spam',
            'not_spam'
        ].includes(result.classification)
    ) {
        throw new Error(
            'El detector de spam devolvió un contrato inválido.'
        );
    }

    return {
        classification:
            result?.classification
            ?? 'not_spam',
        spam_score:
            roundScore(
                result?.spam_score,
                'spam_score'
            ),
        score_type:
            result?.score_type
            ?? null,
        certainty:
            result?.certainty
            ?? result?.analysis?.certainty
            ?? null,
        uncertain:
            Boolean(
                result?.uncertain
                ?? result?.analysis?.uncertain
                ?? false
            ),
        review_recommended:
            Boolean(
                result?.review_recommended
                ?? result?.analysis?.review_recommended
                ?? false
            )
    };
}

/**
 * Convierte el resultado de idioma en una razón interna de moderación humana.
 *
 * Se llama cuando:
 *
 *     pipeline.language.continue_pipeline === false
 *
 * Devuelve:
 *
 *     "language_mixed"       -> mezcla de idiomas confirmada;
 *     "unsupported_language" -> idioma extranjero confirmado;
 *     "language_review"      -> idioma indeterminado o evidencia insuficiente.
 *
 * La razón puede ser terminal o provisional:
 *
 * - "language_mixed" y "unsupported_language" terminan el pipeline en pending;
 * - "language_review" se conserva sólo como señal de seguridad provisional y
 *   el pipeline continúa por amenazas, toxicidad y spam. Si esas etapas salen
 *   limpias, el comentario puede aprobarse aunque el texto haya sido corto.
 *
 * @param {object} language Resumen creado por compactLanguage().
 * @returns {string} Código interno de la razón.
 */
function languagePendingReason(language) {
    if (
        language?.language
        === 'mixed'
    ) {
        return 'language_mixed';
    }

    if (
        language?.language
        === 'foreign'
    ) {
        return 'unsupported_language';
    }

    return 'language_review';
}

/**
 * Indica si un resultado de idioma no confirmado debe tratarse como una señal
 * provisional en lugar de cortar inmediatamente el pipeline.
 *
 * Sólo devuelve true para "unknown". Esto cubre casos como:
 *
 *     "hija de puta."
 *
 * donde el texto es demasiado corto o ambiguo para confirmar español, pero una
 * etapa posterior todavía puede detectar toxicidad o una amenaza clara.
 *
 * Los idiomas realmente extranjeros y las mezclas confirmadas continúan siendo
 * terminales y se derivan directamente a moderación humana.
 *
 * @param {object} language Resumen creado por compactLanguage().
 * @returns {boolean}
 */
function shouldContinueSafetyAnalysisForLanguage(language) {
    return (
        language?.continue_pipeline
            === false
        && language?.language
            === 'unknown'
    );
}

/**
 * Construye el único objeto final que este proceso devuelve a Laravel.
 *
 * Todos los caminos válidos del pipeline terminan llamando a esta función:
 *
 *     link detectado       -> buildResponse(... "rejected" ...)
 *     amenaza HIGH         -> buildResponse(... "rejected" ...)
 *     idioma no soportado  -> buildResponse(... "pending" ...)
 *     toxicidad clara      -> buildResponse(... "rejected" ...)
 *     spam claro           -> buildResponse(... "rejected" ...)
 *     revisión necesaria   -> buildResponse(... "pending" ...)
 *     comentario limpio    -> buildResponse(... "approved" ...)
 *
 * La función NO escribe todavía en stdout. Sólo construye un objeto uniforme.
 * La escritura real ocurre al final del archivo:
 *
 *     process.stdout.write(JSON.stringify(result))
 *
 * "reasons" se deduplica para no enviar dos veces la misma causa.
 *
 * @param {object} data Datos consolidados del análisis.
 * @returns {object} Contrato final que Laravel convertirá desde JSON.
 */
function buildResponse({
    commentId,
    revision,
    decision,
    reason,
    reasons,
    stoppedAt,
    pipeline,
    timings
}) {
    return {
        ok: true,
        schema_version:
            SCRIPT_VERSION,
        comment_id:
            commentId,
        revision,
        decision,
        reason,
        reasons:
            Array.from(
                new Set(
                    reasons
                        .filter(Boolean)
                )
            ),
        stopped_at:
            stoppedAt,
        pipeline,
        timings_ms:
            timings
    };
}


/* ============================================================================
 * 3. CÓMO SE LLAMAN LOS CINCO DETECTORES
 * ============================================================================
 *
 * Este archivo es el único proceso Node que Laravel ejecuta directamente.
 *
 * Laravel NO hace cinco Process::run(). Hace uno solo:
 *
 *     node scripts/comments/moderate-comment.mjs <id> <revision> <texto>
 *
 * A partir de ahí, este archivo llama a los detectores.
 *
 * IDIOMA
 * ---------------------------------------------------------------------------
 *
 * Se importó al comienzo del archivo:
 *
 *     import { detectCommentLanguage }
 *         from "./detect-comment-language.mjs";
 *
 * Se ejecuta con:
 *
 *     detectCommentLanguage(text)
 *
 * LINKS
 * ---------------------------------------------------------------------------
 *
 * También se importó al comienzo:
 *
 *     import { detectLinks }
 *         from "./detect-comment-links.mjs";
 *
 * Se ejecuta con:
 *
 *     detectLinks(text)
 *
 * AMENAZAS
 * ---------------------------------------------------------------------------
 *
 * No se carga al iniciar este archivo. Se importa recién si idioma y links
 * permiten continuar:
 *
 *     const { detectThreats } =
 *         await import("./detect-comment-threats.mjs");
 *
 * Después se ejecuta:
 *
 *     await detectThreats(text)
 *
 * TOXICIDAD
 * ---------------------------------------------------------------------------
 *
 * Se importa recién si ninguna etapa anterior terminó el pipeline:
 *
 *     const { classify: classifyToxicity } =
 *         await import("./classify-comment-toxicity.mjs");
 *
 * El detector exporta una función llamada "classify". Acá se le asigna el
 * nombre local "classifyToxicity" para dejar claro qué clasificador se llama.
 *
 * Después se ejecuta:
 *
 *     await classifyToxicity(
 *         text,
 *         { include_raw_model_audit: false }
 *     )
 *
 * SPAM
 * ---------------------------------------------------------------------------
 *
 * Se importa únicamente si el comentario llegó hasta la última etapa:
 *
 *     const { classify: classifySpam } =
 *         await import("./classify-comment-spam.mjs");
 *
 * También exporta una función llamada "classify", por eso acá se renombra
 * localmente como "classifySpam".
 *
 * Después se ejecuta:
 *
 *     await classifySpam(
 *         text,
 *         { include_raw_model_audit: false }
 *     )
 *
 * Esta carga diferida evita abrir los modelos más pesados cuando idioma,
 * links o amenazas ya resolvieron el comentario.
 * ============================================================================ */


/* ============================================================================
 * 4. PIPELINE DE MODERACIÓN
 * ============================================================================ */

/**
 * Ejecuta el pipeline completo de moderación automática del comentario.
 *
 * Esta es la función principal de negocio de este archivo. Es invocada UNA vez
 * desde la entrada directa situada al final:
 *
 *     const result = await moderateComment(input);
 *
 * Recibe los datos ya validados por validateModerationInput() y ejecuta:
 *
 *     1. idioma
 *     2. links
 *     3. amenazas
 *     4. toxicidad
 *     5. spam
 *
 * No siempre llega al Paso 5. Cuando una etapa produce una decisión terminal,
 * devuelve buildResponse(...) inmediatamente y las etapas siguientes no se
 * importan ni se ejecutan.
 *
 * Las razones que sólo requieren revisión humana se guardan temporalmente en
 * "pendingReasons". El pipeline continúa porque un detector posterior puede
 * encontrar una causa más fuerte que termine en "rejected".
 *
 * @param {object} input Entrada ya validada.
 * @returns {Promise<object>} Resultado final para Laravel.
 */
async function moderateComment({
    comment_id: commentId,
    revision,
    text
}) {
    /*
     * Guarda el instante exacto en que empieza TODO el pipeline.
     * Al terminar se resta este valor de performance.now() para obtener
     * "timings.total".
     */
    const totalStartedAt =
        performance.now();

    /*
     * Cada propiedad guarda cuántos milisegundos consumió una etapa.
     *
     * Las etapas que no llegaron a ejecutarse permanecen en 0. Esto permite
     * distinguir en el JSON final qué partes del pipeline fueron omitidas por
     * un corte temprano.
     */
    const timings = {
        language: 0,
        links: 0,
        threats: 0,
        toxicity: 0,
        spam: 0,
        total: 0
    };

    /*
     * Acá se guarda el resumen devuelto por cada detector.
     *
     * Una etapa conserva null mientras no haya sido ejecutada. Por ejemplo,
     * si links produce "rejected", threats/toxicity/spam quedan en null porque
     * moderateComment() retorna antes de llegar a esos scripts.
     */
    const pipeline = {
        language: null,
        links: null,
        threats: null,
        toxicity: null,
        spam: null
    };

    /*
     * Acumula razones que requieren decisión humana pero que todavía no
     * terminan el pipeline.
     *
     * Ejemplo:
     *
     *     amenazas MEDIUM -> agrega "threat_medium"
     *     toxicidad clara -> puede aparecer después y elevar a "rejected"
     *
     * Si el recorrido termina sin rechazo y este array tiene elementos, la
     * decisión final será "pending".
     */
    const pendingReasons = [];

    /* ------------------------------------------------------------------------
     * Paso 1: idioma.
     * --------------------------------------------------------------------- */
    let startedAt =
        performance.now();

    /*
     * Llama a la función importada desde:
     *
     *     detect-comment-language.mjs
     *
     * Este detector es síncrono en el contrato actual, por eso no lleva await.
     */
    const languageResult =
        detectCommentLanguage(text);

    timings.language =
        roundMilliseconds(
            performance.now()
            - startedAt
        );

    /*
     * No guardamos en el pipeline toda la respuesta del detector.
     * compactLanguage() valida el contrato y conserva solamente los campos
     * necesarios para tomar la decisión.
     */
    pipeline.language =
        compactLanguage(
            languageResult
        );

    /* ------------------------------------------------------------------------
     * Paso 2: links.
     *
     * Se ejecuta incluso si el idioma no está soportado porque no depende de
     * comprensión semántica del español.
     * --------------------------------------------------------------------- */
    startedAt =
        performance.now();

    /*
     * Llama a detectLinks() importado desde:
     *
     *     detect-comment-links.mjs
     *
     * Se ejecuta aunque idioma haya indicado que no conviene continuar con
     * modelos semánticos, porque detectar un enlace no depende del idioma.
     */
    const linksResult =
        detectLinks(text);

    timings.links =
        roundMilliseconds(
            performance.now()
            - startedAt
        );

    pipeline.links =
        compactLinks(
            linksResult
        );

    /*
     * CORTE TERMINAL.
     *
     * Si se encontró al menos un enlace, la política de TRAMA rechaza el
     * comentario automáticamente. El return termina moderateComment() acá.
     *
     * Por lo tanto NO se importan ni ejecutan:
     *
     *     detect-comment-threats.mjs
     *     classify-comment-toxicity.mjs
     *     classify-comment-spam.mjs
     */
    if (pipeline.links.has_links) {
        timings.total =
            roundMilliseconds(
                performance.now()
                - totalStartedAt
            );

        return buildResponse({
            commentId,
            revision,
            decision:
                'rejected',
            reason:
                'link_detected',
            reasons: [
                'link_detected'
            ],
            stoppedAt:
                'links',
            pipeline,
            timings
        });
    }

    /*
     * El detector de idioma sigue controlando si un comentario puede publicarse
     * automáticamente como limpio, pero ahora distinguimos dos situaciones:
     *
     * 1. Idioma extranjero o mezcla confirmada:
     *    -> pending terminal;
     *    -> no cargamos amenazas, toxicidad ni spam.
     *
     * 2. Idioma "unknown" por texto corto o evidencia insuficiente:
     *    -> agregamos "language_review" como señal provisional;
     *    -> CONTINUAMOS por amenazas, toxicidad y spam;
     *    -> si una etapa posterior encuentra una causa clara de rechazo, puede
     *       elevar el resultado final a "rejected";
     *    -> si no encuentra nada grave, al final puede aprobarse automáticamente.
     *
     * Esto evita que un comentario como "hija de puta." quede fuera del análisis
     * de toxicidad únicamente porque es demasiado corto para confirmar idioma.
     */
    if (
        !pipeline.language
            .continue_pipeline
    ) {
        const reason =
            languagePendingReason(
                pipeline.language
            );

        /*
         * "unknown" no se considera un idioma extranjero confirmado.
         * Conservamos la revisión humana como fallback, pero permitimos que los
         * detectores de seguridad posteriores analicen el contenido.
         */
        if (
            shouldContinueSafetyAnalysisForLanguage(
                pipeline.language
            )
        ) {
            pendingReasons.push(
                reason
            );
        } else {
            /*
             * Idioma extranjero o mezcla confirmada: se mantiene el corte temprano
             * original y el comentario queda en moderación humana.
             */
            timings.total =
                roundMilliseconds(
                    performance.now()
                    - totalStartedAt
                );

            return buildResponse({
                commentId,
                revision,
                decision:
                    'pending',
                reason,
                reasons: [
                    reason
                ],
                stoppedAt:
                    'language',
                pipeline,
                timings
            });
        }
    }

    /* ------------------------------------------------------------------------
     * Paso 3: amenazas.
     *
     * Va antes de toxicidad porque una amenaza HIGH es una causa más específica
     * y terminal. MEDIUM/review no corta: otro detector todavía puede elevar el
     * comentario a rechazo automático.
     * --------------------------------------------------------------------- */
    startedAt =
        performance.now();

    /*
     * Amenazas se importa recién cuando links no rechazó y el idioma:
     *
     * - fue confirmado como español; o
     * - quedó "unknown" y requiere análisis de seguridad antes del fallback humano.
     *
     * Un idioma extranjero o una mezcla confirmada retornan antes y no cargan ONNX.
     */
    /*
     * await import(...) carga el módulo EN ESTE MOMENTO.
     *
     * Si idioma o links hubieran terminado el pipeline, la ejecución habría
     * retornado antes y este archivo de amenazas nunca se habría importado.
     */
    const {
        detectThreats
    } = await import(
        './detect-comment-threats.mjs'
    );

    /*
     * Una vez cargado el módulo se invoca la función que exporta:
     *
     *     detectThreats(text)
     *
     * Se utiliza await porque el detector puede ejecutar operaciones asíncronas,
     * entre ellas su análisis basado en modelo.
     */
    const threatResult =
        await detectThreats(text);

    timings.threats =
        roundMilliseconds(
            performance.now()
            - startedAt
        );

    pipeline.threats =
        compactThreat(
            threatResult
        );

    /*
     * CORTE TERMINAL POR AMENAZA HIGH.
     *
     * El return de este bloque termina todo el pipeline. Si ocurre:
     *
     *     toxicidad NO se importa;
     *     spam NO se importa.
     */
    if (
        pipeline.threats.risk
        === 'high'
    ) {
        timings.total =
            roundMilliseconds(
                performance.now()
                - totalStartedAt
            );

        return buildResponse({
            commentId,
            revision,
            decision:
                'rejected',
            reason:
                'threat_high',
            reasons: [
                'threat_high'
            ],
            stoppedAt:
                'threats',
            pipeline,
            timings
        });
    }

    /*
     * Una amenaza MEDIUM no rechaza automáticamente.
     *
     * Sólo guarda una razón de revisión humana y CONTINÚA a toxicidad.
     * Lo mismo ocurre con "review_recommended".
     */
    if (
        pipeline.threats.risk
        === 'medium'
    ) {
        pendingReasons.push(
            'threat_medium'
        );
    } else if (
        pipeline.threats
            .review_recommended
    ) {
        pendingReasons.push(
            'threat_review'
        );
    }

    /* ------------------------------------------------------------------------
     * Paso 4: toxicidad.
     *
     * "review_recommended" tiene prioridad sobre el porcentaje. Un 0.82 con
     * conflicto modelo/estructura queda PENDING, no REJECTED.
     * --------------------------------------------------------------------- */
    startedAt =
        performance.now();

    /*
     * Toxicidad carga su tokenizer/sesión ONNX al importar el módulo. Por eso la
     * importación se difiere hasta llegar realmente a esta etapa.
     */
    /*
     * El módulo exporta una función genérica llamada "classify".
     *
     * Al importarla le asignamos el nombre local "classifyToxicity" para que
     * quede claro que esta llamada corresponde al detector de toxicidad.
     */
    const {
        classify: classifyToxicity
    } = await import(
        './classify-comment-toxicity.mjs'
    );

    /*
     * Ejecuta el detector con el texto completo del comentario.
     *
     * "include_raw_model_audit: false" indica que el coordinador no necesita
     * incorporar al resultado consolidado toda la auditoría interna cruda del
     * modelo; sólo utiliza después los campos compactados por compactToxicity().
     */
    const toxicityResult =
        await classifyToxicity(
            text,
            {
                include_raw_model_audit:
                    false
            }
        );

    timings.toxicity =
        roundMilliseconds(
            performance.now()
            - startedAt
        );

    pipeline.toxicity =
        compactToxicity(
            toxicityResult
        );

    /*
     * Si toxicidad recomienda revisión humana, se agrega la razón y se continúa
     * hacia spam. NO se aplica el umbral de rechazo en este caso porque la señal
     * "review_recommended" tiene prioridad.
     */
    if (
        pipeline.toxicity
            .review_recommended
    ) {
        pendingReasons.push(
            'toxicity_review'
        );
    } else if (
        pipeline.toxicity
            .toxicity_score
        >= TOXICITY_REJECT_MIN
    ) {
        /*
         * CORTE TERMINAL POR TOXICIDAD CLARA.
         *
         * Al retornar "rejected" desde este bloque, spam NO se importa ni se
         * ejecuta.
         */
        const reasons = [
            ...pendingReasons,
            'toxicity_clear'
        ];

        timings.total =
            roundMilliseconds(
                performance.now()
                - totalStartedAt
            );

        return buildResponse({
            commentId,
            revision,
            decision:
                'rejected',
            reason:
                'toxicity_clear',
            reasons,
            stoppedAt:
                'toxicity',
            pipeline,
            timings
        });
    }

    /* ------------------------------------------------------------------------
     * Paso 5: spam.
     *
     * Igual que toxicidad: review tiene prioridad. Sólo un spam claro >= 0.78
     * sin review se rechaza automáticamente.
     * --------------------------------------------------------------------- */
    startedAt =
        performance.now();

    /*
     * Spam también se importa de forma diferida para no cargar su modelo cuando
     * una etapa anterior ya resolvió el comentario.
     */
    /*
     * Spam también exporta una función llamada "classify".
     *
     * Se renombra localmente como "classifySpam" para diferenciarla del
     * clasificador de toxicidad.
     */
    const {
        classify: classifySpam
    } = await import(
        './classify-comment-spam.mjs'
    );

    /*
     * Ejecuta el último detector del pipeline.
     *
     * Si llegamos hasta acá significa que links, amenazas y toxicidad no
     * produjeron antes una decisión terminal de rechazo.
     */
    const spamResult =
        await classifySpam(
            text,
            {
                include_raw_model_audit:
                    false
            }
        );

    timings.spam =
        roundMilliseconds(
            performance.now()
            - startedAt
        );

    pipeline.spam =
        compactSpam(
            spamResult
        );

    /*
     * Igual que en toxicidad, una recomendación explícita de revisión humana
     * tiene prioridad sobre el score.
     */
    if (
        pipeline.spam
            .review_recommended
    ) {
        pendingReasons.push(
            'spam_review'
        );
    } else if (
        pipeline.spam.spam_score
        >= SPAM_REJECT_MIN
    ) {
        const reasons = [
            ...pendingReasons,
            'spam_clear'
        ];

        timings.total =
            roundMilliseconds(
                performance.now()
                - totalStartedAt
            );

        return buildResponse({
            commentId,
            revision,
            decision:
                'rejected',
            reason:
                'spam_clear',
            reasons,
            stoppedAt:
                'spam',
            pipeline,
            timings
        });
    }

    timings.total =
        roundMilliseconds(
            performance.now()
            - totalStartedAt
        );

    /*
     * El pipeline llegó al final sin ningún rechazo terminal.
     *
     * Si alguna etapa de seguridad agregó una razón provisional, el comentario
     * se guarda como "pending" para que Laravel lo envíe a moderación humana.
     * La razón "language_review" por sí sola no alcanza: textos cortos como
     * "Buen dato" se aprueban si amenazas, toxicidad y spam salieron limpios.
     */
    const reviewReasons =
        pendingReasons.filter(
            (reason) => reason !== 'language_review'
        );

    if (
        reviewReasons.length > 0
    ) {
        return buildResponse({
            commentId,
            revision,
            decision:
                'pending',
            reason:
                reviewReasons[0],
            reasons:
                reviewReasons,
            stoppedAt:
                'complete',
            pipeline,
            timings
        });
    }

    /*
     * Si llegamos acá:
     *
     *     - no había links;
     *     - el idioma fue confirmado como español, o quedó "unknown" por texto
     *       corto/evidencia insuficiente pero seguridad no encontró riesgos;
     *     - amenazas no produjo rechazo ni revisión;
     *     - toxicidad no produjo rechazo ni revisión;
     *     - spam no produjo rechazo ni revisión.
     *
     * Por eso la decisión automática final es "approved".
     */
    return buildResponse({
        commentId,
        revision,
        decision:
            'approved',
        reason:
            'automatic_clean',
        reasons: [
            'automatic_clean'
        ],
        stoppedAt:
            'complete',
        pipeline,
        timings
    });
}




/* ============================================================================
 * 5. ENTRADA DIRECTA DESDE LARAVEL
 * ============================================================================ */

/*
 * ESTE BLOQUE ES EL PUNTO DE ENTRADA REAL DEL ARCHIVO.
 *
 * No existe una función main() que Laravel llame por nombre. Cuando Laravel
 * ejecuta:
 *
 *     node scripts/comments/moderate-comment.mjs 845 2 "Excelente nota."
 *
 * Node carga este archivo y ejecuta automáticamente todo el código que está en
 * el nivel superior del módulo. Por eso la ejecución llega directamente acá.
 *
 * En Node:
 *
 *     process.argv[0] -> ejecutable node
 *     process.argv[1] -> ruta de moderate-comment.mjs
 *     process.argv[2] -> "845"
 *     process.argv[3] -> "2"
 *     process.argv[4] -> "Excelente nota."
 *
 * Cada elemento llega como argumento independiente porque Laravel utiliza
 * Process::run([...]) con un array. El texto no se concatena manualmente a una
 * cadena de shell.
 *
 * validateModerationInput() convierte y valida esos valores ANTES de permitir
 * que se ejecute cualquier detector.
 *
 * Si esta validación inicial falla, Node aborta la ejecución con una excepción
 * antes de entrar al bloque que llama a moderateComment().
 */
const input = validateModerationInput({
    comment_id:
        process.argv[2],
    revision:
        process.argv[3],
    text:
        process.argv[4] ?? ''
});

try {
    /*
     * Acá se inicia realmente el análisis.
     *
     * moderateComment() va llamando a los detectores en orden y puede terminar
     * antes cuando alguno obtiene una decisión terminal.
     */
    const result =
        await moderateComment(input);

    /*
     * Si moderateComment() termina correctamente, "result" contiene el contrato
     * consolidado creado por buildResponse().
     *
     * stdout se reserva EXCLUSIVAMENTE para ese JSON porque ModerateComment.php
     * ejecuta después:
     *
     *     json_decode(trim($process->output()), ...)
     *
     * Cualquier console.log() u otro texto en stdout rompería ese JSON.
     */
    process.stdout.write(
        `${JSON.stringify(result)}\n`
    );
} catch (error) {
    /*
     * Cualquier error lanzado por:
     *
     *     validate/compactadores/detectores/importaciones/modelos
     *
     * llega a este catch si ocurre dentro del bloque de ejecución del pipeline.
     *
     * stderr queda reservado para diagnóstico técnico. ModerateComment.php
     * comprueba que el proceso terminó con código distinto de cero, registra el
     * fallo en TramaLog y deja que Laravel Queue reintente el Job.
     *
     * Este error NO significa "approved", "pending" ni "rejected". Es un fallo
     * técnico del proceso automático.
     */
    const message =
        error instanceof Error
            ? error.message
            : String(error);

    process.stderr.write(
        `[TRAMA comment moderation] ${message}\n`
    );

    process.exitCode = 1;
}
