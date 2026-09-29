/* ============================================================================
 * SCRIPT: detect-comment-language.mjs
 * VERSION: 4.1 — HÍBRIDO ROBUSTO / SCANNER MIXED REEQUILIBRADO
 * ============================================================================
 *
 * Objetivo
 * --------
 * Determinar si un comentario puede continuar por el pipeline semántico de
 * TRAMA, cuyos clasificadores posteriores están optimizados para español.
 *
 * Esta versión NO intenta resolver perfectamente todos los idiomas del mundo.
 * La pregunta operativa es deliberadamente binaria:
 *
 *     ¿hay evidencia suficiente de que el comentario es español y no contiene
 *     una mezcla lingüística significativa?
 *
 * Política:
 *
 *     español confirmado                  -> continue_pipeline = true
 *     idioma extranjero                   -> continue_pipeline = false
 *     mezcla español + otro idioma        -> continue_pipeline = false
 *     idioma indeterminado / insuficiente -> continue_pipeline = false
 *
 * Arquitectura V4.1
 * -----------------
 * 1. Normalización Unicode sin destruir tildes ni ñ.
 * 2. Eliminación de ruido lingüístico: URLs, correos, handles e IDs técnicos.
 * 3. Corte duro por alfabetos incompatibles con español.
 * 4. Evidencia positiva propia de español:
 *      - léxico de alta precisión;
 *      - palabras funcionales;
 *      - frases españolas frecuentes;
 *      - construcciones rioplatenses;
 *      - morfología;
 *      - ortografía característica.
 * 5. Evidencia extranjera contrastiva:
 *      - inglés;
 *      - portugués;
 *      - gallego;
 *      - catalán;
 *      - italiano;
 *      - francés;
 *      - alemán;
 *      - neerlandés;
 *      - polaco;
 *      - turco.
 * 6. franc/francAll como señal estadística SECUNDARIA.
 * 7. Detección de mezcla mediante:
 *      - marcadores extranjeros globales;
 *      - oraciones completas;
 *      - ventanas solapadas de palabras.
 * 8. Verificador sintáctico independiente de español corto/medio.
 * 9. Scanner local de code-switching por cláusulas, tokens y secuencias.
 * 10. Perfiles contrastivos para lenguas romances cercanas.
 * 11. Fallback estadístico genérico para bloques latinos no perfilados.
 * 12. Protección específica de listas, URLs, correos y ruido técnico.
 * 13. Consenso local: una inferencia estadística aislada no puede condenar español.
 * 14. Fusión asimétrica y conservadora.
 * 15. Scanner V4 de ventanas adaptativas 3/4/6/8/12 sin fronteras obligatorias.
 * 16. Consenso solapado para detectar islas extranjeras no perfiladas.
 *
 * Principios importantes
 * ----------------------
 * - `franc` NO puede rechazar por sí solo un fragmento español corto.
 * - `franc` NO puede convertir automáticamente gallego/catalán/portugués en
 *   español por simple cercanía estadística.
 * - una predicción estadística extraña (`vec`, `tpi`, etc.) jamás constituye
 *   evidencia suficiente de mezcla.
 * - los textos cortos se evalúan principalmente con reglas de alta precisión;
 *   si no se pueden confirmar, devuelven UNKNOWN.
 * - las palabras internacionales aceptadas (WhatsApp, Bitcoin, software...)
 *   no se consideran mezcla por sí mismas.
 *
 * Dependencia:
 *
 *     npm install franc
 *
 * Uso como módulo:
 *
 *     import {
 *         detectCommentLanguage
 *     } from './detect-comment-language.mjs';
 *
 *     const result = detectCommentLanguage(
 *         'Excelente nota, muy buen trabajo.'
 *     );
 *
 * Uso CLI:
 *
 *     node scripts/comments/detect-comment-language.mjs \
 *         "Excelente nota, muy buen trabajo."
 *
 * ============================================================================ */

import process from 'node:process';
import { pathToFileURL } from 'node:url';

import {
    francAll
} from 'franc';


/* ============================================================================
 * 1. CONFIGURACIÓN GENERAL
 * ============================================================================ */

const SPANISH_CODE =
    'spa';

const UNKNOWN_CODE =
    'und';

/*
 * En V4.1 la inferencia estadística local se restringe a idiomas latinos que
 * razonablemente pueden competir con español. Esto evita que un segmento corto
 * completamente español sea etiquetado como idiomas remotos sólo por ruido de
 * n-gramas.
 *
 * Los alfabetos no latinos se detectan antes mediante Unicode Script.
 */
const LATIN_STATISTICAL_CANDIDATES =
    Object.freeze([
        'spa',
        'eng',
        'por',
        'glg',
        'cat',
        'ita',
        'fra',
        'deu',
        'nld',
        'pol',
        'tur',
        'ron',
        'eus',
        'swe',
        'nor',
        'dan',
        'fin',
        'ces',
        'hun',
        'afr',
        'hrv',
        'slk',
        'slv',
        'swh',
        'vie',
        'ind',
        'zlm',
        'isl',
        'epo'
    ]);

const FRANC_MIN_LENGTH =
    8;

/*
 * Umbrales estructurales propios. No son probabilidades de idioma; representan
 * evidencia acumulada por la capa lingüística de TRAMA.
 */
const SPANISH_STRONG_SCORE =
    0.64;

const SPANISH_SUPPORTED_SCORE =
    0.38;

const SPANISH_MIN_SCORE =
    0.28;

/*
 * Tamaños utilizados por la detección de mezcla. Las ventanas solapadas son
 * suficientemente largas para que una señal estadística tenga valor, pero
 * siguen pudiendo localizar un bloque extranjero dentro de un comentario largo.
 */
const LOCAL_WINDOW_WORDS =
    12;

const LOCAL_WINDOW_STEP =
    5;

const MIN_LOCAL_STAT_WORDS =
    7;

const MIN_LOCAL_STAT_LETTERS =
    34;

/*
 * Para declarar un bloque extranjero basándonos en estadística local exigimos
 * una separación amplia contra español. No basta con que otro código quede #1.
 */
const LOCAL_FOREIGN_MAX_SPANISH_SCORE =
    0.62;

const LOCAL_FOREIGN_MIN_MARGIN =
    0.24;

const LOCAL_FOREIGN_MAX_RULE_SCORE =
    0.34;

/*
 * Una predicción estadística local nunca corta por sí sola. Para
 * considerar un candidato estadístico debe existir apoyo léxico/frasal del
 * mismo idioma y, salvo una oración completa muy clara, debe repetirse en dos
 * bloques locales próximos.
 */
const LOCAL_FOREIGN_MIN_PROFILE_SUPPORT =
    0.12;

const LOCAL_STAT_CONSENSUS_MIN_COUNT =
    2;

const LOCAL_STAT_CONSENSUS_MAX_DISTANCE =
    1;

/*
 * Umbral adicional para una oración completa. Sigue exigiendo apoyo léxico del
 * idioma candidato, pero permite detectar un bloque extranjero delimitado por
 * puntuación sin necesitar dos ventanas.
 */
const SEGMENT_FOREIGN_MAX_SPANISH_SCORE =
    0.38;

const SEGMENT_FOREIGN_MIN_MARGIN =
    0.40;

/*
 * V4.1: fallback conservador para una cláusula extranjera COMPLETA rodeada por español.
 *
 * Es deliberadamente distinto de las ventanas internas:
 * - sólo se aplica a segmentos delimitados por puntuación fuerte;
 * - exige ausencia de estructura española;
 * - exige una cláusula española vecina;
 * - exige que `francAll` prefiera otra lengua latina;
 * - mantiene mínimos independientes de confianza y margen estadístico;
 *   que resultó demasiado conservador para indonesio, vietnamita y suajili.
 *
 * La finalidad es generalizar a lenguas no perfiladas sin agregar diccionarios
 * específicos por idioma.
 */
const GENERIC_BOUNDARY_MIN_WORDS =
    4;

const GENERIC_BOUNDARY_MIN_LETTERS =
    18;

const GENERIC_BOUNDARY_MAX_SPANISH_SCORE =
    0.48;

const GENERIC_BOUNDARY_MIN_FOREIGN_SCORE =
    0.68;

const GENERIC_BOUNDARY_MIN_MARGIN =
    0.18;

const GENERIC_BOUNDARY_MAX_RULE_SCORE =
    0.22;

/*
 * V4.1 — scanner MIXED adaptativo.
 *
 * Confirmar español globalmente no alcanza: un comentario
 * puede ser 90% español y contener una isla extranjera totalmente nueva. V4
 * busca esas islas sin memorizar vocabularios por idioma.
 *
 * Estrategia de costo:
 * - primero puntúa cada token con una firma española barata;
 * - sólo construye/corre francAll sobre ventanas con baja compatibilidad;
 * - combina ventanas 3/4/5/6/8/10 para cubrir microfrases y bloques medianos;
 * - una ventana aislada no condena salvo que sea una cláusula delimitada muy
 *   clara; las ventanas internas necesitan consenso solapado.
 */
const V4_ADAPTIVE_WINDOW_SIZES =
    Object.freeze([
        3,
        4,
        5,
        6,
        8,
        10
    ]);

/*
 * El scanner estadístico no recorre todo el comentario a ciegas. Sólo analiza
 * un número acotado de zonas con baja compatibilidad española.
 */
const V4_MAX_STATISTICAL_WINDOWS =
    40;

const V4_MIN_CANDIDATE_LETTERS =
    10;

const V4_EXPLICIT_MIN_WORDS =
    2;

/*
 * Una cláusula delimitada tiene una frontera real, por eso puede usar un umbral
 * algo menos estricto que una ventana interna. Aun así, francAll nunca corta si
 * la propia cláusula exhibe estructura española suficiente.
 */
const V4_EXPLICIT_MAX_SPANISH_SCORE =
    0.34;

const V4_EXPLICIT_MIN_FOREIGN_SCORE =
    0.72;

const V4_EXPLICIT_MIN_MARGIN =
    0.32;

const V4_TINY_EXPLICIT_MAX_SPANISH_SCORE =
    0.12;

const V4_TINY_EXPLICIT_MIN_FOREIGN_SCORE =
    0.88;

const V4_TINY_EXPLICIT_MIN_MARGIN =
    0.70;


/*
 * Las ventanas internas son mucho más peligrosas: una secuencia española rara,
 * un nombre propio o un término técnico pueden parecer otro idioma. Por eso la
 * evidencia estadística debe ser claramente dominante.
 */
const V4_WINDOW_MAX_SPANISH_SCORE =
    0.30;

const V4_WINDOW_MIN_FOREIGN_SCORE =
    0.74;

const V4_WINDOW_MIN_MARGIN =
    0.38;

const V4_SHORT_WINDOW_MAX_SPANISH_SCORE =
    0.22;

const V4_SHORT_WINDOW_MIN_FOREIGN_SCORE =
    0.80;

const V4_SHORT_WINDOW_MIN_MARGIN =
    0.50;

const V4_MIN_CONSENSUS_OVERLAP =
    0.55;

const V4_MIN_CORE_LOW_SUPPORT_RATIO =
    0.60;

const V4_SINGLE_CORE_MAX_SPANISH_SCORE =
    0.12;

const V4_SINGLE_CORE_MIN_FOREIGN_SCORE =
    0.93;

const V4_SINGLE_CORE_MIN_MARGIN =
    0.80;

const V4_SINGLE_CORE_MIN_LOW_SUPPORT_RATIO =
    0.66;


const V4_MAX_NEUTRAL_TECH_RATIO =
    0.24;

/*
 * V4.1 — segunda vía estadística para islas locales realmente vacías de
 * estructura española. Estos umbrales NO reemplazan a los estrictos: sólo se
 * habilitan cuando la propia capa lingüística local observa casi cero español
 * y existe contexto español claro fuera del candidato.
 */
const V4_RELAXED_EXPLICIT_MIN_WORDS =
    4;

const V4_RELAXED_EXPLICIT_MAX_SPANISH_SCORE =
    0.68;

const V4_RELAXED_EXPLICIT_MIN_FOREIGN_SCORE =
    0.58;

const V4_RELAXED_EXPLICIT_MIN_MARGIN =
    0.10;

const V4_RELAXED_EXPLICIT_MIN_LOW_SUPPORT_RATIO =
    0.72;

const V4_RELAXED_EXPLICIT_MAX_FUNCTION_WORDS =
    1;

const V4_RELAXED_EXPLICIT_MAX_RULE_SCORE =
    0.16;

/*
 * Las cláusulas explícitas de sólo 3 palabras necesitan bastante más señal:
 * con tan poco texto franc puede oscilar con facilidad.
 */
const V4_RELAXED_TINY_MIN_FOREIGN_SCORE =
    0.70;

const V4_RELAXED_TINY_MIN_MARGIN =
    0.22;

const V4_RELAXED_TINY_MAX_SPANISH_SCORE =
    0.56;

/*
 * Candidatos internos para CONSENSO. Una ventana individual que sólo alcance
 * estos umbrales nunca condena por sí sola: necesita otra ventana compatible,
 * solapada y de tamaño distinto.
 */
const V4_CONSENSUS_MAX_SPANISH_SCORE =
    0.62;

const V4_CONSENSUS_MIN_FOREIGN_SCORE =
    0.56;

const V4_CONSENSUS_MIN_MARGIN =
    0.12;

const V4_CONSENSUS_SHORT_MAX_SPANISH_SCORE =
    0.48;

const V4_CONSENSUS_SHORT_MIN_FOREIGN_SCORE =
    0.66;

const V4_CONSENSUS_SHORT_MIN_MARGIN =
    0.24;

const V4_CONSENSUS_MIN_LOW_SUPPORT_RATIO =
    0.74;

const V4_CONSENSUS_MAX_FUNCTION_WORDS =
    0;

/*
 * Un perfil extranjero conocido puede reabrir una cláusula romance cercana
 * que quedó débilmente protegida por palabras funcionales compartidas.
 */
const V4_PROFILE_OVERRIDE_MIN_SUPPORT =
    0.16;

const V4_PROFILE_OVERRIDE_MIN_LOW_SUPPORT_RATIO =
    0.46;


/* ============================================================================
 * 2. VOCABULARIO NEUTRO / INTERNACIONAL
 * ============================================================================ */

/*
 * Estos términos pueden aparecer naturalmente en comentarios españoles y no
 * deben contarse como evidencia extranjera por sí solos.
 */
const NEUTRAL_INTERNATIONAL_WORDS =
    new Set([
        'whatsapp',
        'telegram',
        'instagram',
        'facebook',
        'twitter',
        'x',
        'tiktok',
        'youtube',
        'reddit',
        'google',
        'windows',
        'linux',
        'android',
        'iphone',
        'ios',
        'bitcoin',
        'ethereum',
        'crypto',
        'online',
        'internet',
        'software',
        'hardware',
        'streaming',
        'gaming',
        'marketing',
        'email',
        'e-mail',
        'link',
        'links',
        'web',
        'wifi',
        'wi-fi',
        'app',
        'apps',
        'podcast',
        'chat',
        'blog',
        'startup',
        'stock',
        'stocks',
        'trading',
        'smartphone',
        'smartphones',
        'streamer',
        'streamers',
        'influencer',
        'influencers',
        'api',
        'backend',
        'frontend',
        'dataset',
        'dashboard',
        'bluetooth',
        'gpu',
        'cpu',
        'json',
        'http',
        'https',
        'newsletter',
        'cloud',
        'build',
        'token',
        'tokens'
    ]);


/* ============================================================================
 * 3. EVIDENCIA POSITIVA DE ESPAÑOL
 * ============================================================================ */

/*
 * Léxico de alta utilidad para un portal de noticias.
 *
 * No se usa como diccionario exhaustivo. La finalidad es aportar confirmación
 * positiva en textos cortos y medianos cuando el detector estadístico duda.
 */
const SPANISH_HIGH_PRECISION_WORDS =
    new Set([
        'gracias',
        'excelente',
        'interesante',
        'análisis',
        'analisis',
        'noticia',
        'noticias',
        'nota',
        'artículo',
        'articulo',
        'artículos',
        'articulos',
        'cobertura',
        'informe',
        'informes',
        'contexto',
        'seguimiento',
        'punto',
        'puntos',
        'aporta',
        'aportan',
        'merece',
        'merecen',
        'entiendo',
        'entendí',
        'entendi',
        'veo',
        'distinto',
        'distinta',
        'debatir',
        'explicación',
        'explicacion',
        'información',
        'informacion',
        'publicación',
        'publicacion',
        'actualización',
        'actualizacion',
        'investigación',
        'investigacion',
        'discusión',
        'discusion',
        'opinión',
        'opinion',
        'conclusión',
        'conclusion',
        'posición',
        'posicion',
        'periodista',
        'lectores',
        'fuentes',
        'datos',
        'gráficos',
        'graficos',
        'explicaron',
        'explicado',
        'explicada',
        'publicaron',
        'publicado',
        'publicada',
        'gustó',
        'gusto',
        'gustaría',
        'gustaria',
        'estaría',
        'estaria',
        'sería',
        'seria',
        'habría',
        'habria',
        'podría',
        'podria',
        'debería',
        'deberia',
        'convendría',
        'convendria',
        'faltaría',
        'faltaria',
        'ojalá',
        'ojala',
        'todavía',
        'todavia',
        'también',
        'tambien',
        'acá',
        'aca',
        'capaz',
        'che',
        'coincido',
        'conozco',
        'entiendo',
        'esperaría',
        'esperaria',
        'parece',
        'pienso',
        'creo',
        'bueno',
        'buena',
        'buen',
        'claro',
        'clara',
        'gran',
        'útil',
        'util',
        'tema',
        'temas',
        'trabajo',
        'trabajos',
        'faltan',
        'falta',
        'algunos',
        'algunas',
        'varios',
        'varias',
        'habrá',
        'habra',
        'esperar',
        'todo',
        'planteado',
        'planteada',
        'veremos',

        'documentación', 'documentacion', 'enlazada', 'enlazado', 'contacto',
        'figura', 'aparece', 'menciono', 'documento', 'citado', 'citada',
        'resultados', 'educativos', 'educativas', 'interpretar', 'estuvieran',
        'separados', 'separadas', 'nivel', 'provincia', 'año', 'años', 'media',
        'diferencias', 'metodología', 'metodologia', 'período', 'periodo',
        'comparado', 'comparada', 'revisaría', 'revisaria', 'miraría', 'miraria',
        'recién', 'recien', 'documentos', 'respuesta', 'respondieron', 'actualizan',
        'subió', 'subio', 'confirmó', 'confirmo', 'cambió', 'cambio', 'sirve',
        'necesita', 'queda', 'quedan', 'sobran', 'banco', 'laburo', 'quilombo',
        'zafa', 'recontra', 'toque',
    ]);

/*
 * Palabras funcionales. Como muchas son compartidas con otras lenguas romances,
 * jamás confirman español por sí solas; sólo aportan estructura.
 */
const SPANISH_FUNCTION_WORDS =
    new Set([
        'el',
        'la',
        'los',
        'las',
        'un',
        'una',
        'unos',
        'unas',
        'de',
        'del',
        'al',
        'que',
        'y',
        'o',
        'en',
        'por',
        'para',
        'con',
        'sin',
        'como',
        'se',
        'es',
        'son',
        'fue',
        'era',
        'hay',
        'no',
        'sí',
        'si',
        'muy',
        'más',
        'mas',
        'pero',
        'porque',
        'aunque',
        'cuando',
        'donde',
        'sobre',
        'entre',
        'desde',
        'hasta',
        'según',
        'segun',
        'esto',
        'esta',
        'este',
        'esa',
        'ese',
        'lo',
        'le',
        'les',
        'me',
        'te',
        'nos',
        'su',
        'sus',
        'mi',
        'mis',
        'yo',
        'vos',
        'usted',
        'ustedes',
        'ellos',
        'ellas',
        'estos',
        'estas',
        'esos',
        'esas',
        'aquellos',
        'aquellas',
        'todo',
        'toda',
        'todos',
        'todas',
        'otro',
        'otra',
        'otros',
        'otras',
        'cada',
        'algún',
        'algun',
        'alguna',
        'algunos',
        'algunas',
        'mismo',
        'misma',
        'mismos',
        'mismas',
        'está',
        'estan',
        'están',
        'a',
        'mí',
        'ti'
    ]);

/*
 * Construcciones españolas de alta precisión, especialmente útiles en
 * comentarios de noticias y textos breves.
 */
const SPANISH_PHRASE_PATTERNS =
    Object.freeze([
        /\b(?:la|esta|esa)\s+nota\b/iu,
        /\b(?:el|este|ese)\s+art[ií]culo\b/iu,
        /\b(?:la|esta|esa)\s+noticia\b/iu,
        /\b(?:muy\s+)?buena?\s+(?:nota|noticia|explicaci[oó]n|cobertura|informaci[oó]n)\b/iu,
        /\bbuen\s+art[ií]culo\b/iu,
        /\bexcelente\s+(?:nota|noticia|art[ií]culo|cobertura|informaci[oó]n)\b/iu,
        /\binteresante\s+(?:an[aá]lisis|nota|art[ií]culo|noticia|cobertura)\b/iu,
        /\bgran\s+cobertura\b/iu,
        /\bgracias\s+por\s+(?:informar|publicar|compartir)\b/iu,
        /\bme\s+(?:parece|gust[oó]|gustar[ií]a|interesa)\b/iu,
        /\bcreo\s+que\b/iu,
        /\bpienso\s+que\b/iu,
        /\bpara\s+m[ií]\b/iu,
        /\ba\s+m[ií]\b/iu,
        /\bhay\s+que\b/iu,
        /\bhabr[ií]a\s+que\b/iu,
        /\bestar[ií]a\s+bueno\b/iu,
        /\bser[ií]a\s+(?:bueno|buena|[uú]til|interesante)\b/iu,
        /\bme\s+gustar[ií]a\b/iu,
        /\bno\s+coincido\b/iu,
        /\bde\s+d[oó]nde\b/iu,
        /\bqu[eé]\s+pasa\b/iu,
        /\bqu[eé]\s+tema\b/iu,
        /\bla\s+semana\s+que\s+viene\b/iu,
        /\bsacar\s+conclusiones\b/iu,
        /\bseg[uú]n\s+(?:la|el|los|las)\b/iu,
        /\bde\s+acuerdo\s+con\b/iu,
        /\ba\s+partir\s+de\b/iu,
        /\blos\s+datos\b/iu,
        /\bla\s+informaci[oó]n\b/iu,
        /\bla\s+explicaci[oó]n\b/iu,
        /\b(?:el|la)\s+periodista\b/iu,
        /\blos\s+comentarios\b/iu,
        /\bme\s+parece\s+importante\b/iu,
        /\bno\s+estoy\s+(?:de\s+acuerdo|recomendando)\b/iu,
        /\btodav[ií]a\s+(?:falta|quedan|no)\b/iu,
        /\bser[ií]a\s+bueno\s+(?:agregar|ampliar|comparar|conocer)\b/iu,
        /\b(?:el|la)\s+(?:informe|tema|art[ií]culo|nota)\s+(?:est[aá]|es|merece|aporta)\b/iu,
        /\b(?:el|la)\s+tema\s+merece\s+seguimiento\b/iu,
        /\b(?:la|esta)\s+publicaci[oó]n\s+aporta\s+contexto\b/iu,
        /\byo\s+lo\s+(?:veo|entiendo)\b/iu,
        /\bentiendo\s+el\s+punto\b/iu,
        /\bpuntos\s+interesantes\s+para\s+debatir\b/iu,
        /\bmerece\s+seguimiento\b/iu
    ]);

/*
 * Voseo y coloquialismos rioplatenses. Tienen mucho valor en TRAMA porque el
 * portal está orientado a usuarios argentinos.
 */
/*
 * Gramática de alta precisión para comentarios españoles breves.
 *
 * Esta capa evita depender de n-gramas estadísticos en frases de 2–5 palabras.
 * No memoriza comentarios concretos: reconoce estructuras productivas del
 * español (determinante + sustantivo, perífrasis, concordancia y construcciones
 * muy frecuentes de comentario).
 */
const SHORT_SPANISH_FAMILY_ABUSE_PATTERN =
    /\bhij[oa]s?\s*(?:d\s*e?|de)\s*(?:remil\s*)?(?:idiot[ao]s?|imbecil(?:es)?|inutil(?:es)?|estupid[ao]s?|tarad[ao]s?|pelotud[ao]s?|bolud[ao]s?|salames?|burr[ao]s?|payas[ao]s?|cretin[ao]s?|tont[oa]s?|bob[oa]s?|patetic[ao]s?|forr[oa]s?|soretes?|gil(?:a|es|as)?|nab[oa]s?|mamert[oa]s?|pajer[oa]s?|cagon(?:a|es|as)?|garcas?|chantas?|chorr[oa]s?|caretas?|lacras?|ratas?|ranci[oa]s?|basura|asqueros[oa]s?|repugnantes?|culiad[oa]s?|maricon(?:a|es|as)?|trol[oa]s?|mogolic[oa]s?|retardad[oa]s?|conchud[oa]s?|cornud[oa]s?|cagador(?:a|es|as)?|putas?|putos?|p\s*(?:u\s*)?t[ao]s?)\b/iu;

const SHORT_SPANISH_GRAMMAR_PATTERNS =
    Object.freeze([
        /\b(?:buen|buena|excelente|gran|interesante)\s+(?:trabajo|tema|nota|noticia|art[ií]culo|cobertura|an[aá]lisis|explicaci[oó]n|informaci[oó]n|informe)\b/iu,
        /\b(?:tema|trabajo|nota|noticia|art[ií]culo|cobertura|an[aá]lisis|informaci[oó]n|explicaci[oó]n)\s+(?:interesante|claro|clara|[uú]til|bueno|buena)\b/iu,
        /\b(?:muy|tan|bastante)\s+(?:claro|clara|[uú]til|interesante|bueno|buena|bien)\b/iu,
        /\bfaltan?\s+(?:(?:algunos?|algunas?|varios?|varias?|m[aá]s)\s+)?(?:datos|fuentes|detalles|elementos|antecedentes|informaci[oó]n)\b/iu,
        /\bhabr[aá]\s+que\s+\p{L}+(?:ar|er|ir)\b/iu,
        /\b(?:hay|habr[ií]a)\s+que\s+\p{L}+(?:ar|er|ir)\b/iu,
        /\b(?:est[aá]|estuvo|qued[oó]|parece)\s+(?:muy\s+)?(?:claro|clara|bien|bueno|buena)\b/iu,
        /\bno\s+s[eé]\s+si\b/iu,
        /\best[aá]\s+bien\s+plantead[oa]\b/iu,
        /\bno\s+da\s+para\b/iu,
        /\bpor\s+ahora\b/iu,
        /\bveremos\s+c[oó]mo\s+sigue\b/iu,
        /\b(?:gracias|bien|claro|correcto)\s+(?:por\s+\p{L}+|todo)\b/iu,
        SHORT_SPANISH_FAMILY_ABUSE_PATTERN
    ]);

/*
 * Construcciones copulativas breves de alta precisión.
 *
 * Corrigen un hueco concreto del verificador de texto corto: frases plenamente
 * españolas como:
 *
 *     "esto es un análisis"
 *     "eso fue una explicación"
 *     "aquello era una noticia"
 *
 * podían quedar UNKNOWN porque `es`, `fue` o `era` son palabras funcionales,
 * pero no se contaban como una firma verbal suficiente dentro de la capa corta.
 *
 * IMPORTANTE: el patrón NO confirma español por sí solo. Más abajo se exige
 * además una palabra distintiva española y al menos dos palabras funcionales.
 * De esta forma no convertimos expresiones mixtas como "esto es a test" o
 * fragmentos extranjeros que casualmente contengan una forma copulativa en
 * español en falsos positivos.
 */
const SHORT_SPANISH_COPULAR_PATTERNS =
    Object.freeze([
        /\b(?:esto|eso|aquello)\s+(?:es|era|fue|ser[aá]|ser[ií]a|est[aá]|estaba)\b/iu,
        /\b(?:estos|estas|esos|esas|aquellos|aquellas)\s+(?:son|eran|fueron|ser[aá]n|ser[ií]an|est[aá]n|estaban)\b/iu
    ]);


/*
 * Voseo y coloquialismos rioplatenses. Tienen mucho valor en TRAMA porque el
 * portal está orientado a usuarios argentinos.
 */
const RIOPLATENSE_PATTERNS =
    Object.freeze([
        /\bche\b/iu,
        /\bac[aá]\b/iu,
        /\bcapaz\b/iu,
        /\bpara\s+m[ií]\b/iu,
        /\best[aá]\s+bueno\b/iu,
        /\b(?:ten[eé]s|quer[eé]s|pod[eé]s|sab[eé]s|hac[eé]s|dec[ií]s|ven[ií]s)\b/iu,
        /\b(?:mir[aá]|fijate|decime|contame|pasame|dejame)\b/iu
    ]);

/*
 * Morfología española de apoyo. Es deliberadamente secundaria porque varias
 * lenguas romances comparten terminaciones similares.
 */
const SPANISH_MORPHOLOGY_PATTERNS =
    Object.freeze([
        /(?:ci[oó]n|ciones)$/iu,
        /(?:ar[ií]a|er[ií]a|ir[ií]a)$/iu,
        /(?:ar[ií]amos|er[ií]amos|ir[ií]amos)$/iu,
        /(?:aron|ieron)$/iu,
        /(?:ando|iendo)$/iu,
        /(?:ado|ada|ados|adas|ido|ida|idos|idas)$/iu,
        /(?:aba|aban|[aá]bamos)$/iu,
        /(?:aste|iste)$/iu,
        /(?:dad|dades)$/iu,
        /(?:mente)$/iu
    ]);


/* ============================================================================
 * 3.B VERIFICADOR ESTRUCTURAL DE ESPAÑOL — V4
 * ============================================================================ */

/*
 * Palabras con alto valor para confirmar estructura española real. El objetivo
 * no es crear un diccionario exhaustivo: estas piezas cubren pronombres,
 * interrogativos, formas verbales, conectores y vocabulario periodístico que
 * permite reconocer construcciones productivas sin memorizar frases de prueba.
 */
const SPANISH_DISTINCTIVE_WORDS =
    new Set([
        'qué', 'que', 'quién', 'quien', 'quiénes', 'quienes', 'cuándo',
        'cuando', 'dónde', 'donde', 'cómo', 'como', 'cuál', 'cual', 'cuáles',
        'cuales', 'cuánto', 'cuanto', 'cuánta', 'cuanta', 'cuántos', 'cuantos',
        'cuántas', 'cuantas', 'porqué', 'porque', 'aunque', 'mientras',
        'después', 'despues', 'antes', 'recién', 'recien', 'todavía', 'todavia',
        'también', 'tambien', 'además', 'ademas', 'quizás', 'quizas', 'ojalá',
        'ojala', 'conviene', 'convendría', 'convendria', 'habría', 'habria',
        'habrá', 'habra', 'quisiera', 'quiero', 'queremos', 'puede', 'pueden',
        'podría', 'podria', 'debería', 'deberia', 'deberían', 'deberian',
        'sería', 'seria', 'serían', 'serian', 'estaría', 'estaria', 'estarían',
        'estarian', 'estuviera', 'estuvieran', 'fuera', 'fueran', 'figura',
        'aparece', 'aparecen', 'menciono', 'menciona', 'mencionan', 'revisar',
        'revisaría', 'revisaria', 'revisarla', 'revisarlo', 'comparar',
        'comparado', 'comparada', 'separado', 'separados', 'separada',
        'interpretar', 'interpretación', 'interpretacion', 'entender',
        'explicar', 'aclarar', 'confirmar', 'esperar', 'publicar', 'publicado',
        'publicada', 'citado', 'citada', 'documento', 'documentos',
        'documentación', 'documentacion', 'metodología', 'metodologia',
        'resultados', 'provincia', 'provincias', 'período', 'periodo',
        'períodos', 'periodos', 'nivel', 'niveles', 'año', 'años', 'números',
        'numeros', 'educativos', 'educativas', 'media', 'general', 'diferencias',
        'fuente', 'fuentes', 'contacto', 'enlazada', 'enlazado', 'completa',
        'completo', 'discutir', 'lectura', 'versión', 'version', 'actualización',
        'actualizacion', 'conclusión', 'conclusion', 'datos', 'información',
        'informacion', 'contexto', 'explicación', 'explicacion', 'nota',
        'noticia', 'artículo', 'articulo', 'informe', 'cobertura', 'publicación',
        'publicacion', 'tema', 'análisis', 'analisis', 'claro', 'clara',
        'completo', 'completa', 'útil', 'util', 'interesante', 'convincente',
        'importante', 'importantes', 'dudas', 'preguntas', 'respuesta',
        'respuestas', 'actualizar', 'cambia', 'cambió', 'cambio', 'subió', 'subio',
        'bajó', 'bajo', 'confirmó', 'confirmo', 'publicó', 'publico', 'alcanzan',
        'alcanza', 'sirve', 'necesita', 'merece', 'queda', 'quedan', 'faltan',
        'falta', 'sobran', 'sigue', 'siguen', 'banco', 'laburo', 'quilombo',
        'zafa', 'recontra', 'capaz', 'acá', 'aca', 'che', 'toque', 'cierra',
        'mirá', 'mira', 'fijate', 'leé', 'lee', 'pensá', 'pensa', 'revisá',
        'revisa', 'esperá', 'espera', 'tené', 'tene', 'buscá', 'busca', 'compará',
        'compara', 'prestá', 'presta', 'volvé', 'volve', 'usa', 'usan', 'funciona',
        'funcionan', 'suele', 'suelen', 'muestra', 'muestran', 'conserva',
        'conservan', 'explica', 'explican', 'permite', 'permiten'
    ]);

/* Interrogativos que aportan una firma especialmente fuerte en texto breve. */
const SPANISH_INTERROGATIVE_WORDS =
    new Set([
        'qué', 'que', 'quién', 'quien', 'quiénes', 'quienes', 'cuándo',
        'cuando', 'dónde', 'donde', 'cómo', 'como', 'cuál', 'cual', 'cuáles',
        'cuales', 'cuánto', 'cuanto', 'cuánta', 'cuanta', 'cuántos', 'cuantos',
        'cuántas', 'cuantas'
    ]);

/* Adverbios y adjetivos frecuentes en respuestas breves de español natural. */
const SPANISH_SHORT_ADVERBS =
    new Set([
        'muy', 'bastante', 'realmente', 'demasiado', 'poco', 'bien', 'mal',
        'todavía', 'todavia', 'ahora', 'recién', 'recien', 'igual', 'acá', 'aca'
    ]);

const SPANISH_SHORT_ADJECTIVES =
    new Set([
        'claro', 'clara', 'completo', 'completa', 'útil', 'util', 'interesante',
        'convincente', 'correcto', 'correcta', 'lógico', 'logico', 'lógica',
        'logica', 'discutible', 'fundamentado', 'fundamentada', 'complicado',
        'complicada', 'importante', 'rápido', 'rapido', 'rápida', 'rapida',
        'clarísimo', 'clarisimo', 'clarísima', 'clarisima', 'apurado', 'apurada',
        'planteado', 'planteada', 'explicado', 'explicada'
    ]);

/*
 * Formas verbales frecuentes que, combinadas con estructura española, permiten
 * confirmar frases cortas sin obligar a `franc` a acertar.
 */
const SPANISH_SHORT_VERBS =
    new Set([
        'hay', 'habrá', 'habra', 'habría', 'habria', 'conviene', 'convendría',
        'convendria', 'falta', 'faltan', 'sobra', 'sobran', 'queda', 'quedan',
        'sirve', 'sirven', 'aporta', 'aportan', 'tiene', 'tienen', 'deja', 'dejan',
        'necesita', 'necesitan',
        'merece', 'merecen', 'parece', 'parecen', 'resulta', 'resultan',
        'quiero', 'queremos', 'puede', 'pueden', 'cambia', 'cambió', 'cambio',
        'subió', 'subio', 'bajó', 'bajo', 'confirmó', 'confirmo', 'publicó',
        'publico', 'respondieron', 'actualizan', 'actualizaron', 'entiendo',
        'entiende', 'entienden', 'revisaría', 'revisaria', 'miraría', 'miraria',
        'esperaría', 'esperaria', 'cerraría', 'cerraria', 'alcanzan', 'alcanza',
        'mirá', 'mira', 'fijate', 'leé', 'lee', 'pensá', 'pensa', 'revisá',
        'revisa', 'esperá', 'espera', 'tené', 'tene', 'buscá', 'busca', 'compará',
        'compara', 'prestá', 'presta', 'volvé', 'volve', 'usa', 'usan', 'funciona',
        'funcionan', 'suele', 'suelen', 'muestra', 'muestran', 'conserva',
        'conservan', 'explica', 'explican', 'permite', 'permiten'
    ]);

/*
 * Secuencias sintácticas productivas. Son deliberadamente generales: describen
 * orden y morfología del español, no comentarios concretos de las baterías.
 */
const SPANISH_STRUCTURAL_PATTERNS =
    Object.freeze([
        /\b(?:el|la|los|las|un|una|unos|unas)\s+\p{L}+(?:\s+\p{L}+){0,3}\s+(?:es|son|est[aá]|est[aá]n|fue|eran|parece|parecen|puede|pueden|debe|deben|tiene|tienen|figura|aparece|aparecen|sirve|cambia|muestra|muestran|ayuda|ayudan)\b/iu,
        /\b(?:que|porque|aunque|cuando|mientras|si)\s+(?:se\s+)?\p{L}+(?:aron|ieron|aba|aban|[aá]n|en|a|e|ó|o)\b/iu,
        /\b(?:ser[ií]a|habr[ií]a|estar[ií]a|convendr[ií]a|podr[ií]a|deber[ií]a|faltar[ií]a)\s+\p{L}+/iu,
        /\b(?:los|las)\s+\p{L}+\s+(?:que|de|del|con|por|para|pueden|deben|est[aá]n|fueron|ser[ií]an)\b/iu,
        /\b(?:me|te|se|nos|lo|la|los|las|le|les)\s+\p{L}+(?:o|a|e|an|en|ó|aron|ieron)\b/iu,
        /\b(?:antes|despu[eé]s|todav[ií]a|reci[eé]n|tambi[eé]n|adem[aá]s)\s+(?:de\s+)?\p{L}+/iu,
        /\b(?:por|para|con|sin|desde|hasta)\s+(?:el|la|los|las|un|una|este|esta|ese|esa)\s+\p{L}+/iu,
        /\b(?:mir[aá]|fijate|le[eé]|pens[aá]|revis[aá]|esper[aá]|ten[eé]|busc[aá]|compar[aá]|prest[aá]|volv[eé])\b/iu,
        /\b(?:no\s+)?me\s+(?:cierra|parece|interesa|gust[oó]|falt[oó])\b/iu,
        /\b(?:para\s+m[ií]|a\s+m[ií]|no\s+s[eé]|hay\s+que|habr[aá]\s+que)\b/iu,
        /\bhay\s+(?:algo|mucho|poco|varios|varias|un|una)\b/iu
    ]);

/* Terminaciones verbales de apoyo; nunca confirman español por sí solas. */
const SPANISH_VERB_SIGNATURES =
    Object.freeze([
        /(?:ar[ií]a|er[ií]a|ir[ií]a|ar[ií]an|er[ií]an|ir[ií]an)$/iu,
        /(?:ara|iera|ieran|aran)$/iu,
        /(?:aron|ieron|aban|[aá]bamos|[ií]amos)$/iu,
        /(?:aste|iste|asteis|isteis)$/iu,
        /(?:emos|amos)$/iu,
        /(?:[óo]|[aá]|[eé])$/iu
    ]);

/*
 * Expresiones rioplatenses/coloquiales que no deben convertirse en UNKNOWN por
 * ser breves o no aparecer en diccionarios estadísticos formales.
 */
const SPANISH_COLLOQUIAL_PATTERNS =
    Object.freeze([
        /\b(?:che|ac[aá]|capaz|recontra|laburo|quilombo|zafa)\b/iu,
        /\bbanco\s+que\b/iu,
        /\bno\s+me\s+cierra\b/iu,
        /\bun\s+toque\b/iu,
        /\bpara\s+m[ií]\b/iu,
        /\b(?:ta|est[aá])\s+(?:bueno|buena|bien|claro|clara)\b/iu,
        /\b(?:ni\s+idea|a\s+ver|ojo\s+que)\b/iu,
        /\b(?:eh|igual)\s*$/iu
    ]);


/* ============================================================================
 * 4. EVIDENCIA EXTRANJERA CONTRASTIVA
 * ============================================================================ */

/*
 * Cada perfil distingue:
 *
 * - hard: una aparición ya es una señal fuerte y específica;
 * - soft: se necesitan varias coincidencias o apoyo estadístico;
 * - phrases: construcciones de varias palabras de alta precisión;
 * - morphology: patrones que sólo aportan soporte, nunca corte aislado.
 *
 * Esta capa es especialmente importante para separar español de portugués,
 * gallego y catalán, donde la cercanía estadística puede ser engañosa.
 */
/*
 * Tokens de code-switching prácticamente incompatibles con un comentario
 * enteramente español. Esta capa es deliberadamente pequeña y de alta
 * precisión: una coincidencia puede cortar incluso un comentario corto.
 *
 * No contiene préstamos internacionales aceptados (software, WhatsApp, etc.)
 * ni palabras compartidas entre lenguas romances.
 */
const FOREIGN_EXCLUSIVE_TOKENS =
    Object.freeze({
        eng: new Set([
            'but', 'very', 'thanks', 'thank', 'please', 'because', 'should',
            'would', 'could', 'with', 'from', 'about', 'your', 'yours',
            'their', 'theirs', 'written', 'clearly', 'actually', 'anyway',
            'however', 'maybe', 'sorry', 'really', 'wrong', 'unclear', 'enough'
        ]),

        por: new Set([
            'faltam', 'português', 'portugues', 'informações', 'informacoes',
            'vocês', 'voces', 'obrigado', 'obrigada', 'jornalista', 'acrescentar',
            'porém', 'porem', 'talvez', 'mesmo', 'ainda', 'também', 'tambem',
            'veja', 'matéria', 'materia', 'não', 'nao', 'reportagem', 'ainda', 'agora', 'mesmo', 'úteis',
            'uteis', 'gostaria', 'fonte', 'tirar', 'conclusão', 'conclusao', 'fatos',
            'uma', 'linha', 'ajudaria', 'publiquem', 'houver', 'novos'
        ]),

        glg: new Set([
            'grazas', 'xornalista', 'xornal', 'traballo', 'paréceme', 'pareceme',
            'engadir', 'achega', 'galego', 'galega', 'unha', 'aínda', 'máis',
            'mellor', 'feitos', 'orixinal', 'conclusións', 'conclusions',
            'cronoloxía', 'cronoloxia', 'axudaría', 'axudaria', 'agardo', 'haxa',
            'novos', 'reportaxe', 'tamén', 'tamen', 'quizais', 'cómpre', 'compre',
            'gustaríame', 'gustariame', 'coñecer', 'conecer', 'resposta', 'non', 'coa',
            'análise', 'analise'
        ]),

        cat: new Set([
            'gràcies', 'gracies', 'aquest', 'aquesta', 'aquests', 'aquestes',
            'hauria', 'afegir', 'dades', 'encara', 'però', 'potser', 'també',
            'més', 'millor', 'cal', 'xifres', 'fets', 'fonts'
        ]),

        ita: new Set([
            'grazie', 'questo', 'questa', 'giornalista', 'articolo', 'notizia',
            'dovrebbe', 'pubblicato', 'mancano', 'ancora', 'vorrei', 'vedere',
            'fonte', 'resta', 'chiara', 'sono', 'spiegazione', 'migliore'
        ]),

        fra: new Set([
            'merci', 'beaucoup', 'journaliste', 'actualité', 'actualite',
            'données', 'donnees', 'devrait', 'je', 'voudrais', 'consulter',
            'avant', 'faudrait', 'chiffres', 'chronologie', 'aiderait', 'manque',
            'encore', 'détails', 'details'
        ]),

        deu: new Set([
            'danke', 'nicht', 'zeitung', 'nachricht', 'daten', 'sollte',
            'veröffentlichung', 'informationen', 'fehlen', 'quellen'
        ]),

        nld: new Set([
            'bedankt', 'deze', 'gegevens', 'nieuws', 'duidelijk', 'geschreven',
            'bijwerken'
        ]),

        pol: new Set([
            'dziękuję', 'dziekuje', 'artykuł', 'artykul', 'dziennikarz',
            'powinien', 'wiadomość', 'wiadomosc'
        ]),

        tur: new Set([
            'teşekkür', 'tesekkur', 'makale', 'gazeteci', 'yayımladığınız',
            'yayimladiginiz', 'güncellemek', 'guncellemek', 'hala', 'bilgi', 'eksik'
        ]),

        ron: new Set([
            'mulțumesc', 'multumesc', 'informații', 'informatii', 'jurnalistul',
            'știre', 'stire', 'săptămâna', 'saptamana', 'lipsește', 'lipseste',
            'inca', 'lipsesc', 'cateva', 'articolul', 'nevoie', 'sunt', 'trebui',
            'explicata', 'aceste'
        ]),

        eus: new Set([
            'eskerrik', 'kazetariak', 'gehitu', 'albiste', 'datorren',
            'oraindik', 'argitaratzeagatik'
        ]),

        swe: new Set([
            'tack', 'journalisten', 'uppgifter', 'nyheten', 'nästa', 'nasta',
            'saknas'
        ]),

        nor: new Set([
            'takk', 'journalisten', 'opplysninger', 'nyheten', 'neste',
            'mangler'
        ]),

        dan: new Set([
            'offentliggøre', 'offentliggore', 'oplysninger', 'journalisten',
            'nyhed', 'mangler'
        ]),

        fin: new Set([
            'kiitos', 'toimittajan', 'tietoja', 'uutinen', 'viikolla',
            'puuttuu'
        ]),

        ces: new Set([
            'děkuji', 'dekuji', 'zveřejnění', 'zverejneni', 'novinář',
            'novinar', 'údajů', 'udaju', 'zpráva', 'zprava'
        ]),

        hun: new Set([
            'köszönöm', 'koszonom', 'újságírónak', 'ujsagironak', 'adatot',
            'jövő', 'jovo', 'héten', 'heten', 'hiányzik', 'hianyzik'
        ])
    });


/*
 * Construcciones contrastivas que distinguen especialmente español de
 * portugués, gallego, catalán e inglés. Son patrones generativos, no frases de
 * la batería.
 */
const FOREIGN_CONTRASTIVE_GRAMMAR =
    Object.freeze({
        eng: [
            /\bbut\s+\p{L}+/iu,
            /\bvery\s+\p{L}+/iu,
            /\b(?:this|that)\s+(?:article|news|report|part)\b/iu,
            /\b(?:thanks?|please)\b/iu,
            /\b(?:check|read|see)\s+(?:the|this|that)\b/iu,
            /\bnot\s+enough\b/iu,
            /\bstill\s+(?:unclear|missing|wrong)\b/iu,
            /\bneeds?\s+(?:more\s+)?(?:context|data|sources?|details?)\b/iu,
            /\bi\s+(?:think|disagree|agree|would|need|want)\b/iu,
            /\b(?:la|el|esto|yo|fuente|dato|explicaci[oó]n|nota)\s+(?:is|was|looks|seems|needs|would|should|think)\b/iu
        ],

        por: [
            /\b(?:mas\s+)?faltam\s+(?:dados|informa[cç][oõ]es|detalhes)\b/iu,
            /\best[aá]\s+escrita\s+em\s+portugu[eê]s\b/iu,
            /\bem\s+portugu[eê]s\b/iu,
            /\b(?:esta|essa)\s+parte\s+est[aá]\s+escrita\s+em\b/iu,
            /\bn[aã]o\s+concordo\b/iu,
            /\bmuito\s+obrigad[oa]\b/iu,
            /\bveja\s+a\s+fonte\b/iu,
            /\bobrigad[oa]\s+pela\s+mat[eé]ria\b/iu,
            /\bmuito\s+claro\b/iu,
            /\besses\s+n[uú]meros\b/iu,
            /\bcom\s+os\s+do\s+ano\s+passado\b/iu,
            /\bseria\s+interessante\s+comparar\b/iu,
            /\b(?:a\s+reportagem|gostaria\s+de|uma\s+linha|os\s+n[uú]meros|os\s+anos|os\s+fatos|das\s+informa[cç][oõ]es|quando\s+houver|publiquem\s+uma)\b/iu
        ],

        glg: [
            /\best[aá]\s+escrita\s+en\s+galego\b/iu,
            /\bpar[eé]ceme\s+que\b/iu,
            /\bo\s+xornalista\b/iu,
            /\bm[aá]is\s+datos\b/iu,
            /\b(?:unha|a[ií]nda|m[aá]is|mellor|feitos|orixinal|agardo|haxa|novos)\b/iu,
            /\b(?:fonte\s+orixinal|sacar\s+conclusi[oó]ns|unha\s+cronolox[ií]a)\b/iu,
            /\bhai\b(?:\s+\p{L}+){0,5}\s+\b(?:seguen|sen)\b/iu,
            /\bquero\b(?:\s+\p{L}+){0,5}\s+\b(?:cifras|rexistros|orixinais)\b/iu
        ],

        cat: [
            /\best[aà]\s+escrita\s+en\s+catal[aà]\b/iu,
            /\bhauria\s+d['’]?\s*afegir\b/iu,
            /\bmés\s+dades\b/iu,
            /\baquesta\s+(?:not[ií]cia|part)\b/iu,
            /\b(?:encara|potser|cal)\s+\p{L}+/iu,
            /\bm\s+agradaria\s+llegir\b/iu,
            /\bno\s+estic\s+del\s+tot\s+d\s+acord\b/iu,
            /\bquan\s+apareguin\b/iu,
            /\bllegir\s+una\s+actualitzaci[oó]\b/iu,
            /\b(?:aquest|aquesta|més|per[òó]|gr[aà]cies|xifres|fets|fonts)\b/iu,
            /\b(?:una\s+)?altra\s+\p{L}+(?:\s+\p{L}+){0,3}\s+canviar\b/iu,
            /\binterpretaci[oó]\s+del\s+resultat\b/iu
        ],

        ron: [
            /\bmul[tț]umesc\s+pentru\b/iu,
            /\baceste\s+informa[tț]ii\b/iu,
            /\baceast[aă]\s+(?:știre|stire)\b/iu,
            /\b(?:inca\s+lipsesc|articolul\s+are\s+nevoie|nu\s+sunt\s+de\s+acord|ar\s+trebui)\b/iu
        ],

        ita: [
            /\bmancano\s+ancora\b/iu,
            /\bvorrei\s+vedere\b/iu,
            /\bquesta\s+parte\b/iu,
            /\bresta\s+poco\s+chiara\b/iu
        ],

        fra: [
            /\bil\s+faut\b/iu,
            /\bje\s+voudrais\b/iu,
            /\bcette\s+partie\b/iu,
            /\bplus\s+de\s+d[eé]tails\b/iu,
            /\b(?:avant\s+de|faudrait\s+comparer|ces\s+chiffres)\b/iu
        ]
    });


const FOREIGN_PROFILES =
    Object.freeze({
        eng: {
            hard: new Set([
                'thanks', 'thank', 'please', 'because', 'should', 'would',
                'could', 'fucking', 'fuck', 'stupid', 'kill', 'journalist',
                'written', 'clearly', 'interesting', 'information'
            ]),
            soft: new Set([
                'the', 'this', 'that', 'these', 'those', 'but', 'you', 'your',
                'we', 'our', 'they', 'their', 'are', 'were', 'was', 'will',
                'with', 'from', 'about', 'very', 'article', 'news', 'report',
                'more', 'needs', 'need', 'wrong', 'agree', 'update', 'actually',
                'anyway', 'however', 'maybe', 'sorry', 'really', 'still', 'check',
                'source', 'enough', 'think', 'looks', 'seems', 'is', 'not'
            ]),
            phrases: [
                /\bthis\s+(?:article|news|report)\b/iu,
                /\bthanks?\s+for\b/iu,
                /\bi\s+(?:think|do|agree|disagree)\b/iu,
                /\bshould\s+(?:add|provide|explain|include)\b/iu,
                /\bmore\s+(?:data|context|information)\b/iu,
                /\bvery\s+(?:interesting|clear|good)\b/iu
            ],
            morphology: [
                /(?:ing|ness|ment|tion)$/iu
            ]
        },

        por: {
            hard: new Set([
                'você', 'voce', 'vocês', 'voces', 'obrigado', 'obrigada',
                'muito', 'muita', 'muitos', 'muitas', 'não', 'nao', 'acho',
                'deveria', 'matéria', 'materia', 'informações', 'informacoes',
                'jornalista', 'jornal', 'acrescentar', 'publicação', 'publicacao',
                'reportagem', 'ainda', 'agora', 'mesmo', 'úteis', 'uteis', 'gostaria', 'fonte', 'tirar',
                'conclusão', 'conclusao', 'fatos', 'uma', 'linha', 'ajudaria',
                'publiquem', 'houver', 'novos'
            ]),
            soft: new Set([
                'isso', 'isto', 'pra', 'também', 'tambem', 'trabalho', 'bom',
                'boa', 'bem', 'mais', 'dados', 'artigo', 'notícia', 'noticia',
                'seria', 'semana'
            ]),
            phrases: [
                /\bmuito\s+(?:bem|boa|bom|interessante)\b/iu,
                /\bobrigad[oa]\s+por\b/iu,
                /\bacho\s+que\b/iu,
                /\bn[aã]o\s+(?:concordo|acho|estou)\b/iu,
                /\bdeveria\s+(?:explicar|acrescentar|adicionar)\b/iu,
                /\bveja\s+a\s+fonte\b/iu,
                /\bfaltam\s+dados\b/iu,
                /\bobrigad[oa]\s+pela\b/iu,
                /\ba\s+reportagem\b/iu,
                /\bgostaria\s+de\b/iu,
                /\buma\s+linha\b/iu,
                /\bos\s+n[uú]meros\b/iu,
                /\bos\s+anos\b/iu,
                /\bdas\s+informa[cç][oõ]es\b/iu,
                /\bquando\s+houver\b/iu,
                /\bpubliquem\s+uma\b/iu
            ],
            morphology: [
                /(?:ç[aã]o|ções|[aã]o)$/iu,
                /(?:nh|lh)/iu
            ]
        },

        glg: {
            hard: new Set([
                'grazas', 'xornalista', 'xornal', 'traballo', 'traballos',
                'galego', 'galega', 'galegos', 'galegas', 'paréceme',
                'pareceme', 'engadir', 'achega', 'achegar', 'fixo', 'máis',
                'mais', 'moi', 'unha', 'aínda', 'mellor', 'feitos', 'orixinal',
                'conclusións', 'cronoloxía', 'axudaría', 'agardo', 'haxa', 'novos',
                'reportaxe', 'tamén', 'tamen', 'quizais', 'cómpre', 'compre',
                'gustaríame', 'gustariame', 'coñecer', 'resposta', 'non', 'coa',
                'análise', 'analise'
            ]),
            soft: new Set([
                'moito', 'moita', 'moitos', 'moitas', 'novas', 'bo', 'boa',
                'ben'
            ]),
            phrases: [
                /\bgrazas\s+por\b/iu,
                /\bpar[eé]ceme\s+(?:unha?|que)\b/iu,
                /\bxornalista\s+deber[ií]a\b/iu,
                /\bm[aá]is\s+(?:datos|informaci[oó]n)\b/iu,
                /\bbo\s+traballo\b/iu,
                /\bfonte\s+orixinal\b/iu,
                /\bsacar\s+conclusi[oó]ns\b/iu,
                /\bunha\s+cronolox[ií]a\b/iu,
                /\ba\s+noticia\s+deber[ií]a\b/iu,
                /\bagardo\s+que\b/iu,
                /\bcos\s+anos\s+anteriores\b/iu,
                /\b(?:c[oó]mpre\s+revisar|gustar[ií]ame\s+co[nñ]ecer|non\s+concordo|coa\s+an[aá]lise)\b/iu,
                /\bestas\s+cifras\s+cos\b/iu
            ],
            morphology: [
                /(?:xornal|galeg|par[eé]ceme)/iu
            ]
        },

        cat: {
            hard: new Set([
                'gràcies', 'gracies', 'aquest', 'aquesta', 'aquests', 'aquestes',
                'això', 'aixo', 'hauria', 'afegir', 'dades', 'més', 'però',
                'peró', 'notícia', 'article'
            ]),
            soft: new Set([
                'amb', 'dels', 'molt', 'informació'
            ]),
            phrases: [
                /\bgr[aà]cies\s+per\b/iu,
                /\baquest(?:a|es|s)?\s+(?:article|not[ií]cia)\b/iu,
                /\bhauria\s+d['’]?\s*afegir\b/iu,
                /\bmés\s+dades\b/iu,
                /\bafegir\s+més\b/iu,
                /\bencara\s+falten\b/iu,
                /\bcal\s+(?:revisar|comparar|esperar)\b/iu,
                /\bno\s+estic\s+d['’]?\s*acord\b/iu
            ],
            morphology: [
                /(?:ment|ció|acions)$/iu
            ]
        },

        ita: {
            hard: new Set([
                'grazie', 'questo', 'questa', 'molto', 'giornalista', 'notizia',
                'articolo', 'dovrebbe', 'dati', 'scritto', 'pubblicato', 'utile',
                'prossima', 'sono', 'spiegazione', 'migliore', 'mancano', 'ancora',
                'vorrei', 'vedere'
            ]),
            soft: new Set([
                'più', 'perché', 'perche', 'informazioni', 'analisi', 'settimana'
            ]),
            phrases: [
                /\bgrazie\s+per\b/iu,
                /\bquest[oa]\s+(?:articolo|notizia)\b/iu,
                /\bdovrebbe\s+(?:aggiungere|spiegare)\b/iu,
                /\bpi[uù]\s+dati\b/iu,
                /\bmancano\s+ancora\b/iu,
                /\bvorrei\s+vedere\b/iu,
                /\bquesta\s+parte\b/iu,
                /\bnon\s+sono\b/iu,
                /\bspiegazione\s+migliore\b/iu
            ],
            morphology: [
                /(?:zione|zioni|mente)$/iu
            ]
        },

        fra: {
            hard: new Set([
                'merci', 'beaucoup', 'avec', 'très', 'journaliste',
                'actualité', 'actualite', 'cette', 'ceci', 'données', 'donnees',
                'devrait', 'publié', 'publie', 'semaine', 'je', 'voudrais',
                'consulter', 'avant', 'faudrait', 'chiffres', 'chronologie',
                'aiderait', 'manque', 'encore', 'détails', 'details'
            ]),
            soft: new Set([
                'article', 'information', 'plus', 'analyse', 'utile'
            ]),
            phrases: [
                /\bmerci\s+(?:beaucoup|pour)\b/iu,
                /\bcette\s+(?:actualit[eé]|information)\b/iu,
                /\ble\s+journaliste\b/iu,
                /\bdevrait\s+(?:ajouter|expliquer)\b/iu,
                /\bplus\s+de\s+donn[eé]es\b/iu,
                /\bje\s+voudrais\b/iu,
                /\bil\s+faudrait\b/iu,
                /\bces\s+chiffres\b/iu,
                /\bune\s+chronologie\b/iu,
                /\bavant\s+de\b/iu
            ],
            morphology: [
                /(?:eaux|aux|ique|ement)$/iu
            ]
        },

        deu: {
            hard: new Set([
                'danke', 'bitte', 'nicht', 'zeitung', 'nachricht', 'daten',
                'sollte', 'journalist', 'veröffentlichung', 'informationen', 'fehlen', 'quellen'
            ]),
            soft: new Set([
                'der', 'die', 'das', 'und', 'ist', 'sehr', 'mehr', 'artikel',
                'woche', 'gut'
            ]),
            phrases: [
                /\bdanke\s+f[uü]r\b/iu,
                /\bdies(?:er|e|es)\s+artikel\b/iu,
                /\bmehr\s+daten\b/iu,
                /\bder\s+journalist\b/iu
            ],
            morphology: [
                /(?:ung|keit|lich|isch)$/iu
            ]
        },

        nld: {
            hard: new Set([
                'bedankt', 'deze', 'gegevens', 'nieuws', 'duidelijk', 'geschreven',
                'journalist', 'bijwerken'
            ]),
            soft: new Set([
                'het', 'een', 'meer', 'moeten', 'artikel', 'volgende', 'week'
            ]),
            phrases: [
                /\bbedankt\s+voor\b/iu,
                /\bdeze\s+(?:informatie|nieuws)\b/iu,
                /\bmeer\s+gegevens\b/iu
            ],
            morphology: [
                /(?:lijk|heid|ing)$/iu
            ]
        },

        pol: {
            hard: new Set([
                'dziękuję', 'dziekuje', 'artykuł', 'artykul', 'dziennikarz',
                'powinien', 'wiadomość', 'wiadomosc', 'danych', 'tygodniu'
            ]),
            soft: new Set([
                'ten', 'bardzo', 'jest', 'dobrze', 'więcej', 'wiecej'
            ]),
            phrases: [
                /\bdzi[eę]kuj[eę]\s+za\b/iu,
                /\bten\s+artyku[lł]\b/iu,
                /\bwi[eę]cej\s+danych\b/iu
            ],
            morphology: [
                /(?:owy|owa|owe|anie)$/iu
            ]
        },

        tur: {
            hard: new Set([
                'teşekkür', 'tesekkur', 'makale', 'gazeteci', 'yayımladığınız',
                'yayimladiginiz', 'haber', 'güncellemek', 'guncellemek', 'hala', 'bilgi', 'eksik'
            ]),
            soft: new Set([
                'çok', 'cok', 'daha', 'için', 'icin', 'yararlı', 'yararli',
                'veri'
            ]),
            phrases: [
                /\bte[sş]ekk[uü]r\s+ederim\b/iu,
                /\bbu\s+makale\b/iu,
                /\bdaha\s+fazla\s+veri\b/iu
            ],
            morphology: [
                /(?:mek|mak|lar|ler)$/iu
            ]
        },

        ron: {
            hard: new Set([
                'mulțumesc', 'multumesc', 'jurnalistul', 'știre', 'stire',
                'informații', 'informatii', 'săptămâna', 'saptamana',
                'lipsește', 'lipseste'
            ]),
            soft: new Set([
                'acest', 'această', 'aceasta', 'aceste', 'foarte', 'mai',
                'multe', 'articol'
            ]),
            phrases: [
                /\bmul[tț]umesc\s+pentru\b/iu,
                /\baceste\s+informa[tț]ii\b/iu,
                /\bacest(?:a|ă)\s+articol\b/iu
            ],
            morphology: [
                /(?:ului|elor|esc)$/iu
            ]
        },

        eus: {
            hard: new Set([
                'eskerrik', 'kazetariak', 'gehitu', 'albiste', 'datorren',
                'oraindik', 'argitaratzeagatik'
            ]),
            soft: new Set([
                'hau', 'oso', 'datu', 'gehiago', 'behar'
            ]),
            phrases: [
                /\beskerrik\s+asko\b/iu,
                /\bdatu\s+gehiago\b/iu
            ],
            morphology: [
                /(?:ak|ek|aren)$/iu
            ]
        },

        swe: {
            hard: new Set([
                'tack', 'journalisten', 'uppgifter', 'nyheten', 'nästa',
                'nasta', 'saknas'
            ]),
            soft: new Set([
                'den', 'här', 'har', 'är', 'mer', 'vecka'
            ]),
            phrases: [
                /\btack\s+f[oö]r\b/iu,
                /\bden\s+h[aä]r\b/iu
            ],
            morphology: [
                /(?:het|ande)$/iu
            ]
        },

        nor: {
            hard: new Set([
                'takk', 'journalisten', 'opplysninger', 'nyheten', 'neste',
                'mangler'
            ]),
            soft: new Set([
                'denne', 'svært', 'svaert', 'mer', 'uke'
            ]),
            phrases: [
                /\btakk\s+for\b/iu,
                /\bdenne\s+artikkelen\b/iu
            ],
            morphology: [
                /(?:heten|ene)$/iu
            ]
        },

        dan: {
            hard: new Set([
                'offentliggøre', 'offentliggore', 'oplysninger', 'journalisten',
                'nyhed', 'mangler'
            ]),
            soft: new Set([
                'denne', 'meget', 'flere', 'uge'
            ]),
            phrases: [
                /\btak\s+for\b/iu,
                /\bdenne\s+artikel\b/iu
            ],
            morphology: [
                /(?:hed|ende)$/iu
            ]
        },

        fin: {
            hard: new Set([
                'kiitos', 'toimittajan', 'tietoja', 'uutinen', 'viikolla',
                'puuttuu'
            ]),
            soft: new Set([
                'tämä', 'tama', 'enemmän', 'enemman', 'hyvä', 'hyva'
            ]),
            phrases: [
                /\bkiitos\b/iu,
                /\btietoja\s+puuttuu\b/iu
            ],
            morphology: [
                /(?:ssa|sta|lla|minen)$/iu
            ]
        },

        ces: {
            hard: new Set([
                'děkuji', 'dekuji', 'zveřejnění', 'zverejneni', 'novinář',
                'novinar', 'údajů', 'udaju', 'zpráva', 'zprava'
            ]),
            soft: new Set([
                'tento', 'velmi', 'více', 'vice', 'týden', 'tyden'
            ]),
            phrases: [
                /\bd[eě]kuji\s+za\b/iu,
                /\btento\s+[cč]l[aá]nek\b/iu
            ],
            morphology: [
                /(?:ost|ovat|ého)$/iu
            ]
        },

        hun: {
            hard: new Set([
                'köszönöm', 'koszonom', 'újságírónak', 'ujsagironak',
                'adatot', 'jövő', 'jovo', 'héten', 'heten', 'hiányzik',
                'hianyzik'
            ]),
            soft: new Set([
                'nagyon', 'több', 'tobb', 'cikk'
            ]),
            phrases: [
                /\bk[oö]sz[oö]n[oö]m\b/iu,
                /\bt[oö]bb\s+adatot\b/iu
            ],
            morphology: [
                /(?:nak|nek|ban|ben)$/iu
            ]
        }
    });

const ENGLISH_CONTRACTION_PATTERN =
    /\b(?:don['’]t|doesn['’]t|didn['’]t|isn['’]t|aren['’]t|wasn['’]t|weren['’]t|you['’]re|you['’]ve|you['’]ll|we['’]re|we['’]ve|they['’]re|they['’]ve|it['’]s|that['’]s|there['’]s|can['’]t|won['’]t|wouldn['’]t|shouldn['’]t|couldn['’]t)\b/iu;


/* ============================================================================
 * 5. ALFABETOS INCOMPATIBLES CON ESPAÑOL
 * ============================================================================ */

/*
 * Español utiliza escritura latina. Una letra real perteneciente a estos
 * alfabetos constituye evidencia suficiente para cortar, aunque el comentario
 * tenga también texto español.
 *
 * Emojis, números y símbolos no intervienen.
 */
const FOREIGN_SCRIPT_PATTERN =
    /(?:\p{Script=Cyrillic}|\p{Script=Greek}|\p{Script=Arabic}|\p{Script=Hebrew}|\p{Script=Devanagari}|\p{Script=Bengali}|\p{Script=Gurmukhi}|\p{Script=Gujarati}|\p{Script=Tamil}|\p{Script=Telugu}|\p{Script=Kannada}|\p{Script=Malayalam}|\p{Script=Thai}|\p{Script=Georgian}|\p{Script=Armenian}|\p{Script=Hangul}|\p{Script=Han}|\p{Script=Hiragana}|\p{Script=Katakana}|\p{Script=Ethiopic})/u;


/* ============================================================================
 * 6. NORMALIZACIÓN Y TOKENIZACIÓN
 * ============================================================================ */

/**
 * Normaliza Unicode y espacios sin quitar tildes, ñ ni signos lingüísticos.
 *
 * @param {unknown} value
 * @returns {string}
 */
function normalizeText(value) {
    return String(
        value
        ?? ''
    )
        .normalize('NFKC')
        .replace(
            /[\u200B-\u200F\u202A-\u202E\u2060-\u206F\uFEFF]/gu,
            ''
        )
        .replace(/\s+/gu, ' ')
        .trim();
}


/**
 * Quita contenido que no debería dominar la identificación lingüística.
 *
 * @param {string} text
 * @returns {string}
 */
/**
 * Colapsa elongaciones expresivas de tres o más letras iguales para el análisis
 * lingüístico, sin modificar el comentario original ni repeticiones normales de
 * dos letras.
 *
 * Ejemplos:
 *     interesanteee -> interesante
 *     muyyy         -> muy
 *     buenísimaaa   -> buenísima
 *
 * @param {string} text
 * @returns {string}
 */
function normalizeExpressiveElongations(text) {
    return String(text)
        .replace(
            /(\p{L})\1{2,}/giu,
            '$1'
        );
}


/**
 * Elimina elementos que no deberían decidir el idioma.
 *
 * @param {string} text
 * @returns {string}
 */
function removeLanguageNoise(text) {
    return String(text)
        .replace(/\bhttps?:\/\/[^\s]+/giu, ' ')
        .replace(/\bwww\.[^\s]+/giu, ' ')
        .replace(/\b[\w.+-]+@[\w.-]+\.[a-z]{2,}\b/giu, ' ')
        .replace(/(?:^|\s)@[a-z0-9_.-]+/giu, ' ')
        .replace(/(?:^|\s)#[\p{L}\p{N}_-]+/gu, ' ')
        .replace(/\b(?=[A-Z0-9_-]{8,}\b)(?=[A-Z0-9_-]*[0-9_-])[A-Z0-9_-]+\b/g, ' ')
        .replace(/\s+/gu, ' ')
        .trim();
}


/**
 * Extrae palabras Unicode preservando apóstrofes e hiphen internos.
 *
 * @param {string} text
 * @returns {string[]}
 */
function extractWords(text) {
    return (
        String(text)
            .toLocaleLowerCase('es')
            .match(
                /\p{L}+(?:['’\-]\p{L}+)*/gu
            )
        ?? []
    );
}


/**
 * Cuenta letras Unicode reales.
 *
 * @param {string} text
 * @returns {number}
 */
function countLetters(text) {
    return (
        String(text)
            .match(/\p{L}/gu)
        ?? []
    ).length;
}


/**
 * Indica si una palabra pertenece al conjunto de términos internacionales que
 * TRAMA considera neutros para la detección de idioma.
 *
 * @param {string} word
 * @returns {boolean}
 */
function isNeutralInternationalWord(word) {
    return NEUTRAL_INTERNATIONAL_WORDS.has(
        String(word)
            .toLocaleLowerCase('es')
    );
}


/**
 * Elimina del análisis estadístico términos internacionales neutros.
 *
 * @param {string[]} words
 * @returns {string[]}
 */
function getInformativeWords(words) {
    return words.filter(
        (word) =>
            !isNeutralInternationalWord(
                word
            )
    );
}


/**
 * Cuenta coincidencias exactas contra un Set.
 *
 * @param {string[]} words
 * @param {Set<string>} dictionary
 * @returns {number}
 */
function countDictionaryMatches(
    words,
    dictionary
) {
    let count = 0;

    for (const word of words) {
        if (dictionary.has(word)) {
            count += 1;
        }
    }

    return count;
}


/**
 * Cuenta patrones que aparecen al menos una vez en el texto.
 *
 * @param {string} text
 * @param {RegExp[]} patterns
 * @returns {number}
 */
function countPatternHits(
    text,
    patterns
) {
    let count = 0;

    for (const pattern of patterns) {
        if (pattern.test(text)) {
            count += 1;
        }
    }

    return count;
}


/* ============================================================================
 * 7. ANALIZADOR POSITIVO DE ESPAÑOL
 * ============================================================================ */

/**
 * Calcula evidencia española independiente de franc.
 *
 * La puntuación usa funciones saturadas por cantidad absoluta. En esta arquitectura, un comentario largo no pierde evidencia sólo porque aumente el
 * denominador de palabras.
 *
 * @param {string} text
 * @param {string[]} words
 * @returns {object}
 */
function analyzeSpanishEvidence(
    text,
    words
) {
    const informativeWords =
        getInformativeWords(
            words
        );

    const strongWords =
        countDictionaryMatches(
            informativeWords,
            SPANISH_HIGH_PRECISION_WORDS
        );

    const functionWords =
        countDictionaryMatches(
            informativeWords,
            SPANISH_FUNCTION_WORDS
        );

    const phraseHits =
        countPatternHits(
            text,
            SPANISH_PHRASE_PATTERNS
        );

    const rioplatenseHits =
        countPatternHits(
            text,
            RIOPLATENSE_PATTERNS
        );

    const shortGrammarHits =
        countPatternHits(
            text,
            SHORT_SPANISH_GRAMMAR_PATTERNS
        );

    let morphologyHits =
        0;

    for (const word of informativeWords) {
        if (word.length < 5) {
            continue;
        }

        for (
            const pattern
            of SPANISH_MORPHOLOGY_PATTERNS
        ) {
            if (pattern.test(word)) {
                morphologyHits += 1;
                break;
            }
        }
    }

    const invertedPunctuation =
        /[¿¡]/u.test(
            text
        );

    const enyeCount =
        (
            text.match(/[ñÑ]/gu)
            ?? []
        ).length;

    const accentedSpanishCount =
        (
            text.match(/[áéíóúÁÉÍÓÚ]/gu)
            ?? []
        ).length;

    const strongComponent =
        Math.min(
            1,
            strongWords / 3
        );

    const functionComponent =
        Math.min(
            1,
            functionWords / 5
        );

    const phraseComponent =
        Math.min(
            1,
            phraseHits / 2
        );

    const rioplatenseComponent =
        Math.min(
            1,
            rioplatenseHits / 2
        );

    const shortGrammarComponent =
        Math.min(
            1,
            shortGrammarHits / 2
        );

    const morphologyComponent =
        Math.min(
            1,
            morphologyHits / 4
        );

    const orthographyComponent =
        Math.min(
            1,
            (
                (invertedPunctuation ? 0.55 : 0)
                + Math.min(0.30, enyeCount * 0.15)
                + Math.min(0.25, accentedSpanishCount * 0.05)
            )
        );

    const score =
        Math.min(
            1,
            (
                strongComponent * 0.30
                + functionComponent * 0.18
                + phraseComponent * 0.24
                + rioplatenseComponent * 0.09
                + shortGrammarComponent * 0.16
                + morphologyComponent * 0.09
                + orthographyComponent * 0.08
            )
        );

    /*
     * Confirmaciones de alta precisión para textos breves y coloquiales.
     * Se exige más de una clase de evidencia cuando el léxico por sí solo es
     * compartible con otras lenguas romances.
     */
    const directConfirmation =
        (
            shortGrammarHits >= 1
            && (
                strongWords >= 1
                || functionWords >= 1
                || words.length <= 5
            )
        )
        || (
            phraseHits >= 1
            && (
                strongWords >= 1
                || functionWords >= 2
                || rioplatenseHits >= 1
            )
        )
        || (
            strongWords >= 2
            && functionWords >= 1
        )
        || (
            strongWords >= 2
            && words.length <= 4
        )
        || (
            rioplatenseHits >= 1
            && (
                strongWords >= 1
                || functionWords >= 2
            )
        )
        || (
            invertedPunctuation
            && phraseHits >= 1
        );

    return {
        score,
        direct_confirmation:
            directConfirmation,
        strong_words:
            strongWords,
        function_words:
            functionWords,
        phrase_hits:
            phraseHits,
        rioplatense_hits:
            rioplatenseHits,
        short_grammar_hits:
            shortGrammarHits,
        morphology_hits:
            morphologyHits,
        inverted_punctuation:
            invertedPunctuation,
        enye_count:
            enyeCount,
        accented_count:
            accentedSpanishCount,
        informative_words:
            informativeWords.length,
        total_words:
            words.length
    };
}



/* ============================================================================
 * 7.B ANÁLISIS ESTRUCTURAL Y ESPAÑOL CORTO — V4
 * ============================================================================ */

/**
 * Cuenta palabras distintas presentes en un Set.
 *
 * @param {string[]} words
 * @param {Set<string>} dictionary
 * @returns {number}
 */
function countDistinctDictionaryMatches(
    words,
    dictionary
) {
    const matches =
        new Set();

    for (const word of words) {
        if (dictionary.has(word)) {
            matches.add(word);
        }
    }

    return matches.size;
}


/**
 * Calcula firmas verbales españolas sin convertir una terminación aislada en
 * prueba de idioma.
 *
 * @param {string[]} words
 * @returns {number}
 */
function countSpanishVerbSignatures(words) {
    let hits =
        0;

    for (const word of words) {
        if (
            SPANISH_SHORT_VERBS.has(word)
        ) {
            hits +=
                1;
            continue;
        }

        if (word.length < 5) {
            continue;
        }

        for (
            const pattern
            of SPANISH_VERB_SIGNATURES
        ) {
            if (pattern.test(word)) {
                hits +=
                    1;
                break;
            }
        }
    }

    return hits;
}


/**
 * Analiza estructura española productiva. Esta capa es independiente de franc
 * y complementa el léxico de `analyzeSpanishEvidence()`.
 *
 * @param {string} text
 * @param {string[]} words
 * @returns {object}
 */
function analyzeSpanishStructure(
    text,
    words
) {
    const informativeWords =
        getInformativeWords(
            words
        );

    const wordCount =
        informativeWords.length;

    const distinctiveWords =
        countDistinctDictionaryMatches(
            informativeWords,
            SPANISH_DISTINCTIVE_WORDS
        );

    const functionWords =
        countDictionaryMatches(
            informativeWords,
            SPANISH_FUNCTION_WORDS
        );

    const distinctFunctionWords =
        countDistinctDictionaryMatches(
            informativeWords,
            SPANISH_FUNCTION_WORDS
        );

    const structuralHits =
        countPatternHits(
            text,
            SPANISH_STRUCTURAL_PATTERNS
        );

    const colloquialHits =
        countPatternHits(
            text,
            SPANISH_COLLOQUIAL_PATTERNS
        );

    /*
     * Detecta únicamente la forma estructural demostrativo + verbo copulativo.
     * Todavía NO confirma idioma: la validación léxica se realiza después.
     */
    const shortCopularHits =
        countPatternHits(
            text,
            SHORT_SPANISH_COPULAR_PATTERNS
        );

    const verbHits =
        countSpanishVerbSignatures(
            informativeWords
        );

    const interrogativeHits =
        informativeWords.filter(
            (word) =>
                SPANISH_INTERROGATIVE_WORDS.has(word)
        ).length;

    const hasInvertedQuestion =
        /¿/u.test(text);

    const hasInvertedExclamation =
        /¡/u.test(text);

    const shortAdverbCount =
        countDictionaryMatches(
            informativeWords,
            SPANISH_SHORT_ADVERBS
        );

    const shortAdjectiveCount =
        countDictionaryMatches(
            informativeWords,
            SPANISH_SHORT_ADJECTIVES
        );

    const functionDensity =
        wordCount > 0
            ? functionWords / wordCount
            : 0;

    const distinctiveDensity =
        wordCount > 0
            ? distinctiveWords / wordCount
            : 0;

    const questionStructure =
        (
            hasInvertedQuestion
            || interrogativeHits >= 1
        )
        && (
            verbHits >= 1
            || distinctiveWords >= 1
            || functionWords >= 2
        );

    const voseoStructure =
        countPatternHits(
            text,
            RIOPLATENSE_PATTERNS
        ) >= 1
        && (
            verbHits >= 1
            || distinctiveWords >= 1
            || functionWords >= 1
        );

    const shortPredicateStructure =
        wordCount <= 6
        && (
            (
                shortAdverbCount >= 1
                && shortAdjectiveCount >= 1
            )
            || (
                verbHits >= 1
                && (
                    distinctiveWords >= 1
                    || functionWords >= 1
                )
            )
            || (
                distinctiveWords >= 2
                && distinctFunctionWords >= 1
            )
        );

    /*
     * Confirma construcciones copulativas cortas sólo cuando la forma sintáctica
     * viene acompañada por evidencia léxica española real.
     *
     * Ejemplo válido:
     *
     *     esto / es / un / análisis
     *       ^      ^          ^
     *     función  cópula   palabra distintiva
     *
     * Requisitos deliberadamente conservadores:
     * - máximo 8 palabras informativas;
     * - al menos un patrón copulativo explícito;
     * - al menos una palabra distintiva española;
     * - al menos dos palabras funcionales españolas.
     *
     * La evidencia extranjera fuerte se evalúa antes de esta función dentro de
     * detectCommentLanguage(), por lo que una mezcla inequívoca sigue cortando.
     */
    const shortCopularStructure =
        wordCount <= 8
        && shortCopularHits >= 1
        && distinctiveWords >= 1
        && functionWords >= 2;

    const mediumStructure =
        (
            structuralHits >= 2
            && (
                distinctiveWords >= 2
                || distinctFunctionWords >= 4
            )
        )
        || (
            verbHits >= 2
            && distinctiveWords >= 2
            && functionWords >= 3
        )
        || (
            distinctiveWords >= 4
            && functionWords >= 4
        )
        || (
            wordCount >= 8
            && functionDensity >= 0.24
            && distinctiveWords >= 2
            && verbHits >= 1
        )
        || (
            wordCount <= 16
            && structuralHits >= 1
            && verbHits >= 1
            && functionDensity >= 0.34
            && distinctFunctionWords >= 3
        );

    const shortConfirmation =
        questionStructure
        || voseoStructure
        || shortPredicateStructure
        || shortCopularStructure
        || (
            colloquialHits >= 1
            && (
                distinctiveWords >= 1
                || functionWords >= 2
            )
        )
        || (
            wordCount <= 5
            && distinctiveWords >= 2
        )
        || (
            wordCount <= 10
            && structuralHits >= 1
            && verbHits >= 1
            && functionWords >= 2
        );

    return {
        word_count:
            wordCount,
        distinctive_words:
            distinctiveWords,
        function_words:
            functionWords,
        distinct_function_words:
            distinctFunctionWords,
        structural_hits:
            structuralHits,
        colloquial_hits:
            colloquialHits,
        verb_hits:
            verbHits,
        interrogative_hits:
            interrogativeHits,
        inverted_question:
            hasInvertedQuestion,
        inverted_exclamation:
            hasInvertedExclamation,
        short_adverbs:
            shortAdverbCount,
        short_adjectives:
            shortAdjectiveCount,
        function_density:
            functionDensity,
        distinctive_density:
            distinctiveDensity,
        question_structure:
            questionStructure,
        voseo_structure:
            voseoStructure,
        short_predicate_structure:
            shortPredicateStructure,
        short_copular_hits:
            shortCopularHits,
        short_copular_structure:
            shortCopularStructure,
        short_confirmation:
            shortConfirmation,
        medium_confirmation:
            mediumStructure
    };
}


/**
 * Quita numeración/viñetas de un fragmento sin borrar sus palabras.
 *
 * @param {string} text
 * @returns {string}
 */
function stripStructuredPrefix(text) {
    return String(text)
        .replace(
            /^\s*(?:[-*•–—]|\d{1,3}[.)]|[a-z][.)])\s*/iu,
            ''
        )
        .trim();
}


/**
 * Produce cláusulas cortas para detectar code-switching. A diferencia de las
 * ventanas estadísticas, aquí sí usamos coma, punto y coma, dos puntos,
 * paréntesis y comillas porque una microfrase extranjera suele aparecer allí.
 * Cada cláusula se valida con reglas fuertes antes de usar estadística.
 *
 * @param {string} text
 * @returns {string[]}
 */
function splitMicroClauses(text) {
    return String(text)
        .replace(/[()\[\]{}"“”«»]/gu, ' | ')
        .split(
            /\s*(?:[.!?;:]|,|\n|\|)\s*/gu
        )
        .map(
            (part) =>
                stripStructuredPrefix(part)
        )
        .filter(Boolean);
}


/**
 * Indica si un candidato estadístico pertenece a una lengua latina ampliada
 * que no posee necesariamente un perfil manual detallado.
 *
 * @param {string|null} language
 * @returns {boolean}
 */
function isGenericLatinForeignLanguage(language) {
    return Boolean(
        language
        && language !== SPANISH_CODE
        && LATIN_STATISTICAL_CANDIDATES.includes(
            language
        )
    );
}


/**
 * Scanner de mezcla mínima y bloques latinos desconocidos.
 *
 * Reglas de seguridad contra falsos positivos:
 * - 1 token sólo corta si es exclusivo de alta precisión;
 * - 2–3 tokens requieren regla/perfil extranjero;
 * - el fallback estadístico genérico exige >=4 palabras, español estructural
 *   prácticamente ausente y una ventaja estadística muy grande;
 * - fragmentos de listas españoles quedan protegidos por estructura propia.
 *
 * @param {string} text
 * @param {string[]} fullWords
 * @returns {object|null}
 */
function isConfidentSpanishClause(text) {
    const words =
        extractWords(
            text
        );

    if (words.length < 2) {
        return false;
    }

    const structure =
        analyzeSpanishStructure(
            text,
            words
        );

    if (
        structure.short_confirmation
        || structure.medium_confirmation
        || (
            structure.distinctive_words >= 2
            && structure.function_words >= 1
        )
    ) {
        return true;
    }

    const evidence =
        analyzeSpanishEvidence(
            text,
            words
        );

    return Boolean(
        evidence.direct_confirmation
        || evidence.score >= 0.38
    );
}


/**
 * Evalúa un segmento delimitado por puntuación fuerte como posible cláusula
 * extranjera genérica.
 *
 * En segmentos con frontera fuerte se puede usar estadística local con
 * margen estadístico porque la frontera lingüística ya está respaldada por
 * contexto: el fragmento tiene entidad propia y está junto a español claro.
 *
 * @param {string} clause
 * @param {number} clauseIndex
 * @param {string[]} strongClauses
 * @returns {object|null}
 */
function detectGenericBoundaryForeignClause(
    clause,
    clauseIndex,
    strongClauses
) {
    const clauseWords =
        extractWords(
            clause
        );

    if (
        clauseWords.length < GENERIC_BOUNDARY_MIN_WORDS
        || countLetters(
            clause
        ) < GENERIC_BOUNDARY_MIN_LETTERS
    ) {
        return null;
    }

    const spanishStructure =
        analyzeSpanishStructure(
            clause,
            clauseWords
        );

    if (
        spanishStructure.short_confirmation
        || spanishStructure.medium_confirmation
        || (
            spanishStructure.distinctive_words >= 2
            && spanishStructure.function_words >= 1
        )
    ) {
        return null;
    }

    const spanishEvidence =
        analyzeSpanishEvidence(
            clause,
            clauseWords
        );

    if (
        spanishEvidence.direct_confirmation
        || spanishEvidence.score
            >= GENERIC_BOUNDARY_MAX_RULE_SCORE
    ) {
        return null;
    }

    const previousClause =
        strongClauses[
            clauseIndex - 1
        ]
        ?? '';

    const nextClause =
        strongClauses[
            clauseIndex + 1
        ]
        ?? '';

    const hasSpanishNeighbor =
        isConfidentSpanishClause(
            previousClause
        )
        || isConfidentSpanishClause(
            nextClause
        );

    if (!hasSpanishNeighbor) {
        return null;
    }

    const statisticalEvidence =
        analyzeStatisticalEvidence(
            clause,
            clauseWords
        );

    if (!statisticalEvidence.available) {
        return null;
    }

    const candidateLanguage =
        statisticalEvidence.best_foreign_language
        ?? statisticalEvidence.top_language;

    const spanishScore =
        Number(
            statisticalEvidence.spanish_score
            ?? 0
        );

    const foreignScore =
        Number(
            statisticalEvidence.best_foreign_score
            ?? 0
        );

    const margin =
        foreignScore
        - spanishScore;

    if (
        isGenericLatinForeignLanguage(
            candidateLanguage
        )
        && statisticalEvidence.top_language
            !== SPANISH_CODE
        && spanishScore
            <= GENERIC_BOUNDARY_MAX_SPANISH_SCORE
        && foreignScore
            >= GENERIC_BOUNDARY_MIN_FOREIGN_SCORE
        && margin
            >= GENERIC_BOUNDARY_MIN_MARGIN
        && spanishStructure.distinctive_words <= 1
        && spanishStructure.function_density < 0.24
    ) {
        return {
            kind:
                'generic_foreign_clause',
            mode:
                'statistical_boundary',
            language:
                candidateLanguage,
            clause,
            clause_index:
                clauseIndex,
            spanish_score:
                spanishScore,
            foreign_score:
                foreignScore,
            margin,
            spanish_neighbor:
                true
        };
    }

    return null;
}


/**
 * Devuelve una compatibilidad española BARATA para un token individual.
 *
 * No intenta clasificar la palabra. Sólo sirve para decidir si vale la pena
 * pagar una llamada estadística sobre la zona que la contiene.
 *
 * @param {string} word
 * @returns {number}
 */
function getV4SpanishTokenCompatibility(word) {
    const token =
        String(word)
            .toLocaleLowerCase('es');

    if (!token) {
        return 0;
    }

    /*
     * Un término internacional es NEUTRO, no extranjero. Para el prefiltrado
     * barato le damos soporte alto únicamente para impedir que cree por sí solo
     * una falsa "isla" estadística. No se usa para confirmar español global.
     */
    if (isNeutralInternationalWord(token)) {
        return 0.82;
    }

    if (
        SPANISH_DISTINCTIVE_WORDS.has(token)
        || SPANISH_HIGH_PRECISION_WORDS.has(token)
        || SPANISH_SHORT_VERBS.has(token)
        || SPANISH_INTERROGATIVE_WORDS.has(token)
        || SPANISH_SHORT_ADVERBS.has(token)
        || SPANISH_SHORT_ADJECTIVES.has(token)
    ) {
        return 1;
    }

    if (SPANISH_FUNCTION_WORDS.has(token)) {
        return 0.50;
    }

    /*
     * Si el token contiene letras latinas que no pertenecen al alfabeto
     * ortográfico habitual del español, no se premia por compartir además una
     * vocal como `ó`. Ejemplo: `źródeł` contiene `ó`, pero también `ź` y `ł`.
     */
    if (
        !/^[a-záéíóúüñ]+(?:['’\-][a-záéíóúüñ]+)*$/iu.test(
            token
        )
    ) {
        return 0;
    }

    /*
     * Tildes propias del español y ñ son una señal barata útil. No confirman el
     * idioma por sí mismas; sólo evitan mandar demasiadas palabras claramente
     * españolas al detector estadístico local.
     */
    if (/[ñáéíóú]/iu.test(token)) {
        /*
         * Una tilde compatible con español es sólo una señal DÉBIL: húngaro,
         * portugués y otras lenguas también usan varios de estos signos. Las
         * palabras españolas realmente claras ya fueron capturadas por léxico,
         * función o morfología antes de llegar aquí.
         */
        return 0.28;
    }

    if (token.length >= 5) {
        for (const pattern of SPANISH_MORPHOLOGY_PATTERNS) {
            if (pattern.test(token)) {
                return 0.42;
            }
        }

        /*
         * Plurales nominales/adjetivales comunes reciben sólo apoyo DÉBIL.
         * No confirman español; sirven para que una secuencia como
         * determinante + sustantivo + adjetivo no se convierta automáticamente
         * en una ventana extranjera por desconocimiento léxico.
         */
        if (/(?:os|as|es)$/iu.test(token)) {
            return 0.22;
        }
    }

    return 0;
}


/**
 * Umbral de prefiltrado según longitud de ventana.
 *
 * Este valor sólo decide si vale la pena consultar francAll. Una ventana que
 * supera este filtro todavía necesita evidencia estadística fuerte y consenso.
 *
 * @param {number} size
 * @returns {number}
 */
function getV4CheapSupportLimit(size) {
    if (size <= 3) {
        return 0.26;
    }

    if (size <= 4) {
        return 0.28;
    }

    if (size <= 6) {
        return 0.30;
    }

    return 0.32;
}


/**
 * Indica si dos códigos estadísticos pueden representar la misma isla.
 *
 * Se evita el consenso indiscriminado de la V4 inicial. franc puede oscilar en
 * familias muy cercanas (por ejemplo ind/zlm o dan/nor/swe), pero una ventana
 * "slv" y otra "ind" no constituyen corroboración entre sí.
 *
 * @param {string|null} left
 * @param {string|null} right
 * @returns {boolean}
 */
function areV4ForeignLanguagesCompatible(
    left,
    right
) {
    if (
        !left
        || !right
        || left === SPANISH_CODE
        || right === SPANISH_CODE
    ) {
        return false;
    }

    if (left === right) {
        return true;
    }

    const compatibleGroups =
        [
            new Set([
                'ind',
                'zlm'
            ]),
            new Set([
                'swe',
                'nor',
                'dan'
            ]),
            new Set([
                'ces',
                'slk',
                'slv'
            ])
        ];

    return compatibleGroups.some(
        (group) =>
            group.has(left)
            && group.has(right)
    );
}


/**
 * Resume cuánta forma española REAL conserva un candidato local.
 *
 * Esta métrica no clasifica idioma. Sólo distingue entre:
 * - una frase española rara que todavía mantiene función/sintaxis/morfología;
 * - una isla en la que prácticamente desaparece toda señal española.
 *
 * @param {string} text
 * @param {string[]} words
 * @param {object} spanishStructure
 * @param {object} spanishEvidence
 * @returns {object}
 */
function getV4LocalSpanishShape(
    text,
    words,
    spanishStructure,
    spanishEvidence
) {
    const informativeWords =
        getInformativeWords(
            words
        );

    const compatibility =
        informativeWords.map(
            (word) =>
                getV4SpanishTokenCompatibility(
                    word
                )
        );

    const lowSupportWords =
        compatibility.filter(
            (value) =>
                value <= 0.10
        ).length;

    const weakOrLowerWords =
        compatibility.filter(
            (value) =>
                value <= 0.28
        ).length;

    const neutralTechnicalWords =
        informativeWords.filter(
            (word) =>
                isNeutralInternationalWord(
                    String(word)
                        .toLocaleLowerCase('es')
                )
        ).length;

    const spanishFunctionWords =
        informativeWords.filter(
            (word) =>
                SPANISH_FUNCTION_WORDS.has(
                    String(word)
                        .toLocaleLowerCase('es')
                )
        ).length;

    /*
     * Morfología española relativamente concreta para proteger núcleos internos
     * como `diferenciar denuncia prueba`. No reutilizamos la firma verbal amplia
     * (que acepta casi cualquier vocal final) porque sería demasiado permisiva.
     */
    const clearSpanishMorphWords =
        informativeWords.filter(
            (word) => {
                const token =
                    String(word)
                        .toLocaleLowerCase('es');

                return (
                    SPANISH_SHORT_VERBS.has(
                        token
                    )
                    || /(?:ar|ando|iendo|ados?|adas?|idos?|idas?)$/iu.test(
                        token
                    )
                );
            }
        ).length;

    const supportScore =
        compatibility.reduce(
            (total, value) =>
                total + value,
            0
        );

    const tokenCount =
        Math.max(
            1,
            informativeWords.length
        );

    const lowSupportRatio =
        lowSupportWords
        / tokenCount;

    const weakOrLowerRatio =
        weakOrLowerWords
        / tokenCount;

    const averageSupport =
        supportScore
        / tokenCount;

    const structurallyVoid =
        !spanishStructure.question_structure
        && !spanishStructure.voseo_structure
        && !spanishStructure.medium_confirmation
        && Number(
            spanishStructure.structural_hits
            ?? 0
        ) === 0
        && Number(
            spanishStructure.distinctive_words
            ?? 0
        ) <= 1
        && Number(
            spanishEvidence.phrase_hits
            ?? 0
        ) === 0
        && Number(
            spanishEvidence.rioplatense_hits
            ?? 0
        ) === 0
        && Number(
            spanishEvidence.short_grammar_hits
            ?? 0
        ) === 0;

    const almostNoSpanish =
        structurallyVoid
        && !spanishEvidence.direct_confirmation
        && Number(
            spanishEvidence.score
            ?? 1
        ) <= V4_RELAXED_EXPLICIT_MAX_RULE_SCORE
        && spanishFunctionWords
            <= V4_RELAXED_EXPLICIT_MAX_FUNCTION_WORDS
        && (
            lowSupportRatio
                >= V4_RELAXED_EXPLICIT_MIN_LOW_SUPPORT_RATIO
            || (
                weakOrLowerRatio >= 0.78
                && averageSupport <= 0.24
            )
        )
        && neutralTechnicalWords === 0;

    const consensusVoid =
        structurallyVoid
        && !spanishEvidence.direct_confirmation
        && Number(
            spanishEvidence.score
            ?? 1
        ) <= 0.20
        && spanishFunctionWords
            <= V4_CONSENSUS_MAX_FUNCTION_WORDS
        && clearSpanishMorphWords === 0
        && (
            lowSupportRatio
                >= V4_CONSENSUS_MIN_LOW_SUPPORT_RATIO
            || (
                weakOrLowerRatio >= 0.82
                && averageSupport <= 0.20
            )
        )
        && neutralTechnicalWords === 0;

    return {
        informative_words:
            informativeWords.length,
        low_support_words:
            lowSupportWords,
        low_support_ratio:
            lowSupportRatio,
        weak_or_lower_ratio:
            weakOrLowerRatio,
        average_support:
            averageSupport,
        spanish_function_words:
            spanishFunctionWords,
        clear_spanish_morph_words:
            clearSpanishMorphWords,
        neutral_technical_words:
            neutralTechnicalWords,
        structurally_void:
            structurallyVoid,
        almost_no_spanish:
            almostNoSpanish,
        consensus_void:
            consensusVoid,
        text_length:
            countLetters(
                text
            )
    };
}


/**
 * Analiza una zona sospechosa ya localizada por el scanner barato.
 *
 * V4 vuelve a pedir evidencia propia de español ANTES de usar francAll. Esto
 * protege frases españolas legítimas con vocabulario infrecuente.
 *
 * @param {string} candidateText
 * @param {string} kind
 * @param {object} metadata
 * @returns {object|null}
 */
function analyzeV4ForeignCandidate(
    candidateText,
    kind,
    metadata = {}
) {
    const candidateWords =
        extractWords(
            candidateText
        );

    const minimumWords =
        kind === 'v4_clause'
            ? V4_EXPLICIT_MIN_WORDS
            : 3;

    if (
        candidateWords.length < minimumWords
        || countLetters(candidateText) < V4_MIN_CANDIDATE_LETTERS
    ) {
        return null;
    }

    const spanishStructure =
        analyzeSpanishStructure(
            candidateText,
            candidateWords
        );

    const spanishEvidence =
        analyzeSpanishEvidence(
            candidateText,
            candidateWords
        );

    const foreignEvidence =
        analyzeForeignEvidence(
            candidateText,
            candidateWords
        );

    const informativeWords =
        getInformativeWords(
            candidateWords
        );

    const localShape =
        getV4LocalSpanishShape(
            candidateText,
            candidateWords,
            spanishStructure,
            spanishEvidence
        );

    const neutralTechnicalWords =
        Number(
            localShape.neutral_technical_words
            ?? 0
        );

    const neutralTechnicalRatio =
        informativeWords.length > 0
            ? neutralTechnicalWords
                / informativeWords.length
            : 0;

    const explicit =
        kind === 'v4_clause';

    /*
     * Una evidencia extranjera exclusiva o una construcción contrastiva de alta
     * precisión es independiente de francAll. Esta capa ya fue diseñada para
     * code-switching mínimo y conserva prioridad.
     */
    const independentForeignRule =
        foreignEvidence.exclusive_marker
        || foreignEvidence.contrastive_grammar;

    if (independentForeignRule) {
        return {
            kind,
            mode:
                'v4_rules',
            language:
                foreignEvidence
                    .exclusive_marker
                    ?.language
                ?? foreignEvidence
                    .contrastive_grammar
                    ?.language
                ?? foreignEvidence
                    .best_language
                ?? 'foreign',
            text:
                candidateText,
            word_count:
                candidateWords.length,
            neutral_technical_ratio:
                neutralTechnicalRatio,
            local_shape:
                localShape,
            ...metadata
        };
    }

    const foreignProfileScore =
        Number(
            foreignEvidence.best_score
            ?? 0
        );

    const noForeignProfile =
        foreignProfileScore <= 0;

    const localSpanishShape =
        noForeignProfile
        && (
            (
                spanishStructure.function_words >= 2
                && spanishStructure.verb_hits >= 1
            )
            || (
                spanishStructure.function_words >= 2
                && spanishStructure.structural_hits >= 1
            )
            || (
                spanishStructure.function_words >= 3
                && spanishStructure.function_density >= 0.28
            )
            || (
                spanishStructure.function_words >= 2
                && spanishStructure.function_density >= 0.30
                && spanishEvidence.score >= 0.18
            )
        );

    /*
     * Protección española principal. Se mantiene deliberadamente fuerte para
     * ventanas internas. Una cláusula explícita romance puede reabrirse sólo si
     * un perfil extranjero real la respalda y la mayoría de sus tokens carece de
     * soporte español. Así no volvemos a los falsos `ind/slv/zlm` sobre español.
     */
    const spanishProtected =
        spanishStructure.medium_confirmation
        || spanishStructure.question_structure
        || spanishStructure.voseo_structure
        || spanishEvidence.direct_confirmation
        || (
            spanishStructure.distinctive_words >= 2
            && spanishStructure.function_words >= 1
        )
        || spanishEvidence.score >= 0.34
        || localSpanishShape
        || (
            neutralTechnicalWords >= 1
            && (
                spanishStructure.function_words >= 1
                || spanishStructure.distinctive_words >= 1
                || spanishStructure.verb_hits >= 1
                || spanishStructure.structural_hits >= 1
            )
        );

    const profileOverride =
        explicit
        && foreignProfileScore
            >= V4_PROFILE_OVERRIDE_MIN_SUPPORT
        && Number(
            localShape.low_support_ratio
            ?? 0
        ) >= V4_PROFILE_OVERRIDE_MIN_LOW_SUPPORT_RATIO
        && neutralTechnicalWords === 0
        && !spanishStructure.question_structure
        && !spanishStructure.voseo_structure
        && Number(
            spanishEvidence.rioplatense_hits
            ?? 0
        ) === 0
        && Number(
            spanishEvidence.phrase_hits
            ?? 0
        ) === 0;

    if (
        spanishProtected
        && !profileOverride
    ) {
        return null;
    }

    /*
     * Las reglas de perfil conocidas pueden cortar después de superar la
     * protección española. En cláusulas romances cercanas `profileOverride`
     * evita que palabras funcionales compartidas escondan otro idioma.
     */
    if (foreignEvidence.strong) {
        return {
            kind,
            mode:
                'v4_rules',
            language:
                foreignEvidence
                    .hard_marker
                    ?.language
                ?? foreignEvidence
                    .best_language
                ?? 'foreign',
            text:
                candidateText,
            word_count:
                candidateWords.length,
            neutral_technical_ratio:
                neutralTechnicalRatio,
            foreign_profile_score:
                foreignProfileScore,
            local_shape:
                localShape,
            ...metadata
        };
    }

    /*
     * Los fragmentos creados únicamente por coma siguen siendo una vía de reglas.
     * Nunca se usa francAll sobre ellos.
     */
    if (kind === 'v4_rule_fragment') {
        return null;
    }

    if (
        !explicit
        && neutralTechnicalRatio
            > V4_MAX_NEUTRAL_TECH_RATIO
    ) {
        return null;
    }

    const statisticalEvidence =
        analyzeStatisticalEvidence(
            candidateText,
            candidateWords
        );

    if (!statisticalEvidence.available) {
        return null;
    }

    const candidateLanguage =
        statisticalEvidence.best_foreign_language
        ?? statisticalEvidence.top_language;

    if (
        !isGenericLatinForeignLanguage(
            candidateLanguage
        )
        || statisticalEvidence.top_language
            === SPANISH_CODE
    ) {
        return null;
    }

    const spanishScore =
        Number(
            statisticalEvidence.spanish_score
            ?? 0
        );

    const foreignScore =
        Number(
            statisticalEvidence.best_foreign_score
            ?? 0
        );

    const margin =
        foreignScore
        - spanishScore;

    const wordCount =
        candidateWords.length;

    const shortWindow =
        wordCount <= 4;

    const tinyExplicit =
        explicit
        && wordCount <= 2;

    /*
     * Vía estricta: conserva exactamente la filosofía conservadora de la
     * corrección anterior.
     */
    const strictPasses =
        tinyExplicit
            ? (
                spanishScore <= V4_TINY_EXPLICIT_MAX_SPANISH_SCORE
                && foreignScore >= V4_TINY_EXPLICIT_MIN_FOREIGN_SCORE
                && margin >= V4_TINY_EXPLICIT_MIN_MARGIN
            )
            : explicit
                ? (
                    spanishScore <= V4_EXPLICIT_MAX_SPANISH_SCORE
                    && foreignScore >= V4_EXPLICIT_MIN_FOREIGN_SCORE
                    && margin >= V4_EXPLICIT_MIN_MARGIN
                )
                : shortWindow
                    ? (
                        spanishScore <= V4_SHORT_WINDOW_MAX_SPANISH_SCORE
                        && foreignScore >= V4_SHORT_WINDOW_MIN_FOREIGN_SCORE
                        && margin >= V4_SHORT_WINDOW_MIN_MARGIN
                    )
                    : (
                        spanishScore <= V4_WINDOW_MAX_SPANISH_SCORE
                        && foreignScore >= V4_WINDOW_MIN_FOREIGN_SCORE
                        && margin >= V4_WINDOW_MIN_MARGIN
                    );

    /*
     * Vía V4.1 para cláusulas explícitas con casi cero español local.
     *
     * No se habilita por "franc dice extranjero" a secas. Exige simultáneamente:
     * - frontera fuerte real;
     * - contexto español comprobado fuera del segmento;
     * - ausencia estructural de español dentro;
     * - mayoría de tokens sin soporte español;
     * - cero vocabulario técnico neutral;
     * - ventaja estadística extranjera, aunque ya no sea exageradamente grande.
     */
    const relaxedExplicitPasses =
        explicit
        && Boolean(
            metadata.spanish_neighbor
        )
        && neutralTechnicalWords === 0
        && (
            (
                wordCount >= V4_RELAXED_EXPLICIT_MIN_WORDS
                && localShape.almost_no_spanish
                && spanishScore
                    <= V4_RELAXED_EXPLICIT_MAX_SPANISH_SCORE
                && foreignScore
                    >= V4_RELAXED_EXPLICIT_MIN_FOREIGN_SCORE
                && margin
                    >= V4_RELAXED_EXPLICIT_MIN_MARGIN
            )
            || (
                wordCount === 3
                && localShape.structurally_void
                && Number(
                    localShape.spanish_function_words
                    ?? 0
                ) === 0
                && (
                    Number(
                        localShape.low_support_ratio
                        ?? 0
                    ) >= 0.90
                    || (
                        Number(
                            localShape.weak_or_lower_ratio
                            ?? 0
                        ) >= 0.90
                        && Number(
                            localShape.average_support
                            ?? 1
                        ) <= 0.20
                    )
                )
                && Number(
                    spanishEvidence.score
                    ?? 1
                ) <= 0.14
                && spanishScore
                    <= V4_RELAXED_TINY_MAX_SPANISH_SCORE
                && foreignScore
                    >= V4_RELAXED_TINY_MIN_FOREIGN_SCORE
                && margin
                    >= V4_RELAXED_TINY_MIN_MARGIN
            )
            || (
                profileOverride
                && spanishScore <= 0.72
                && foreignScore >= 0.56
                && margin >= 0.08
            )
        );

    /*
     * Vía relajada para VENTANAS que sólo pueden entrar a un consenso posterior.
     * Una ventana individual con estos umbrales nunca produce un rechazo.
     */
    const relaxedConsensusPasses =
        !explicit
        && localShape.consensus_void
        && (
            wordCount <= 3
                ? (
                    spanishScore
                        <= V4_CONSENSUS_SHORT_MAX_SPANISH_SCORE
                    && foreignScore
                        >= V4_CONSENSUS_SHORT_MIN_FOREIGN_SCORE
                    && margin
                        >= V4_CONSENSUS_SHORT_MIN_MARGIN
                )
                : (
                    spanishScore
                        <= V4_CONSENSUS_MAX_SPANISH_SCORE
                    && foreignScore
                        >= V4_CONSENSUS_MIN_FOREIGN_SCORE
                    && margin
                        >= V4_CONSENSUS_MIN_MARGIN
                )
        );

    if (
        !strictPasses
        && !relaxedExplicitPasses
        && !relaxedConsensusPasses
    ) {
        return null;
    }

    const acceptanceTier =
        strictPasses
            ? 'strict'
            : relaxedExplicitPasses
                ? 'relaxed_explicit_void'
                : 'relaxed_consensus_candidate';

    return {
        kind,
        mode:
            explicit
                ? 'v4_statistical_clause'
                : 'v4_statistical_window',
        language:
            candidateLanguage,
        text:
            candidateText,
        word_count:
            wordCount,
        spanish_score:
            spanishScore,
        foreign_score:
            foreignScore,
        margin,
        ranking:
            statisticalEvidence.ranking,
        neutral_technical_ratio:
            neutralTechnicalRatio,
        foreign_profile_score:
            foreignProfileScore,
        acceptance_tier:
            acceptanceTier,
        low_support_ratio:
            Number(
                metadata.low_support_ratio
                ?? localShape.low_support_ratio
                ?? 0
            ),
        spanish_function_words:
            Number(
                metadata.spanish_function_words
                ?? localShape.spanish_function_words
                ?? 0
            ),
        local_shape:
            localShape,
        ...metadata
    };
}


/**
 * Extrae segmentos con una frontera explícita real: oraciones completas,
 * paréntesis, corchetes, comillas o texto entre rayas.
 *
 * La coma se excluye deliberadamente de esta colección estadística.
 *
 * @param {string} text
 * @returns {string[]}
 */
function extractV4StrongDelimitedSegments(text) {
    const source =
        String(text);

    const segments =
        [
            ...splitStrongSegments(
                source
            )
        ];

    const patterns =
        [
            /\(([^()]{2,})\)/gu,
            /\[([^\[\]]{2,})\]/gu,
            /\{([^{}]{2,})\}/gu,
            /"([^"]{2,})"/gu,
            /“([^”]{2,})”/gu,
            /«([^»]{2,})»/gu,
            /—\s*([^—]{2,}?)\s*—/gu
        ];

    for (const pattern of patterns) {
        for (
            const match
            of source.matchAll(
                pattern
            )
        ) {
            const segment =
                String(
                    match[1]
                    ?? ''
                ).trim();

            if (segment) {
                segments.push(
                    segment
                );
            }
        }
    }

    return [
        ...new Set(
            segments
                .map(
                    (segment) =>
                        stripStructuredPrefix(
                            segment
                        )
                )
                .filter(Boolean)
        )
    ];
}


/**
 * Comprueba que existe español claro fuera del candidato.
 *
 * @param {string} fullText
 * @param {string} candidate
 * @returns {boolean}
 */
function hasV4SpanishContextOutside(
    fullText,
    candidate
) {
    const source =
        String(fullText);

    const position =
        source.indexOf(
            candidate
        );

    let outside;

    if (position >= 0) {
        outside =
            (
                source.slice(
                    Math.max(
                        0,
                        position - 220
                    ),
                    position
                )
                + ' '
                + source.slice(
                    position
                    + candidate.length,
                    Math.min(
                        source.length,
                        position
                        + candidate.length
                        + 220
                    )
                )
            ).trim();
    } else {
        outside =
            source;
    }

    if (!outside) {
        return false;
    }

    return isConfidentSpanishClause(
        outside
    );
}


/**
 * Busca una cláusula extranjera delimitada por puntuación/comillas/paréntesis.
 *
 * La cláusula necesita contexto español vecino. Esta vía permite reaccionar a
 * microfrases de 3–4 palabras sin exigir dos ventanas estadísticas.
 *
 * @param {string} text
 * @returns {object|null}
 */
function detectV4DelimitedMixedSwitch(text) {
    /*
     * 1. Fragmentos finos (incluyen coma): sólo reglas extranjeras propias.
     *    No se permite estadística sobre estos fragmentos.
     */
    const ruleFragments =
        splitMicroClauses(
            text
        );

    for (
        let index = 0;
        index < ruleFragments.length;
        index += 1
    ) {
        const fragment =
            ruleFragments[index];

        if (
            extractWords(
                fragment
            ).length < 2
            || !hasV4SpanishContextOutside(
                text,
                fragment
            )
        ) {
            continue;
        }

        const result =
            analyzeV4ForeignCandidate(
                fragment,
                'v4_rule_fragment',
                {
                    clause_index:
                        index,
                    spanish_neighbor:
                        true
                }
            );

        if (result) {
            return result;
        }
    }

    /*
     * 2. Segmentos con frontera fuerte: aquí sí se admite estadística porque la
     *    unidad tiene entidad propia y existe español claro fuera de ella.
     */
    const strongSegments =
        extractV4StrongDelimitedSegments(
            text
        );

    for (
        let index = 0;
        index < strongSegments.length;
        index += 1
    ) {
        const segment =
            strongSegments[index];

        const segmentWords =
            extractWords(
                segment
            );

        if (
            segmentWords.length < V4_EXPLICIT_MIN_WORDS
            || !hasV4SpanishContextOutside(
                text,
                segment
            )
        ) {
            continue;
        }

        const result =
            analyzeV4ForeignCandidate(
                segment,
                'v4_clause',
                {
                    clause_index:
                        index,
                    spanish_neighbor:
                        true
                }
            );

        if (result) {
            return result;
        }
    }

    return null;
}


/**
 * Construye candidatos adaptativos usando una firma española barata.
 *
 * No llama a francAll. Devuelve únicamente las zonas más sospechosas y limita
 * la cantidad máxima para que un comentario de 1200 caracteres no multiplique
 * el costo de inferencia de forma descontrolada.
 *
 * @param {string[]} words
 * @returns {object[]}
 */
function buildV4AdaptiveCandidates(words) {
    const informativeWords =
        getInformativeWords(
            words
        );

    if (informativeWords.length < 3) {
        return [];
    }

    const support =
        informativeWords.map(
            (word) =>
                getV4SpanishTokenCompatibility(
                    word
                )
        );

    const prefix =
        [0];

    for (const value of support) {
        prefix.push(
            prefix[prefix.length - 1]
            + value
        );
    }

    const candidates =
        [];

    const seen =
        new Set();

    for (
        const size
        of V4_ADAPTIVE_WINDOW_SIZES
    ) {
        if (informativeWords.length < size) {
            continue;
        }

        const step =
            size <= 6
                ? 1
                : 2;

        for (
            let start = 0;
            start + size <= informativeWords.length;
            start += step
        ) {
            const end =
                start + size;

            const supportScore =
                prefix[end]
                - prefix[start];

            const supportRatio =
                supportScore
                / size;

            if (
                supportRatio
                > getV4CheapSupportLimit(size)
            ) {
                continue;
            }

            const sliceSupport =
                support.slice(
                    start,
                    end
                );

            const lowSupportWords =
                sliceSupport.filter(
                    (value) =>
                        value <= 0.10
                ).length;

            const weakOrLowerWords =
                sliceSupport.filter(
                    (value) =>
                        value <= 0.28
                ).length;

            const lowSupportRatio =
                lowSupportWords
                / size;

            const weakOrLowerRatio =
                weakOrLowerWords
                / size;

            /*
             * Hay dos formas de llegar al análisis estadístico:
             *
             * 1. mayoría de tokens completamente desconocidos;
             * 2. secuencia muy homogénea de soporte débil, útil para idiomas que
             *    comparten tildes con español (por ejemplo húngaro).
             *
             * La segunda vía todavía deberá demostrar cero funciones españolas
             * y consenso estadístico posterior.
             */
            const weakSequenceCandidate =
                weakOrLowerRatio >= 0.82
                && supportRatio <= 0.22;

            if (
                lowSupportRatio
                    < V4_MIN_CORE_LOW_SUPPORT_RATIO
                && !weakSequenceCandidate
            ) {
                continue;
            }

            const slice =
                informativeWords.slice(
                    start,
                    end
                );

            const neutralTechnicalWords =
                slice.filter(
                    (word) =>
                        isNeutralInternationalWord(
                            String(word)
                                .toLocaleLowerCase('es')
                        )
                ).length;

            const spanishFunctionWords =
                slice.filter(
                    (word) =>
                        SPANISH_FUNCTION_WORDS.has(
                            String(word)
                                .toLocaleLowerCase('es')
                        )
                ).length;

            if (
                weakSequenceCandidate
                && spanishFunctionWords > 0
            ) {
                continue;
            }

            /*
             * Los trigramas son especialmente ruidosos. Si ya contienen una
             * palabra funcional española, se exige una ventana mayor antes de
             * consultar estadística. Esto elimina falsos candidatos como
             * "suele simplificar el" sin impedir trigramas extranjeros puros.
             */
            if (
                size <= 3
                && spanishFunctionWords > 0
            ) {
                continue;
            }

            /*
             * Un término técnico internacional rodeado por español no constituye
             * una isla extranjera. Si la ventana contiene ese tipo de token se
             * deja a las reglas explícitas, no a francAll.
             */
            if (neutralTechnicalWords > 0) {
                continue;
            }

            const candidateText =
                slice.join(' ');

            if (seen.has(candidateText)) {
                continue;
            }

            seen.add(candidateText);

            candidates.push({
                text:
                    candidateText,
                start,
                end,
                size,
                cheap_spanish_ratio:
                    supportRatio,
                low_support_ratio:
                    lowSupportRatio,
                weak_or_lower_ratio:
                    weakOrLowerRatio,
                weak_sequence_candidate:
                    weakSequenceCandidate,
                spanish_function_words:
                    spanishFunctionWords
            });
        }
    }

    /*
     * Se priorizan ventanas con más tokens realmente desconocidos. El score
     * medio español sólo se usa como segundo criterio.
     */
    candidates.sort(
        (a, b) =>
            (
                b.low_support_ratio
                - a.low_support_ratio
            )
            || (
                a.cheap_spanish_ratio
                - b.cheap_spanish_ratio
            )
            || (
                b.size
                - a.size
            )
    );

    return candidates.slice(
        0,
        V4_MAX_STATISTICAL_WINDOWS
    );
}


/**
 * Busca una racha CONTIGUA de cuatro o más palabras con soporte español casi
 * nulo dentro de un comentario globalmente español.
 *
 * Esta vía está pensada para bloques largos incrustados sin puntuación. Es más
 * segura que relajar todas las ventanas porque exige simultáneamente:
 * - 4+ palabras contiguas;
 * - cero palabras funcionales españolas;
 * - cero términos técnicos neutrales;
 * - casi toda la racha con soporte español nulo o débil;
 * - contexto español claro fuera de la racha;
 * - francAll prefiriendo un idioma no español con margen real.
 *
 * @param {string} text
 * @param {string[]} words
 * @returns {object|null}
 */
function detectV4EmbeddedForeignRun(
    text,
    words
) {
    const informativeWords =
        getInformativeWords(
            words
        );

    if (informativeWords.length < 8) {
        return null;
    }

    const support =
        informativeWords.map(
            (word) =>
                getV4SpanishTokenCompatibility(
                    word
                )
        );

    const runs =
        [];

    let start =
        null;

    const flush =
        (end) => {
            if (
                start === null
                || end - start < 4
            ) {
                start =
                    null;
                return;
            }

            runs.push({
                start,
                end
            });

            start =
                null;
        };

    for (
        let index = 0;
        index < informativeWords.length;
        index += 1
    ) {
        const word =
            informativeWords[index];

        const token =
            String(word)
                .toLocaleLowerCase('es');

        const value =
            support[index];

        const isBoundary =
            SPANISH_FUNCTION_WORDS.has(
                token
            )
            || isNeutralInternationalWord(
                token
            )
            || value > 0.28;

        if (isBoundary) {
            flush(
                index
            );
            continue;
        }

        if (start === null) {
            start =
                index;
        }
    }

    flush(
        informativeWords.length
    );

    if (!runs.length) {
        return null;
    }

    runs.sort(
        (left, right) =>
            (
                right.end
                - right.start
            )
            - (
                left.end
                - left.start
            )
    );

    for (const run of runs) {
        const slice =
            informativeWords.slice(
                run.start,
                run.end
            );

        const candidateText =
            slice.join(
                ' '
            );

        if (
            !hasV4SpanishContextOutside(
                text,
                candidateText
            )
        ) {
            continue;
        }

        const candidateWords =
            extractWords(
                candidateText
            );

        const spanishStructure =
            analyzeSpanishStructure(
                candidateText,
                candidateWords
            );

        const spanishEvidence =
            analyzeSpanishEvidence(
                candidateText,
                candidateWords
            );

        const foreignEvidence =
            analyzeForeignEvidence(
                candidateText,
                candidateWords
            );

        const localShape =
            getV4LocalSpanishShape(
                candidateText,
                candidateWords,
                spanishStructure,
                spanishEvidence
            );

        if (
            spanishStructure.question_structure
            || spanishStructure.voseo_structure
            || spanishEvidence.direct_confirmation
            || Number(
                spanishStructure.distinctive_words
                ?? 0
            ) >= 2
            || Number(
                localShape.spanish_function_words
                ?? 0
            ) > 0
            || Number(
                localShape.neutral_technical_words
                ?? 0
            ) > 0
            || Number(
                spanishEvidence.score
                ?? 1
            ) > 0.18
            || (
                Number(
                    localShape.low_support_ratio
                    ?? 0
                ) < 0.72
                && !(
                    Number(
                        localShape.weak_or_lower_ratio
                        ?? 0
                    ) >= 0.84
                    && Number(
                        localShape.average_support
                        ?? 1
                    ) <= 0.20
                )
            )
        ) {
            continue;
        }

        const statisticalEvidence =
            analyzeStatisticalEvidence(
                candidateText,
                candidateWords
            );

        if (
            !statisticalEvidence.available
            || statisticalEvidence.top_language
                === SPANISH_CODE
        ) {
            continue;
        }

        const candidateLanguage =
            statisticalEvidence.best_foreign_language
            ?? statisticalEvidence.top_language;

        if (
            !isGenericLatinForeignLanguage(
                candidateLanguage
            )
        ) {
            continue;
        }

        const spanishScore =
            Number(
                statisticalEvidence.spanish_score
                ?? 0
            );

        const foreignScore =
            Number(
                statisticalEvidence.best_foreign_score
                ?? 0
            );

        const margin =
            foreignScore
            - spanishScore;

        const closeRomance =
            new Set([
                'por',
                'glg',
                'cat',
                'ita',
                'fra',
                'ron'
            ]).has(
                candidateLanguage
            );

        const profileSupport =
            getForeignProfileSupport(
                foreignEvidence,
                candidateLanguage
            );

        const statisticalPass =
            spanishScore <= 0.58
            && foreignScore >= 0.70
            && margin >= 0.24;

        const romancePass =
            !closeRomance
            || profileSupport >= 0.12
            || margin >= 0.24;

        if (
            statisticalPass
            && romancePass
        ) {
            return {
                kind:
                    'v4_embedded_run',
                mode:
                    'v4_statistical_embedded_run',
                language:
                    candidateLanguage,
                text:
                    candidateText,
                word_count:
                    candidateWords.length,
                run_start:
                    run.start,
                run_end:
                    run.end,
                spanish_score:
                    spanishScore,
                foreign_score:
                    foreignScore,
                margin,
                foreign_profile_score:
                    profileSupport,
                local_shape:
                    localShape,
                reason:
                    'v4_embedded_foreign_run'
            };
        }
    }

    return null;
}


/**
 * Busca una isla extranjera dentro de una oración o párrafo sin depender de la
 * puntuación. El objetivo es localizar islas internas sin depender de una coma.
 *
 * @param {string} text
 * @param {string[]} words
 * @returns {object|null}
 */
function detectV4AdaptiveMixedSwitch(
    text,
    words
) {
    const candidates =
        buildV4AdaptiveCandidates(
            words
        );

    const foreignCandidates =
        [];

    for (
        let index = 0;
        index < candidates.length;
        index += 1
    ) {
        const candidate =
            candidates[index];

        const result =
            analyzeV4ForeignCandidate(
                candidate.text,
                'v4_window',
                {
                    candidate_index:
                        index,
                    window_start:
                        candidate.start,
                    window_end:
                        candidate.end,
                    cheap_spanish_ratio:
                        candidate.cheap_spanish_ratio,
                    low_support_ratio:
                        candidate.low_support_ratio,
                    weak_or_lower_ratio:
                        candidate.weak_or_lower_ratio,
                    weak_sequence_candidate:
                        candidate.weak_sequence_candidate,
                    spanish_function_words:
                        candidate.spanish_function_words
                }
            );

        if (!result) {
            continue;
        }

        /*
         * Una regla extranjera contrastiva de alta precisión sí puede resolver
         * inmediatamente porque ya pasó por la protección española.
         */
        if (result.mode === 'v4_rules') {
            return result;
        }

        /*
         * Una isla compacta de 3–6 palabras puede bastar sin una segunda ventana
         * si la estadística es excepcionalmente dominante, no contiene palabras
         * funcionales españolas y casi todo el núcleo carece de soporte español.
         *
         * Esta vía reemplaza la antigua "ventana larga única", que era demasiado
         * permisiva. Los umbrales aquí son deliberadamente mucho más altos.
         */
        if (
            Number(
                result.word_count
                ?? 0
            ) >= 3
            && Number(
                result.word_count
                ?? 0
            ) <= 6
            && Number(
                result.spanish_function_words
                ?? 0
            ) === 0
            && (
                Number(
                    result.low_support_ratio
                    ?? 0
                ) >= V4_SINGLE_CORE_MIN_LOW_SUPPORT_RATIO
                || Boolean(
                    result.local_shape
                        ?.consensus_void
                )
            )
            && Number(
                result.spanish_score
                ?? 1
            ) <= V4_SINGLE_CORE_MAX_SPANISH_SCORE
            && Number(
                result.foreign_score
                ?? 0
            ) >= V4_SINGLE_CORE_MIN_FOREIGN_SCORE
            && Number(
                result.margin
                ?? 0
            ) >= V4_SINGLE_CORE_MIN_MARGIN
        ) {
            return {
                ...result,
                reason:
                    'v4_single_compact_foreign_core'
            };
        }

        foreignCandidates.push(
            result
        );
    }

    /*
     * CONSENSO CONSERVADOR.
     *
     * Dos ventanas no bastan por el mero hecho de superponerse. Deben:
     * - solaparse de forma material;
     * - apuntar al mismo idioma o a un grupo estadístico compatible;
     * - exhibir una mayoría real de tokens de bajo soporte español.
     *
     * Se elimina también la vía "una sola ventana larga", responsable de varios
     * falsos MIXED sobre español largo.
     */
    for (
        let index = 0;
        index < foreignCandidates.length;
        index += 1
    ) {
        const current =
            foreignCandidates[index];

        for (
            let nextIndex = index + 1;
            nextIndex < foreignCandidates.length;
            nextIndex += 1
        ) {
            const next =
                foreignCandidates[nextIndex];

            if (
                !areV4ForeignLanguagesCompatible(
                    current.language,
                    next.language
                )
            ) {
                continue;
            }

            const currentWords =
                Number(
                    current.word_count
                    ?? 0
                );

            const nextWords =
                Number(
                    next.word_count
                    ?? 0
                );

            if (
                currentWords === nextWords
                || Math.max(
                    currentWords,
                    nextWords
                ) < 4
            ) {
                continue;
            }

            const overlap =
                Math.min(
                    Number(current.window_end ?? 0),
                    Number(next.window_end ?? 0)
                )
                - Math.max(
                    Number(current.window_start ?? 0),
                    Number(next.window_start ?? 0)
                );

            if (overlap <= 0) {
                continue;
            }

            const currentSpan =
                Math.max(
                    1,
                    Number(current.window_end ?? 0)
                    - Number(current.window_start ?? 0)
                );

            const nextSpan =
                Math.max(
                    1,
                    Number(next.window_end ?? 0)
                    - Number(next.window_start ?? 0)
                );

            const overlapRatio =
                overlap
                / Math.min(
                    currentSpan,
                    nextSpan
                );

            if (
                overlapRatio
                < V4_MIN_CONSENSUS_OVERLAP
            ) {
                continue;
            }

            const currentLowSupportConfirmed =
                Number(
                    current.low_support_ratio
                    ?? 0
                ) >= V4_MIN_CORE_LOW_SUPPORT_RATIO
                || Boolean(
                    current.local_shape
                        ?.consensus_void
                );

            const nextLowSupportConfirmed =
                Number(
                    next.low_support_ratio
                    ?? 0
                ) >= V4_MIN_CORE_LOW_SUPPORT_RATIO
                || Boolean(
                    next.local_shape
                        ?.consensus_void
                );

            const lowSupportConfirmed =
                currentLowSupportConfirmed
                && nextLowSupportConfirmed;

            if (!lowSupportConfirmed) {
                continue;
            }

            /*
             * Si ambas ventanas llegaron sólo por la vía relajada, además del
             * solapamiento exigimos una pequeña corroboración de confianza. La
             * relajación sirve para formar consenso, no para convertir dos
             * predicciones marginales en una sentencia.
             */
            const bothRelaxed =
                current.acceptance_tier
                    === 'relaxed_consensus_candidate'
                && next.acceptance_tier
                    === 'relaxed_consensus_candidate';

            if (bothRelaxed) {
                const currentMargin =
                    Number(
                        current.margin
                        ?? 0
                    );

                const nextMargin =
                    Number(
                        next.margin
                        ?? 0
                    );

                const strongestForeignScore =
                    Math.max(
                        Number(
                            current.foreign_score
                            ?? 0
                        ),
                        Number(
                            next.foreign_score
                            ?? 0
                        )
                    );

                if (
                    Math.max(
                        currentMargin,
                        nextMargin
                    ) < 0.18
                    || Math.min(
                        currentMargin,
                        nextMargin
                    ) < 0.10
                    || strongestForeignScore < 0.62
                ) {
                    continue;
                }
            }

            return {
                ...(
                    Number(current.margin ?? 0)
                    >= Number(next.margin ?? 0)
                        ? current
                        : next
                ),
                reason:
                    'v4_adaptive_foreign_consensus',
                consensus_count:
                    2,
                corroborating_language:
                    next.language,
                overlap_ratio:
                    overlapRatio
            };
        }
    }

    return null;
}


/**
 * Scanner de mezcla mínima y bloques latinos desconocidos.
 *
 * @param {string} text
 * @param {string[]} fullWords
 * @returns {object|null}
 */
function detectForeignMicroSwitch(
    text,
    fullWords
) {
    /*
     * V4 — orden deliberado:
     *
     * 1. microcláusulas delimitadas: muy baratas y muy precisas;
     * 2. scanner adaptativo sin fronteras: detecta islas en medio de oración;
     * 3. fallback conservador de fronteras fuertes como segunda opinión.
     */
    const delimitedEvidence =
        detectV4DelimitedMixedSwitch(
            text
        );

    if (delimitedEvidence) {
        return delimitedEvidence;
    }

    const embeddedRunEvidence =
        detectV4EmbeddedForeignRun(
            text,
            fullWords
        );

    if (embeddedRunEvidence) {
        return embeddedRunEvidence;
    }

    const adaptiveEvidence =
        detectV4AdaptiveMixedSwitch(
            text,
            fullWords
        );

    if (adaptiveEvidence) {
        return adaptiveEvidence;
    }

    const strongClauses =
        splitStrongSegments(
            text
        );

    for (
        let index = 0;
        index < strongClauses.length;
        index += 1
    ) {
        const result =
            detectGenericBoundaryForeignClause(
                strongClauses[index],
                index,
                strongClauses
            );

        if (result) {
            return result;
        }
    }

    return null;
}


/* ============================================================================
 * 8. ANALIZADOR EXTRANJERO CONTRASTIVO
 * ============================================================================ */

/**
 * Analiza evidencia extranjera por idioma.
 *
 * @param {string} text
 * @param {string[]} words
 * @returns {object}
 */
/**
 * Busca un token extranjero exclusivo de alta precisión.
 *
 * A diferencia de `hard` dentro de los perfiles, esta capa está diseñada para
 * code-switching mínimo: una sola palabra realmente incompatible con español
 * puede invalidar el comentario.
 *
 * @param {string[]} words
 * @returns {{language: string, marker: string}|null}
 */
function findExclusiveForeignToken(words) {
    for (
        const [language, markers]
        of Object.entries(
            FOREIGN_EXCLUSIVE_TOKENS
        )
    ) {
        for (const word of words) {
            if (markers.has(word)) {
                return {
                    language,
                    marker:
                        word
                };
            }
        }
    }

    return null;
}


/**
 * Cuenta gramática contrastiva extranjera.
 *
 * @param {string} text
 * @returns {{language: string, hits: number}|null}
 */
function findContrastiveForeignGrammar(text) {
    let best =
        null;

    for (
        const [language, patterns]
        of Object.entries(
            FOREIGN_CONTRASTIVE_GRAMMAR
        )
    ) {
        const hits =
            countPatternHits(
                text,
                patterns
            );

        if (
            hits > 0
            && (
                !best
                || hits > best.hits
            )
        ) {
            best = {
                language,
                hits
            };
        }
    }

    return best;
}


/**
 * Analiza evidencia extranjera por idioma.
 *
 * @param {string} text
 * @param {string[]} words
 * @returns {object}
 */
function analyzeForeignEvidence(
    text,
    words
) {
    const informativeWords =
        getInformativeWords(
            words
        );

    const byLanguage =
        {};

    let bestLanguage =
        null;

    let bestScore =
        0;

    let hardMarker =
        null;

    const exclusiveMarker =
        findExclusiveForeignToken(
            informativeWords
        );

    const contrastiveGrammar =
        findContrastiveForeignGrammar(
            text
        );

    if (exclusiveMarker) {
        hardMarker = {
            language:
                exclusiveMarker.language,
            marker:
                exclusiveMarker.marker
        };
    }

    if (
        contrastiveGrammar
        && !hardMarker
    ) {
        hardMarker = {
            language:
                contrastiveGrammar.language,
            marker:
                'contrastive-grammar'
        };
    }

    if (
        ENGLISH_CONTRACTION_PATTERN.test(
            text
        )
        && !hardMarker
    ) {
        hardMarker = {
            language:
                'eng',
            marker:
                'english-contraction'
        };
    }

    for (
        const [language, profile]
        of Object.entries(
            FOREIGN_PROFILES
        )
    ) {
        const hardMatchedWords =
            new Set();

        const softMatchedWords =
            new Set();

        let firstHardWord =
            null;

        for (const word of informativeWords) {
            if (profile.hard.has(word)) {
                hardMatchedWords.add(
                    word
                );

                firstHardWord ??=
                    word;
            }

            if (profile.soft.has(word)) {
                softMatchedWords.add(
                    word
                );
            }
        }

        const hardHits =
            hardMatchedWords.size;

        const softHits =
            softMatchedWords.size;

        const phraseHits =
            countPatternHits(
                text,
                profile.phrases
            );

        let morphologyHits =
            0;

        for (const word of informativeWords) {
            if (word.length < 5) {
                continue;
            }

            for (
                const pattern
                of profile.morphology
            ) {
                if (pattern.test(word)) {
                    morphologyHits += 1;
                    break;
                }
            }
        }

        const score =
            Math.min(
                1,
                (
                    Math.min(1, hardHits / 2) * 0.52
                    + Math.min(1, softHits / 3) * 0.24
                    + Math.min(1, phraseHits) * 0.48
                    + Math.min(1, morphologyHits / 3) * 0.12
                )
            );

        const strong =
            hardHits >= 1
            || phraseHits >= 1
            || softHits >= 3
            || (
                softHits >= 2
                && morphologyHits >= 1
            );

        byLanguage[language] = {
            score,
            strong,
            hard_hits:
                hardHits,
            soft_hits:
                softHits,
            phrase_hits:
                phraseHits,
            morphology_hits:
                morphologyHits
        };

        if (
            hardHits >= 1
            && !hardMarker
        ) {
            hardMarker = {
                language,
                marker:
                    firstHardWord
            };
        }

        if (score > bestScore) {
            bestScore =
                score;

            bestLanguage =
                language;
        }
    }

    const strongest =
        bestLanguage
            ? byLanguage[
                bestLanguage
            ]
            : null;

    return {
        strong:
            Boolean(
                hardMarker
                || strongest?.strong
            ),
        hard_marker:
            hardMarker,
        exclusive_marker:
            exclusiveMarker,
        contrastive_grammar:
            contrastiveGrammar,
        best_language:
            bestLanguage,
        best_score:
            bestScore,
        by_language:
            byLanguage
    };
}


/**
 * Devuelve apoyo léxico/frasal real para un candidato estadístico.
 *
 * La morfología sola no cuenta: varias terminaciones son compartidas entre
 * lenguas romances y fueron origen de falsos positivos locales.
 *
 * @param {object} foreignEvidence
 * @param {string|null} language
 * @returns {number}
 */
function getForeignProfileSupport(
    foreignEvidence,
    language
) {
    if (!language) {
        return 0;
    }

    const evidence =
        foreignEvidence
            ?.by_language
            ?.[language];

    if (!evidence) {
        return 0;
    }

    const lexicalHits =
        Number(
            evidence.hard_hits
            ?? 0
        )
        + Number(
            evidence.soft_hits
            ?? 0
        )
        + Number(
            evidence.phrase_hits
            ?? 0
        );

    if (lexicalHits <= 0) {
        return 0;
    }

    return Number(
        evidence.score
        ?? 0
    );
}


/* ============================================================================
 * 9. SEÑAL ESTADÍSTICA SECUNDARIA
 * ============================================================================ */

/**
 * Obtiene ranking estadístico limitado a idiomas latinos relevantes.
 *
 * `francAll()` se usa como evidencia auxiliar. Nunca aprueba ni rechaza un
 * comentario por sí solo.
 *
 * @param {string} text
 * @param {string[]} words
 * @returns {object}
 */
function analyzeStatisticalEvidence(
    text,
    words
) {
    const informativeWords =
        getInformativeWords(
            words
        );

    const statisticalText =
        informativeWords.join(' ');

    if (
        informativeWords.length < 2
        || countLetters(
            statisticalText
        ) < 8
    ) {
        return {
            available:
                false,
            top_language:
                UNKNOWN_CODE,
            top_score:
                null,
            spanish_score:
                null,
            best_foreign_language:
                null,
            best_foreign_score:
                null,
            spanish_margin:
                null,
            ranking:
                []
        };
    }

    let ranking;

    try {
        ranking =
            francAll(
                statisticalText,
                {
                    minLength:
                        FRANC_MIN_LENGTH,
                    only:
                        LATIN_STATISTICAL_CANDIDATES
                }
            );
    } catch {
        ranking =
            [];
    }

    if (!ranking.length) {
        return {
            available:
                false,
            top_language:
                UNKNOWN_CODE,
            top_score:
                null,
            spanish_score:
                null,
            best_foreign_language:
                null,
            best_foreign_score:
                null,
            spanish_margin:
                null,
            ranking:
                []
        };
    }

    const topLanguage =
        ranking[0][0];

    const topScore =
        Number(
            ranking[0][1]
        );

    const spanishEntry =
        ranking.find(
            ([language]) =>
                language === SPANISH_CODE
        );

    const spanishScore =
        spanishEntry
            ? Number(
                spanishEntry[1]
            )
            : 0;

    const bestForeignEntry =
        ranking.find(
            ([language]) =>
                language !== SPANISH_CODE
        );

    const bestForeignLanguage =
        bestForeignEntry?.[0]
        ?? null;

    const bestForeignScore =
        bestForeignEntry
            ? Number(
                bestForeignEntry[1]
            )
            : 0;

    return {
        available:
            true,
        top_language:
            topLanguage,
        top_score:
            topScore,
        spanish_score:
            spanishScore,
        best_foreign_language:
            bestForeignLanguage,
        best_foreign_score:
            bestForeignScore,
        spanish_margin:
            spanishScore
            - bestForeignScore,
        ranking:
            ranking.slice(0, 5)
    };
}


/* ============================================================================
 * 10. VENTANAS Y ORACIONES PARA DETECTAR MEZCLA
 * ============================================================================ */

/**
 * Divide solamente por fronteras fuertes de oración.
 *
 * La coma NO se usa como frontera: ese fue uno de los principales orígenes de
 * falsos rechazos en versiones anteriores.
 *
 * @param {string} text
 * @returns {string[]}
 */
function splitStrongSegments(text) {
    return String(text)
        .split(
            /(?<=[.!?])\s+|\n+/gu
        )
        .map(
            (segment) =>
                segment.trim()
        )
        .filter(Boolean);
}


/**
 * Construye ventanas solapadas sobre palabras.
 *
 * @param {string[]} words
 * @returns {string[]}
 */
function buildOverlappingWindows(words) {
    if (
        words.length
        < MIN_LOCAL_STAT_WORDS
    ) {
        return [];
    }

    const windows =
        [];

    const seen =
        new Set();

    for (
        let start = 0;
        start < words.length;
        start += LOCAL_WINDOW_STEP
    ) {
        const slice =
            words.slice(
                start,
                start + LOCAL_WINDOW_WORDS
            );

        if (
            slice.length
            < MIN_LOCAL_STAT_WORDS
        ) {
            break;
        }

        const text =
            slice.join(' ');

        if (!seen.has(text)) {
            seen.add(text);

            windows.push({
                text,
                start,
                end:
                    start
                    + slice.length
            });
        }
    }

    return windows;
}


/**
 * Decide si un bloque local contiene evidencia REAL de otro idioma.
 *
 * La estadística local sólo puede producir un rechazo cuando:
 *
 * - el bloque tiene tamaño suficiente;
 * - español queda claramente por debajo del mejor candidato extranjero;
 * - la capa propia de español es débil.
 *
 * @param {string} text
 * @param {string} kind
 * @returns {object|null}
 */
function detectForeignLocalBlock(
    text,
    kind
) {
    const words =
        extractWords(
            text
        );

    const letters =
        countLetters(
            text
        );

    if (
        words.length
        < MIN_LOCAL_STAT_WORDS
        || letters
            < MIN_LOCAL_STAT_LETTERS
    ) {
        return null;
    }

    const foreignEvidence =
        analyzeForeignEvidence(
            text,
            words
        );

    if (foreignEvidence.strong) {
        return {
            kind,
            mode:
                'rules',
            language:
                foreignEvidence
                    .hard_marker
                    ?.language
                ?? foreignEvidence
                    .best_language
                ?? 'foreign',
            reason:
                'foreign_rules',
            text,
            profile_support:
                foreignEvidence
                    .best_score
                ?? 0
        };
    }

    const spanishEvidence =
        analyzeSpanishEvidence(
            text,
            words
        );

    const spanishStructure =
        analyzeSpanishStructure(
            text,
            words
        );

    /*
     * V4: la sintaxis española protege listas, fragmentos periodísticos y
     * ventanas donde franc puede confundir español con una lengua romance.
     */
    if (
        spanishEvidence.direct_confirmation
        || spanishEvidence.score
            >= LOCAL_FOREIGN_MAX_RULE_SCORE
        || spanishStructure.medium_confirmation
        || (
            spanishStructure.distinctive_words >= 2
            && spanishStructure.function_words >= 2
        )
    ) {
        return null;
    }

    const statisticalEvidence =
        analyzeStatisticalEvidence(
            text,
            words
        );

    if (!statisticalEvidence.available) {
        return null;
    }

    const topLanguage =
        statisticalEvidence
            .top_language;

    const spanishScore =
        Number(
            statisticalEvidence
                .spanish_score
            ?? 0
        );

    const bestForeignScore =
        Number(
            statisticalEvidence
                .best_foreign_score
            ?? 0
        );

    const foreignMargin =
        bestForeignScore
        - spanishScore;

    const candidateLanguage =
        statisticalEvidence
            .best_foreign_language
        ?? topLanguage;

    const profileSupport =
        getForeignProfileSupport(
            foreignEvidence,
            candidateLanguage
        );

    /*
     * Una predicción estadística sin una sola señal léxica/frasal del
     * idioma candidato se descarta. Esto elimina falsos `cat`, `tur`, etc. en
     * ventanas españolas donde franc estaba seguro por razones de n-gramas.
     */
    if (
        topLanguage !== SPANISH_CODE
        && spanishScore
            <= LOCAL_FOREIGN_MAX_SPANISH_SCORE
        && foreignMargin
            >= LOCAL_FOREIGN_MIN_MARGIN
        && profileSupport
            >= LOCAL_FOREIGN_MIN_PROFILE_SUPPORT
    ) {
        return {
            kind,
            mode:
                'statistical',
            language:
                candidateLanguage,
            reason:
                'foreign_statistical_candidate',
            text,
            spanish_score:
                spanishScore,
            foreign_score:
                bestForeignScore,
            margin:
                foreignMargin,
            profile_support:
                profileSupport
        };
    }

    return null;
}


/**
 * Busca cambios de idioma dentro de un comentario que globalmente parece
 * español.
 *
 * @param {string} text
 * @param {string[]} words
 * @returns {object|null}
 */
function detectMixedLanguage(
    text,
    words
) {
    const statisticalCandidates =
        [];

    const segments =
        splitStrongSegments(
            text
        );

    for (
        let segmentIndex = 0;
        segmentIndex < segments.length;
        segmentIndex += 1
    ) {
        const segment =
            segments[
                segmentIndex
            ];

        const result =
            detectForeignLocalBlock(
                segment,
                'segment'
            );

        if (!result) {
            continue;
        }

        /*
         * Reglas contrastivas reales siguen teniendo prioridad inmediata.
         */
        if (result.mode === 'rules') {
            return result;
        }

        /*
         * Una oración completa puede alcanzar por estadística + apoyo léxico,
         * pero usamos umbrales más exigentes que para un simple candidato.
         */
        if (
            Number(
                result.spanish_score
                ?? 1
            ) <= SEGMENT_FOREIGN_MAX_SPANISH_SCORE
            && Number(
                result.margin
                ?? 0
            ) >= SEGMENT_FOREIGN_MIN_MARGIN
        ) {
            return {
                ...result,
                reason:
                    'foreign_segment_consensus'
            };
        }

        statisticalCandidates.push({
            ...result,
            source_index:
                segmentIndex
        });
    }

    const windows =
        buildOverlappingWindows(
            getInformativeWords(
                words
            )
        );

    for (
        let windowIndex = 0;
        windowIndex < windows.length;
        windowIndex += 1
    ) {
        const window =
            windows[
                windowIndex
            ];

        const result =
            detectForeignLocalBlock(
                window.text,
                'window'
            );

        if (!result) {
            continue;
        }

        if (result.mode === 'rules') {
            return {
                ...result,
                window_start:
                    window.start,
                window_end:
                    window.end
            };
        }

        statisticalCandidates.push({
            ...result,
            source_index:
                windowIndex,
            window_start:
                window.start,
            window_end:
                window.end
        });
    }

    /*
     * Consenso estadístico:
     *
     * - mismo idioma;
     * - candidatos próximos;
     * - al menos dos observaciones.
     *
     * Una inferencia aislada queda como ruido y no puede cortar el comentario.
     */
    for (
        let index = 0;
        index < statisticalCandidates.length;
        index += 1
    ) {
        const current =
            statisticalCandidates[
                index
            ];

        let consensus =
            1;

        for (
            let next = index + 1;
            next < statisticalCandidates.length;
            next += 1
        ) {
            const candidate =
                statisticalCandidates[
                    next
                ];

            if (
                candidate.language
                !== current.language
            ) {
                continue;
            }

            if (
                Math.abs(
                    Number(
                        candidate.source_index
                        ?? 0
                    )
                    - Number(
                        current.source_index
                        ?? 0
                    )
                )
                <= LOCAL_STAT_CONSENSUS_MAX_DISTANCE
            ) {
                consensus += 1;
            }
        }

        if (
            consensus
            >= LOCAL_STAT_CONSENSUS_MIN_COUNT
        ) {
            return {
                ...current,
                reason:
                    'foreign_statistical_consensus',
                consensus_count:
                    consensus
            };
        }
    }

    return null;
}


/* ============================================================================
 * 11. FUSIÓN GLOBAL
 * ============================================================================ */

/**
 * Fusiona evidencia propia + señal estadística para confirmar español.
 *
 * @param {object} spanishEvidence
 * @param {object} statisticalEvidence
 * @param {number} letters
 * @returns {{is_spanish: boolean, reason: string}}
 */
function fuseSpanishDecision(
    spanishEvidence,
    spanishStructure,
    statisticalEvidence,
    letters
) {
    const wordCount =
        Number(
            spanishStructure.word_count
            ?? spanishEvidence.total_words
            ?? 0
        );

    const shortText =
        wordCount <= 8
        || letters < 60;

    /*
     * V4: los textos breves tienen un verificador propio. La estadística sólo
     * ayuda; nunca es requisito si la estructura española ya está confirmada.
     */
    if (shortText) {
        if (
            spanishStructure.short_confirmation
            || spanishStructure.medium_confirmation
            || spanishEvidence.direct_confirmation
            || Number(
                spanishEvidence.short_grammar_hits
                ?? 0
            ) >= 1
        ) {
            return {
                is_spanish:
                    true,
                reason:
                    'confirmed_spanish_short_structure'
            };
        }

        if (
            (
                spanishStructure.distinctive_words >= 2
                && spanishStructure.function_words >= 1
            )
            || (
                spanishStructure.verb_hits >= 1
                && spanishStructure.function_words >= 2
                && spanishStructure.distinctive_words >= 1
            )
            || (
                spanishStructure.verb_hits >= 1
                && Number(
                    spanishEvidence.strong_words
                    ?? 0
                ) >= 1
            )
            || (
                spanishEvidence.score >= 0.34
                && statisticalEvidence.top_language
                    === SPANISH_CODE
            )
        ) {
            return {
                is_spanish:
                    true,
                reason:
                    'confirmed_spanish_short_hybrid_v4'
            };
        }

        return {
            is_spanish:
                false,
            reason:
                'language_not_confirmed_short'
        };
    }

    /*
     * Una estructura española media/fuerte y ausencia de evidencia extranjera
     * real tiene prioridad sobre confusiones estadísticas spa/cat/glg/por.
     */
    if (
        spanishStructure.medium_confirmation
        || spanishEvidence.direct_confirmation
        || spanishEvidence.score
            >= SPANISH_STRONG_SCORE
    ) {
        return {
            is_spanish:
                true,
            reason:
                spanishStructure.medium_confirmation
                    ? 'confirmed_spanish_structure'
                    : 'confirmed_spanish_rules'
        };
    }

    const spanishStatScore =
        Number(
            statisticalEvidence.spanish_score
            ?? 0
        );

    const spanishMargin =
        Number(
            statisticalEvidence.spanish_margin
            ?? -1
        );

    /*
     * Evidencia estructural moderada + estadística compatible.
     */
    if (
        (
            spanishEvidence.score >= SPANISH_SUPPORTED_SCORE
            || (
                spanishStructure.distinctive_words >= 2
                && spanishStructure.function_words >= 3
            )
        )
        && spanishStatScore >= 0.62
        && spanishMargin >= -0.18
    ) {
        return {
            is_spanish:
                true,
            reason:
                'confirmed_spanish_hybrid'
        };
    }

    /*
     * Español #1 estadístico: todavía exige estructura mínima propia.
     */
    if (
        statisticalEvidence.top_language
            === SPANISH_CODE
        && (
            spanishEvidence.score >= SPANISH_MIN_SCORE
            || (
                spanishStructure.distinctive_words >= 1
                && spanishStructure.function_words >= 2
                && spanishStructure.verb_hits >= 1
            )
        )
    ) {
        return {
            is_spanish:
                true,
            reason:
                'confirmed_spanish_statistical_supported'
        };
    }

    return {
        is_spanish:
            false,
        reason:
            'spanish_evidence_insufficient'
    };
}


/* ============================================================================
 * 12. RESULTADO ESTABLE
 * ============================================================================ */

/**
 * Construye la respuesta pública del detector.
 *
 * @param {object} values
 * @returns {object}
 */
function buildResult({
    isSpanish,
    language,
    detectedLanguage,
    reason,
    analysis
}) {
    const result = {
        is_spanish:
            Boolean(
                isSpanish
            ),
        continue_pipeline:
            Boolean(
                isSpanish
            ),
        language,
        detected_language:
            detectedLanguage,
        reason
    };

    if (analysis) {
        result.analysis =
            analysis;
    }

    return result;
}


/* ============================================================================
 * 13. DETECTOR PRINCIPAL
 * ============================================================================ */

/**
 * Confirma si un comentario puede tratarse como español dentro de TRAMA.
 *
 * @param {unknown} value
 * @param {{include_analysis?: boolean}} [options]
 * @returns {object}
 */
export function detectCommentLanguage(
    value,
    {
        include_analysis = false
    } = {}
) {
    /* ------------------------------------------------------------------------
     * Fase 1: normalización y preparación.
     * --------------------------------------------------------------------- */

    const normalizedText =
        normalizeText(
            value
        );

    if (!normalizedText) {
        return buildResult({
            isSpanish:
                false,
            language:
                'unknown',
            detectedLanguage:
                UNKNOWN_CODE,
            reason:
                'empty_text',
            analysis:
                include_analysis
                    ? {
                        meaningful_letters:
                            0,
                        total_words:
                            0
                    }
                    : null
        });
    }

    const cleanedText =
        removeLanguageNoise(
            normalizedText
        );

    /*
     * Copia exclusiva para análisis lingüístico. El comentario original no se
     * altera: únicamente normalizamos elongaciones expresivas de 3+ letras.
     */
    const analysisText =
        normalizeExpressiveElongations(
            cleanedText
        );

    const words =
        extractWords(
            analysisText
        );

    const letters =
        countLetters(
            analysisText
        );

    if (
        letters === 0
        || words.length === 0
    ) {
        return buildResult({
            isSpanish:
                false,
            language:
                'unknown',
            detectedLanguage:
                UNKNOWN_CODE,
            reason:
                'language_not_confirmed',
            analysis:
                include_analysis
                    ? {
                        meaningful_letters:
                            letters,
                        total_words:
                            words.length
                    }
                    : null
        });
    }

    /* ------------------------------------------------------------------------
     * Fase 2: escritura no latina.
     * --------------------------------------------------------------------- */

    if (
        FOREIGN_SCRIPT_PATTERN.test(
            analysisText
        )
    ) {
        const spanishEvidence =
            analyzeSpanishEvidence(
                analysisText,
                words
            );

        return buildResult({
            isSpanish:
                false,
            language:
                spanishEvidence.score >= 0.25
                    ? 'mixed'
                    : 'foreign',
            detectedLanguage:
                'non-latin-script',
            reason:
                spanishEvidence.score >= 0.25
                    ? 'mixed_language_detected'
                    : 'foreign_script_detected',
            analysis:
                include_analysis
                    ? {
                        meaningful_letters:
                            letters,
                        spanish_evidence:
                            spanishEvidence,
                        spanish_structure:
                            analyzeSpanishStructure(
                                analysisText,
                                words
                            ),
                        foreign_script:
                            true
                    }
                    : null
        });
    }

    /* ------------------------------------------------------------------------
     * Fase 3: calcular evidencia española y extranjera independiente.
     * --------------------------------------------------------------------- */

    const spanishEvidence =
        analyzeSpanishEvidence(
            analysisText,
            words
        );

    const spanishStructure =
        analyzeSpanishStructure(
            analysisText,
            words
        );

    const foreignEvidence =
        analyzeForeignEvidence(
            analysisText,
            words
        );

    /*
     * Una marca extranjera contrastiva fuerte tiene prioridad absoluta. Si el
     * mismo comentario posee evidencia española, se informa MIXED.
     */
    if (foreignEvidence.strong) {
        const hasSpanishContext =
            spanishEvidence.direct_confirmation
            || spanishEvidence.score
                >= 0.28;

        const foreignLanguage =
            foreignEvidence
                .hard_marker
                ?.language
            ?? foreignEvidence
                .best_language
            ?? 'foreign';

        return buildResult({
            isSpanish:
                false,
            language:
                hasSpanishContext
                    ? 'mixed'
                    : 'foreign',
            detectedLanguage:
                foreignLanguage,
            reason:
                hasSpanishContext
                    ? 'mixed_language_detected'
                    : 'foreign_language_detected',
            analysis:
                include_analysis
                    ? {
                        meaningful_letters:
                            letters,
                        spanish_evidence:
                            spanishEvidence,
                        spanish_structure:
                            spanishStructure,
                        foreign_evidence:
                            foreignEvidence
                    }
                    : null
        });
    }

    /* ------------------------------------------------------------------------
     * Fase 4: señal estadística global.
     * --------------------------------------------------------------------- */

    const statisticalEvidence =
        analyzeStatisticalEvidence(
            analysisText,
            words
        );

    const globalDecision =
        fuseSpanishDecision(
            spanishEvidence,
            spanishStructure,
            statisticalEvidence,
            letters
        );

    if (!globalDecision.is_spanish) {
        const stronglyForeignStatistical =
            statisticalEvidence.available
            && statisticalEvidence
                .top_language
                !== SPANISH_CODE
            && Number(
                statisticalEvidence
                    .spanish_score
                ?? 0
            ) <= 0.55
            && Number(
                statisticalEvidence
                    .best_foreign_score
                ?? 0
            ) - Number(
                statisticalEvidence
                    .spanish_score
                ?? 0
            ) >= 0.28
            && spanishEvidence.score
                < 0.30
            && !spanishStructure.medium_confirmation
            && spanishStructure.distinctive_words < 2;

        return buildResult({
            isSpanish:
                false,
            language:
                stronglyForeignStatistical
                    ? 'foreign'
                    : 'unknown',
            detectedLanguage:
                stronglyForeignStatistical
                    ? statisticalEvidence
                        .best_foreign_language
                        ?? statisticalEvidence
                            .top_language
                    : UNKNOWN_CODE,
            reason:
                stronglyForeignStatistical
                    ? 'foreign_language_detected_statistical'
                    : globalDecision.reason,
            analysis:
                include_analysis
                    ? {
                        meaningful_letters:
                            letters,
                        spanish_evidence:
                            spanishEvidence,
                        spanish_structure:
                            spanishStructure,
                        foreign_evidence:
                            foreignEvidence,
                        statistical_evidence:
                            statisticalEvidence
                    }
                    : null
        });
    }

    /* ------------------------------------------------------------------------
     * Fase 5: scanner MIXED adaptativo V4.
     *
     * Sólo corre después de confirmar español globalmente. Primero busca
     * microcláusulas delimitadas y luego zonas con baja compatibilidad española
     * mediante ventanas 3/4/6/8/12. `francAll` se ejecuta únicamente sobre
     * candidatos sospechosos, no sobre todas las ventanas del comentario.
     * --------------------------------------------------------------------- */

    const microForeignEvidence =
        detectForeignMicroSwitch(
            analysisText,
            words
        );

    if (microForeignEvidence) {
        return buildResult({
            isSpanish:
                false,
            language:
                'mixed',
            detectedLanguage:
                microForeignEvidence.language
                ?? 'foreign',
            reason:
                'mixed_language_detected',
            analysis:
                include_analysis
                    ? {
                        meaningful_letters:
                            letters,
                        spanish_evidence:
                            spanishEvidence,
                        spanish_structure:
                            spanishStructure,
                        foreign_evidence:
                            foreignEvidence,
                        statistical_evidence:
                            statisticalEvidence,
                        micro_foreign_evidence:
                            microForeignEvidence
                    }
                    : null
        });
    }

    /* ------------------------------------------------------------------------
     * Fase 6: detección local de mezcla.
     *
     * Recién se ejecuta después de que el comentario completo tenga evidencia
     * suficiente de español. La finalidad es descubrir un bloque extranjero
     * escondido, no volver a juzgar cada coma del texto.
     * --------------------------------------------------------------------- */

    const mixedEvidence =
        detectMixedLanguage(
            analysisText,
            words
        );

    if (mixedEvidence) {
        return buildResult({
            isSpanish:
                false,
            language:
                'mixed',
            detectedLanguage:
                mixedEvidence.language,
            reason:
                'mixed_language_detected',
            analysis:
                include_analysis
                    ? {
                        meaningful_letters:
                            letters,
                        spanish_evidence:
                            spanishEvidence,
                        spanish_structure:
                            spanishStructure,
                        foreign_evidence:
                            foreignEvidence,
                        statistical_evidence:
                            statisticalEvidence,
                        mixed_evidence:
                            mixedEvidence
                    }
                    : null
        });
    }

    /* ------------------------------------------------------------------------
     * Fase 7: español confirmado.
     * --------------------------------------------------------------------- */

    return buildResult({
        isSpanish:
            true,
        language:
            'es',
        detectedLanguage:
            SPANISH_CODE,
        reason:
            globalDecision.reason,
        analysis:
            include_analysis
                ? {
                    meaningful_letters:
                        letters,
                    spanish_evidence:
                        spanishEvidence,
                    foreign_evidence:
                        foreignEvidence,
                    statistical_evidence:
                        statisticalEvidence
                }
                : null
    });
}


/* ============================================================================
 * 14. CLI
 * ============================================================================ */

/**
 * Lee STDIN completo como UTF-8.
 *
 * @returns {Promise<string>}
 */
async function readStdin() {
    if (process.stdin.isTTY) {
        return '';
    }

    process.stdin.setEncoding(
        'utf8'
    );

    let data =
        '';

    for await (
        const chunk
        of process.stdin
    ) {
        data +=
            chunk;
    }

    return data;
}


/**
 * Ejecuta la interfaz de línea de comandos.
 *
 * @returns {Promise<void>}
 */
async function runCli() {
    const argumentText =
        process.argv
            .slice(2)
            .join(' ')
            .trim();

    const input =
        argumentText
        || (
            await readStdin()
        ).trim();

    const result =
        detectCommentLanguage(
            input,
            {
                include_analysis:
                    true
            }
        );

    process.stdout.write(
        `${JSON.stringify(
            result,
            null,
            2
        )}\n`
    );
}


/*
 * Evita ejecutar el CLI cuando el archivo se importa desde Laravel/Node o una
 * batería de pruebas.
 */
const isDirectExecution =
    process.argv[1]
    && import.meta.url
        === pathToFileURL(
            process.argv[1]
        ).href;

if (isDirectExecution) {
    await runCli();
}
