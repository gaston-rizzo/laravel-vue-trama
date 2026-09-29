/* ============================================================================
 * SCRIPT: classify-comment-toxicity.mjs
 * ============================================================================
 *
 * Clasifica la toxicidad de un comentario mediante una arquitectura híbrida
 * cuyo componente semántico principal es el modelo:
 *
 * onnx-community/distilbert-multilingual-toxicity-classifier-ONNX
 *
 * Se trata de un clasificador multilingüe basado en DistilBERT.
 *
 * TRAMA ejecuta localmente la variante cuantizada del modelo mediante
 * onnxruntime-node.
 *
 * El archivo ONNX utilizado por la aplicación se almacena en:
 *
 * resources/models/comment-toxicity/model_quantized.onnx
 *
 * El modelo neuronal distingue entre dos clases:
 *
 * - not-toxic
 * - toxic
 *
 * Sin embargo, la clasificación final de TRAMA no trata esa predicción como una
 * autoridad absoluta.
 *
 * El modelo constituye una fuente semántica principal de evidencia y se combina
 * con otras señales generales obtenidas del propio texto:
 *
 * - normalización y reconstrucción de técnicas de evasión;
 * - contexto de cita, discurso referido, rechazo y metalenguaje;
 * - detección estructural de ataques personales;
 * - identificación de objetivos humanos y no humanos;
 * - corrección de críticas, emociones y usos figurados dirigidos únicamente
 *   contra contenido, medidas, decisiones, sistemas u otros objetivos no
 *   personales;
 * - manejo explícito de desacuerdos entre el modelo y la estructura lingüística;
 * - estimación interna de certeza e incertidumbre.
 *
 * El objetivo de esta arquitectura es generalizar ante comentarios nuevos y no
 * memorizar frases concretas pertenecientes a una batería de pruebas.
 *
 * ---------------------------------------------------------------------------
 * COMENTARIO ORIGINAL Y REPRESENTACIONES AUXILIARES
 * ---------------------------------------------------------------------------
 *
 * El comentario original que TRAMA recibe desde Laravel nunca se modifica.
 *
 * A partir de ese texto se generan representaciones auxiliares exclusivamente
 * para la clasificación.
 *
 * La normalización produce, entre otras, dos variantes principales:
 *
 * normalizedText:
 *
 *     versión conservadora con normalización Unicode, eliminación de caracteres
 *     de formato invisibles y espacios normalizados;
 *
 * deobfuscatedText:
 *
 *     versión auxiliar donde además se reconstruyen técnicas generales de
 *     evasión que pueden perjudicar la tokenización o el análisis estructural.
 *
 * La normalización de evasiones no busca una oración específica ni una forma
 * concreta de una palabra.
 *
 * Por ejemplo, reconoce el patrón general:
 *
 * letra + separador + letra + separador + letra + ...
 *
 * independientemente del separador utilizado.
 *
 * De esta forma una representación auxiliar puede reconstruir secuencias como:
 *
 * i-d-i-o-t-a
 * i _ d _ i _ o _ t _ a
 * i...d...i...o...t...a
 * i***d***i***o***t***a
 *
 * También contempla otras técnicas generales, como:
 *
 * - grupos densos de separadores dentro de un token;
 * - caracteres Unicode invisibles;
 * - sustituciones alfanuméricas frecuentes de leetspeak;
 * - repeticiones exageradas de una misma letra;
 * - diferencias de mayúsculas y minúsculas.
 *
 * Ejemplos conceptuales de representaciones auxiliares:
 *
 * tarad0      → tarado
 * 1mbéc1l     → imbécil
 * idiooota    → idiota
 * pelotudooo  → pelotudo
 *
 * Estas transformaciones NO significan que el resultado reconstruido sea
 * automáticamente tóxico.
 *
 * La normalización solamente mejora la evidencia disponible para el modelo y
 * para el análisis estructural posterior.
 *
 * Además se registra:
 *
 * - si la normalización modificó la copia de análisis;
 * - qué técnicas de evasión fueron detectadas;
 * - una señal general denominada obfuscation_signal.
 *
 * La evasión por sí sola nunca convierte un comentario en tóxico.
 *
 * ---------------------------------------------------------------------------
 * ANÁLISIS DE CONTEXTO
 * ---------------------------------------------------------------------------
 *
 * Una palabra ofensiva aislada no alcanza para determinar la intención del
 * usuario.
 *
 * Por ejemplo:
 *
 * "Sos un idiota."
 *
 * no cumple la misma función lingüística que:
 *
 * "La palabra 'idiota' aparece en el comentario denunciado."
 *
 * Por ese motivo, antes de utilizar el texto para la decisión final, TRAMA
 * analiza contextos generales donde una expresión potencialmente ofensiva puede
 * aparecer sin constituir un ataque propio del autor del comentario.
 *
 * Entre ellos se distinguen:
 *
 * - quoted_context:
 *     contenido citado con contexto suficiente de referencia;
 *
 * - reported_context:
 *     discurso referido, denuncia, expediente, reporte o documentación de lo
 *     dicho por otra persona;
 *
 * - metalinguistic_context:
 *     análisis de una palabra, expresión, ejemplo, batería, documentación,
 *     moderación o uso lingüístico;
 *
 * - negation_context:
 *     rechazo, negación o desaliento explícito de una descalificación.
 *
 * La negación se interpreta por su función lingüística y no únicamente por la
 * presencia de la palabra "no".
 *
 * Por ejemplo:
 *
 * "No sos un salame."
 *
 * niega una atribución y puede tratarse como rechazo de la descalificación.
 *
 * En cambio:
 *
 * "No seas salame."
 *
 * utiliza "salame" como reproche dirigido al interlocutor. El imperativo no
 * queda protegido como negación y continúa disponible para la detección de
 * ataque personal.
 *
 * Poner una expresión entre comillas no la vuelve automáticamente inocua.
 *
 * La cita solamente se protege cuando el contexto cercano indica que cumple una
 * función de reporte, ejemplo, documentación, análisis o rechazo.
 *
 * El comentario también se divide en cláusulas semánticas pequeñas.
 *
 * Esto permite distinguir casos como:
 *
 * "No corresponde llamarlo idiota, pero el editor es un tarado."
 *
 * La primera cláusula puede reconocerse como rechazo de una descalificación,
 * mientras la segunda continúa disponible como posible ataque real.
 *
 * De esta manera TRAMA evita dos errores opuestos:
 *
 * - considerar tóxica toda mención de una palabra ofensiva;
 * - neutralizar un comentario completo solamente porque una parte contiene una
 *   cita, negación o explicación legítima.
 *
 * La protección contextual se aplica exclusivamente a las copias de análisis.
 * El comentario original nunca se altera.
 *
 * ---------------------------------------------------------------------------
 * MODELO NEURONAL
 * ---------------------------------------------------------------------------
 *
 * Después de preparar las representaciones auxiliares, el texto se tokeniza
 * utilizando el tokenizer oficial del modelo.
 *
 * Tokenizar significa transformar el texto escrito por una persona en una
 * representación numérica que DistilBERT pueda procesar.
 *
 * Por ejemplo, un comentario como:
 *
 * "No estoy de acuerdo."
 *
 * puede separarse internamente en tokens conceptualmente semejantes a:
 *
 * [CLS] No esto ##y de acuerdo . [SEP]
 *
 * Cada token se convierte posteriormente en un identificador numérico.
 *
 * Junto con esos identificadores se genera una attention_mask, que indica qué
 * posiciones de la secuencia contienen información válida.
 *
 * El script ejecuta la inferencia mediante onnxruntime-node utilizando el modelo
 * ONNX almacenado localmente.
 *
 * El modelo devuelve logits para sus dos clases.
 *
 * Los logits son puntuaciones internas que todavía no representan probabilidades.
 *
 * Conceptualmente podrían ser:
 *
 * not-toxic → 4.8
 * toxic     → -1.3
 *
 * El script convierte esos valores mediante Softmax.
 *
 * Softmax produce probabilidades comprendidas entre 0 y 1 cuya suma es
 * aproximadamente 1.
 *
 * Por ejemplo:
 *
 * not-toxic → 0.9978
 * toxic     → 0.0022
 *
 * es decir:
 *
 * not-toxic → 99.78 %
 * toxic     → 0.22 %
 *
 * El modelo no analiza únicamente el comentario completo.
 *
 * Para evitar que un ataque breve quede diluido dentro de mucho texto legítimo,
 * TRAMA construye unidades de análisis formadas por:
 *
 * - el comentario completo;
 * - cláusulas o segmentos semánticos;
 * - ventanas superpuestas cuando un segmento resulta demasiado extenso.
 *
 * Estas unidades se generan tanto desde la representación normalizada como desde
 * la representación deobfuscada, evitando duplicados.
 *
 * Además, para cada unidad se compara:
 *
 * - la versión conservando su capitalización;
 * - una segunda variante completamente en minúsculas, cuando ambas son distintas.
 *
 * Para evitar ejecutar una llamada ONNX independiente por cada variante, TRAMA
 * agrupa las secuencias pendientes en lotes pequeños. Antes de cada lote las
 * secuencias se ordenan por longitud y se rellenan únicamente hasta la longitud
 * máxima del grupo, utilizando attention_mask para ignorar el padding. Si un
 * export ONNX concreto no admite batch dinámico, el mismo mecanismo conserva
 * compatibilidad degradando automáticamente a inferencias individuales.
 *
 * El proceso también mantiene una cache LRU acotada de inferencias exactas. Si
 * una representación idéntica ya fue procesada durante la misma ejecución, su
 * resultado neuronal se reutiliza sin volver a ejecutar DistilBERT. Esta cache no
 * modifica scores ni canonicaliza el texto: solo evita trabajo neuronal repetido.
 *
 * Se conserva como model_toxicity_score la mayor probabilidad de toxicidad
 * producida por el modelo entre todas las unidades y variantes analizadas.
 *
 * Si TRAMA neutralizó contexto protegido, también conserva para auditoría
 * raw_model_toxicity_score.
 *
 * Ese campo permite observar qué habría producido el modelo sobre la
 * representación normalizada previa a la protección contextual.
 *
 * Por lo tanto:
 *
 * model_toxicity_score:
 *     señal neuronal utilizada por la arquitectura después de preparar el
 *     contexto;
 *
 * raw_model_toxicity_score:
 *     referencia neuronal previa a esa protección cuando fue necesario
 *     calcularla.
 *
 * ---------------------------------------------------------------------------
 * DETECCIÓN ESTRUCTURAL DE ATAQUES
 * ---------------------------------------------------------------------------
 *
 * Después de la inferencia neuronal, TRAMA analiza estructuras lingüísticas
 * generales sobre el contenido que NO quedó protegido por contexto.
 *
 * Esta capa no intenta memorizar oraciones completas.
 *
 * Busca relaciones conceptuales como:
 *
 * [OBJETIVO HUMANO]
 *        +
 * [CÓPULA / ATRIBUCIÓN]
 *        +
 * [DESCALIFICACIÓN PERSONAL]
 *
 * o:
 *
 * [SEGUNDA PERSONA]
 *        +
 * [ATRIBUCIÓN / DEGRADACIÓN]
 *
 * También contempla estructuras generales de:
 *
 * - vocativo ofensivo;
 * - ataque contra un grupo o referencia humana;
 * - ataque retórico personal;
 * - degradación de capacidad o competencia;
 * - profanidad dirigida;
 * - objetivo humano y predicado descalificador dentro de la misma cláusula;
 * - agentes humanos implícitos asociados a una obra o contenido;
 * - preguntas retóricas que degradan a una persona sin nombrarla explícitamente;
 * - imperativos despectivos, incluido "no seas + descalificación personal";
 * - ataques contra grupos humanos;
 * - referencias anafóricas cuyo sujeto humano aparece en la cláusula anterior;
 * - ruido de puntuación repetida que intenta separar una atribución;
 * - omisión de una letra o repetición adyacente dentro de un predicado personal.
 *
 * Estas familias se describen mediante relaciones lingüísticas generales. Por
 * ejemplo, "este artículo lo escribió un idiota" no se protege como crítica al
 * artículo: la descalificación recae sobre el agente humano que lo escribió.
 *
 * La capa estructural genera, entre otras señales:
 *
 * direct_attack_signal:
 *     intensidad estructural del posible ataque personal;
 *
 * attack_types:
 *     familias estructurales observadas;
 *
 * personal_target:
 *     existencia de un objetivo humano o de segunda persona;
 *
 * non_personal_target:
 *     existencia de un objetivo como nota, argumento, metodología, medida,
 *     política, decisión, resultado u otro objeto no humano;
 *
 * derogatory_lexeme:
 *     presencia de un predicado descalificador reconocido por la capa
 *     estructural;
 *
 * directed_profanity:
 *     presencia de profanidad dirigida a una persona.
 *
 * El léxico estructural no funciona como una lista automática de palabras
 * prohibidas.
 *
 * Su presencia aislada no determina la clasificación.
 *
 * El modelo neuronal continúa captando expresiones semánticas que nunca fueron
 * programadas en estas estructuras.
 *
 * ---------------------------------------------------------------------------
 * CRÍTICA NO PERSONAL
 * ---------------------------------------------------------------------------
 *
 * El modelo puede producir scores muy altos ante críticas fuertes que no atacan
 * a ninguna persona.
 *
 * Por ejemplo:
 *
 * "La cobertura es débil."
 * "Esta decisión es absurda."
 * "La metodología es un desastre."
 * "Me indigna esta demora."
 * "Este bug está matando el rendimiento."
 *
 * Esas expresiones pueden ser negativas, emocionales o figuradas sin constituir
 * una descalificación personal.
 *
 * La protección también contempla emociones en primera persona cuando el objeto
 * de esa emoción es inequívocamente una política, demora, decisión, sistema,
 * problema u otro objetivo no humano.
 *
 * Del mismo modo, verbos de daño utilizados figuradamente sobre software,
 * documentos, proyectos, consumo u otros objetos no se interpretan como ataques
 * contra personas.
 *
 * La arquitectura intenta reconocer cuándo el comentario completo está anclado
 * en un objetivo no humano y no existe una señal estructural de ataque personal.
 *
 * Solamente en ese caso puede limitar un score neuronal artificialmente alto.
 *
 * En cambio:
 *
 * "Ese periodista es un desastre."
 *
 * contiene un objetivo humano y no recibe esa protección.
 *
 * ---------------------------------------------------------------------------
 * FUSIÓN DE EVIDENCIAS
 * ---------------------------------------------------------------------------
 *
 * La clasificación final no utiliza una única regla del tipo:
 *
 * model_score >= 0.50
 *
 * Tampoco reemplaza al modelo por una colección de expresiones regulares.
 *
 * La arquitectura fusiona varias fuentes de evidencia:
 *
 * - model_toxicity_score;
 * - direct_attack_signal;
 * - obfuscation_signal;
 * - contexto protegido;
 * - objetivo personal o no personal;
 * - crítica no personal;
 * - conflicto entre la evidencia neuronal y la evidencia estructural.
 *
 * Cuando existe una estructura personal clara, la señal estructural puede elevar
 * un score neuronal demasiado bajo.
 *
 * Conceptualmente:
 *
 * model_toxicity_score: 0.04
 * direct_attack_signal: 1.00
 * personal_target: true
 * quoted_context: false
 *
 * puede terminar en una clasificación tóxica aunque el modelo neuronal haya
 * subestimado el ataque.
 *
 * La contribución estructural se combina con la neuronal de forma gradual. No se
 * sustituye el score por un valor fijo simplemente porque una regla coincida.
 *
 * La señal de obfuscación solamente aumenta el riesgo cuando también existe una
 * estructura personal suficiente.
 *
 * Por otro lado, un score neuronal alto puede reducirse cuando existe evidencia
 * clara de:
 *
 * - crítica exclusivamente no personal;
 * - cita protegida;
 * - discurso referido;
 * - metalenguaje;
 * - rechazo o negación de una descalificación.
 *
 * El score final continúa disponible como toxicity_score.
 *
 * El valor neuronal original no se pierde y permanece en los metadatos de
 * analysis.
 *
 * ---------------------------------------------------------------------------
 * INCERTIDUMBRE
 * ---------------------------------------------------------------------------
 *
 * La arquitectura no asume que todo comentario sea inequívoco.
 *
 * Internamente distingue tres estados de certeza:
 *
 * - clear-toxic;
 * - uncertain;
 * - clear-not-toxic.
 *
 * La propiedad analysis.uncertain indica si la decisión se encuentra en una
 * zona fronteriza o si existen evidencias importantes en conflicto.
 *
 * analysis.review_recommended permite que una capa posterior de moderación
 * considere una revisión humana.
 *
 * Esta incertidumbre no agrega una tercera clase pública.
 *
 * Hacia Laravel, classification continúa siendo solamente:
 *
 * - toxic
 * - not-toxic
 *
 * utilizando el umbral final de toxicidad configurado por el clasificador.
 *
 * La política de moderación puede decidir posteriormente qué hacer con un caso
 * incierto.
 *
 * ---------------------------------------------------------------------------
 * SALIDA JSON
 * ---------------------------------------------------------------------------
 *
 * El script devuelve un JSON estructurado.
 *
 * Ejemplo conceptual abreviado:
 *
 * {
 *     "classification": "toxic",
 *     "toxicity_score": 0.84,
 *     "scores": {
 *         "not-toxic": 0.16,
 *         "toxic": 0.84
 *     },
 *     "score_type": "hybrid",
 *     "analysis": {
 *         "architecture_version": "contextual-target-scope-fusion-v4.1",
 *         "model_toxicity_score": 0.12,
 *         "raw_model_toxicity_score": 0.12,
 *         "strongest_unit_kind": "segment",
 *         "strongest_unit_index": 2,
 *         "strongest_unit_representation": "deobfuscated",
 *         "analysis_unit_count": 8,
 *         "model_variant_count": 12,
 *         "model_batch_count": 2,
 *         "model_cache_hits": 3,
 *         "model_unique_inference_count": 9,
 *         "normalization_changed": true,
 *         "obfuscation_signal": 0.85,
 *         "obfuscation_types": ["internal-separators"],
 *         "context_adjusted": false,
 *         "context_protection_score": 0,
 *         "quoted_context": false,
 *         "reported_context": false,
 *         "metalinguistic_context": false,
 *         "negation_context": false,
 *         "direct_attack_signal": 0.95,
 *         "direct_attack_reinforced": true,
 *         "attack_types": ["human-copular-attribution"],
 *         "personal_target": true,
 *         "non_personal_target": false,
 *         "non_personal_criticism": false,
 *         "model_structural_conflict": true,
 *         "certainty": "clear-toxic",
 *         "uncertain": false,
 *         "review_recommended": false
 *     }
 * }
 *
 * Significado de los campos principales:
 *
 * classification:
 *
 *     clasificación binaria final utilizada por TRAMA.
 *
 * toxicity_score:
 *
 *     score FINAL después de la fusión de evidencias. No debe interpretarse
 *     automáticamente como la probabilidad pura producida por DistilBERT.
 *
 * scores:
 *
 *     contiene los valores finales complementarios "not-toxic" y "toxic".
 *
 * score_type:
 *
 *     "model" cuando la salida final no necesitó intervención de las capas
 *     híbridas;
 *
 *     "hybrid" cuando intervinieron normalización, contexto, estructura o una
 *     corrección propia de TRAMA.
 *
 * analysis.model_toxicity_score:
 *
 *     mayor score tóxico utilizado desde las inferencias neuronales efectuadas
 *     sobre las unidades contextualmente preparadas.
 *
 * analysis.raw_model_toxicity_score:
 *
 *     score neuronal previo a la protección contextual cuando esa comparación
 *     fue necesaria para auditoría.
 *
 * analysis.strongest_unit_kind:
 *
 *     puede ser:
 *
 *     - full;
 *     - segment;
 *     - window.
 *
 * analysis.strongest_unit_representation:
 *
 *     indica si la mayor señal neuronal provino de la representación
 *     "normalized" o "deobfuscated".
 *
 * analysis.analysis_unit_count:
 *
 *     cantidad total de unidades construidas para el análisis, después de
 *     eliminar duplicados y respetar el límite defensivo.
 *
 * analysis.model_variant_count:
 *
 *     cantidad de variantes neuronales consideradas, incluyendo diferencias de
 *     capitalización y, cuando corresponde, la referencia cruda de auditoría.
 *
 * analysis.model_batch_count:
 *
 *     cantidad de lotes ONNX realmente ejecutados para ese comentario. Puede ser
 *     mucho menor que model_variant_count.
 *
 * analysis.model_cache_hits:
 *
 *     cantidad de variantes resueltas desde la cache de inferencias sin ejecutar
 *     nuevamente el modelo.
 *
 * analysis.model_unique_inference_count:
 *
 *     cantidad de representaciones únicas que necesitaron una inferencia nueva.
 *
 * analysis.obfuscation_signal:
 *
 *     evidencia general de técnicas de evasión detectadas durante la
 *     normalización.
 *
 * analysis.context_protection_score:
 *
 *     proporción aproximada de contenido protegido por contexto dentro de las
 *     representaciones analizadas.
 *
 * analysis.direct_attack_signal:
 *
 *     intensidad de la evidencia estructural de ataque personal.
 *
 * analysis.model_structural_conflict:
 *
 *     indica un desacuerdo fuerte entre el score neuronal y la estructura o las
 *     correcciones contextuales.
 *
 * analysis.certainty:
 *
 *     nivel interno de certeza: clear-toxic, uncertain o clear-not-toxic.
 *
 * analysis.review_recommended:
 *
 *     marca casos donde una futura política de moderación puede preferir una
 *     revisión humana.
 *
 * ---------------------------------------------------------------------------
 * RESPONSABILIDAD DE ESTE SCRIPT
 * ---------------------------------------------------------------------------
 *
 * Este script solamente clasifica toxicidad y expone las señales utilizadas para
 * llegar a la decisión.
 *
 * No decide por sí mismo si el comentario debe quedar:
 *
 * - approved;
 * - pending;
 * - rejected.
 *
 * Esa decisión corresponde a la política de moderación de TRAMA implementada en
 * la capa que consume esta clasificación.
 *
 * El manejo de incertidumbre permite precisamente que esa política pueda enviar
 * ciertos casos a revisión humana sin forzar al clasificador a fingir una
 * certeza que no posee.
 *
 * ---------------------------------------------------------------------------
 * FLUJO GENERAL
 * ---------------------------------------------------------------------------
 *
 * Todo el procesamiento se realiza localmente:
 *
 * comentario original
 *      ↓
 * normalización Unicode y copia conservadora
 *      ↓
 * reconstrucción auxiliar de técnicas de evasión
 *      ↓
 * análisis de cita / reporte / metalenguaje / negación
 *      ↓
 * protección selectiva del contexto legítimo
 *      ↓
 * comentario completo + segmentos + ventanas largas
 *      ↓
 * representación normalizada + representación deobfuscada
 *      ↓
 * variante original + variante en minúsculas
 *      ↓
 * deduplicación + cache de inferencias exactas
 *      ↓
 * agrupación por longitud + batches ONNX
 *      ↓
 * inferencias DistilBERT ONNX
 *      ↓
 * agregación neuronal contextual con ancla en el comentario completo
 *      ↓
 * detección estructural de ataque y tipo de objetivo
 *      ↓
 * corrección contextual / crítica no personal
 *      ↓
 * fusión de evidencias
 *      ↓
 * toxic / not-toxic + certeza + metadatos de auditoría
 *      ↓
 * JSON para Laravel
 *
 * No utiliza APIs externas ni envía el contenido del comentario fuera del
 * servidor donde se ejecuta TRAMA.
 * ============================================================================ */

import fs from 'node:fs';
import path from 'node:path';
import process from 'node:process';
import { pathToFileURL } from 'node:url';

import * as ort from 'onnxruntime-node';
import { Tokenizer } from '@huggingface/tokenizers';

/* ============================================================================
 * RUTAS Y MODELO
 * ============================================================================ */

/*
 * Obtiene la carpeta raíz desde la que se está ejecutando Node.
 *
 * El script debe ejecutarse desde la raíz del proyecto TRAMA para que las rutas
 * relativas hacia resources/models puedan resolverse correctamente.
 */
const ROOT_PATH = process.cwd();

/*
 * Identificador público exacto del modelo utilizado por TRAMA.
 *
 * Se conserva como constante documental para poder identificar el modelo desde
 * el propio código sin depender únicamente de la estructura de carpetas local.
 */
const MODEL_NAME =
    'onnx-community/distilbert-multilingual-toxicity-classifier-ONNX';

/*
 * Ruta donde se encuentran todos los archivos locales necesarios para ejecutar
 * el clasificador.
 *
 * Estructura esperada:
 *
 * resources/
 * └── models/
 *     └── comment-toxicity/
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
    'comment-toxicity'
);

/*
 * tokenizer.json contiene la definición completa del tokenizer: vocabulario,
 * normalización, WordPiece, tokens especiales y procesamiento asociado.
 */
const tokenizerJson = JSON.parse(
    fs.readFileSync(
        path.join(MODEL_PATH, 'tokenizer.json'),
        'utf8'
    )
);

/*
 * tokenizer_config.json contiene configuración complementaria del tokenizer.
 */
const tokenizerConfig = JSON.parse(
    fs.readFileSync(
        path.join(MODEL_PATH, 'tokenizer_config.json'),
        'utf8'
    )
);

/*
 * config.json describe el modelo y, entre otras cosas, permite resolver las
 * etiquetas producidas por la red sin duplicarlas manualmente.
 */
const modelConfig = JSON.parse(
    fs.readFileSync(
        path.join(MODEL_PATH, 'config.json'),
        'utf8'
    )
);

/*
 * Crea una única instancia reutilizable del tokenizer.
 *
 * Una red neuronal no procesa directamente palabras: el tokenizer convierte el
 * comentario en identificadores numéricos y máscaras de atención.
 */
const tokenizer = new Tokenizer(
    tokenizerJson,
    tokenizerConfig
);

/*
 * Carga una única vez la variante cuantizada del modelo mediante
 * onnxruntime-node.
 *
 * Mantener una sola sesión evita recargar el archivo ONNX para cada comentario.
 */
const session = await ort.InferenceSession.create(
    path.join(MODEL_PATH, 'model_quantized.onnx')
);

/*
 * El identificador de padding se obtiene de la configuración del modelo o del
 * tokenizer. El valor 0 queda únicamente como último respaldo defensivo.
 */
const PAD_TOKEN_ID =
    Number(
        modelConfig.pad_token_id
        ?? tokenizerJson.padding?.pad_id
        ?? 0
    );

/* ============================================================================
 * CONFIGURACIÓN GENERAL
 * ============================================================================ */

const TOXICITY_THRESHOLD = 0.50;

const LONG_SEGMENT_WINDOW_WORDS = 36;
const LONG_SEGMENT_OVERLAP_WORDS = 10;
const MAX_ANALYSIS_UNITS = 32;

/*
 * Las inferencias de un mismo comentario se agrupan en lotes pequeños. De esta
 * forma varias unidades comparten una sola llamada a ONNX Runtime en lugar de
 * ejecutar `session.run()` de manera secuencial para cada una.
 */
/*
 * El tamaño puede ajustarse desde una batería sin modificar el archivo:
 * TRAMA_MODEL_BATCH_SIZE=16 (1..64).
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
 * Cache LRU acotada para representaciones repetidas.
 *
 * Resulta especialmente útil cuando distintos comentarios contienen segmentos
 * idénticos —por ejemplo cierres, citas o frases breves— y evita repetir una
 * inferencia neuronal determinista que ya fue calculada en este proceso.
 */
/*
 * También puede ampliarse durante benchmarks combinatorios:
 * TRAMA_MODEL_CACHE_MAX=24000 (1000..100000).
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
 * Umbrales de certeza internos.
 *
 * No sustituyen TOXICITY_THRESHOLD. Solamente permiten distinguir una decisión
 * clara de una clasificación que conviene revisar manualmente.
 */
const CLEAR_NOT_TOXIC_MAX = 0.40;
const UNCERTAIN_LOW = 0.40;
const UNCERTAIN_HIGH = 0.62;
const CLEAR_TOXIC_MIN = 0.70;

/*
 * Las críticas dirigidas inequívocamente a una decisión, artículo, política,
 * metodología u otro objetivo no humano pueden recibir scores neuronales muy
 * altos. Este límite se aplica únicamente cuando no existe señal personal.
 */
const NON_PERSONAL_CRITICISM_SCORE_CAP = 0.30;
const LOCAL_SEGMENT_UNGROUNDED_WEIGHT = 0.24;
const LOCAL_SEGMENT_PERSONAL_WEIGHT = 0.72;
const LOCAL_SEGMENT_LONG_WEIGHT = 0.42;
const LOCAL_SEGMENT_SHORT_WEIGHT = 0.16;
const AFFECTIONATE_CONTEXT_SCORE_CAP = 0.24;

/* ============================================================================
 * UTILIDADES NUMÉRICAS Y DE TEXTO
 * ============================================================================ */

/**
 * Limita un valor numérico al intervalo inclusivo 0..1.
 *
 * Se utiliza antes de exponer o fusionar scores para evitar que una operación
 * aritmética intermedia produzca valores fuera del rango de probabilidad.
 *
 * @param {number} value
 * @returns {number}
 */
function clamp01(value) {
    return Math.min(
        1,
        Math.max(0, value)
    );
}

/**
 * Normaliza y redondea un score a seis decimales para mantener una salida JSON
 * estable y suficientemente precisa para auditoría.
 *
 * @param {number} value
 * @returns {number}
 */
function roundScore(value) {
    return Math.round(
        clamp01(value) * 1000000
    ) / 1000000;
}

/**
 * Genera una representación canónica destinada exclusivamente al análisis
 * estructural. Elimina diferencias irrelevantes de acentos y capitalización,
 * normaliza comillas y espacios, pero nunca modifica el comentario original.
 *
 * @param {string} value
 * @returns {string}
 */
function canonicalizeForStructure(value) {
    return String(value)
        .normalize('NFD')
        .replace(/\p{M}+/gu, '')
        .toLocaleLowerCase('es')
        .replace(/[\u2018\u2019\u201C\u201D]/gu, '"')
        .replace(/\s+/gu, ' ')
        .trim();
}

/**
 * Elimina cadenas vacías y representaciones duplicadas conservando el orden de
 * aparición. Evita enviar dos veces la misma variante al modelo.
 *
 * @param {string[]} values
 * @returns {string[]}
 */
function uniqueStrings(values) {
    const seen = new Set();
    const result = [];

    for (const value of values) {
        const normalized = String(value ?? '').trim();

        if (!normalized || seen.has(normalized)) {
            continue;
        }

        seen.add(normalized);
        result.push(normalized);
    }

    return result;
}

/* ============================================================================
 * 1. NORMALIZACIÓN ROBUSTA
 * ============================================================================ */

/*
 * Conversión de leetspeak utilizada únicamente para crear una representación
 * auxiliar. No reemplaza números en el comentario original ni afirma que toda
 * palabra con números sea ofensiva.
 */
const LEETSPEAK_PRIMARY_MAP = Object.freeze({
    '0': 'o',
    '1': 'i',
    '2': 'z',
    '3': 'e',
    '4': 'a',
    '5': 's',
    '6': 'g',
    '7': 't',
    '8': 'b',
    '9': 'g',
    '@': 'a',
    '$': 's',
    '!': 'i'
});

/*
 * Detecta secuencias del tipo:
 *
 * i-d-i-o-t-a
 * i _ d _ i _ o _ t _ a
 * i...d...i...o...t...a
 * i***d***i***o***t***a
 *
 * No depende de una palabra concreta. La secuencia compactada se utiliza como
 * representación auxiliar para el modelo y para el análisis estructural.
 */
const PUNCTUATED_SEPARATED_CHARACTER_RUN_PATTERN =
    /(?<![\p{L}\p{N}])(?:[\p{L}\p{N}](?:\p{Z}*[\p{P}\p{S}_]+\p{Z}*)+){2,}[\p{L}\p{N}](?![\p{L}\p{N}])/gu;

/*
 * Variante exclusivamente espaciada. Se exige un mínimo de cuatro caracteres
 * para no compactar secuencias breves normales del tipo "A B C".
 *
 * Las secuencias con puntuación/símbolos se resuelven primero mediante el patrón
 * anterior. Esta separación evita que, por ejemplo, "r-i-d-i-c-u-l-o y ..."
 * termine reconstruido como "ridiculoy": el espacio posterior ya no forma parte
 * del mismo mecanismo de evasión que los guiones internos.
 */
const WHITESPACE_SEPARATED_CHARACTER_RUN_PATTERN =
    /(?<![\p{L}\p{N}])(?:[\p{L}\p{N}]\p{Z}+){3,}[\p{L}\p{N}](?![\p{L}\p{N}])/gu;

/*
 * Detecta tokens con varios grupos internos de símbolos, por ejemplo:
 *
 * id__io__ta
 * im...be...cil
 * pe***lo***tudo
 *
 * De nuevo, la regla describe la técnica de separación, no una palabra.
 */
const DENSE_INTERNAL_SEPARATOR_PATTERN =
    /(?<![\p{L}\p{N}])[\p{L}\p{N}](?:[\p{L}\p{N}]|[\p{P}\p{S}_]+){3,}[\p{L}\p{N}](?![\p{L}\p{N}])/gu;

/**
 * Reconstruye secuencias de caracteres individuales separados repetidamente por
 * espacios, puntuación o símbolos.
 *
 * La regla describe la técnica de evasión y no una palabra concreta. También
 * devuelve una intensidad de obfuscación para la etapa de fusión.
 *
 * @param {string} text
 * @returns {{text: string, changed: boolean, signal: number}}
 */
function compactSeparatedCharacterRuns(text) {
    let changed = false;
    let strongestSignal = 0;

    const compactMatch = (
        match,
        minimumCharacters,
        signal
    ) => {
        const characters =
            match.match(/[\p{L}\p{N}]/gu)
            ?? [];

        if (
            characters.length < minimumCharacters
            || characters.length > 32
        ) {
            return match;
        }

        changed = true;
        strongestSignal = Math.max(
            strongestSignal,
            signal
        );

        return characters.join('');
    };

    /*
     * Primero reconstruimos secuencias separadas por signos. Además de ser una
     * señal de evasión más fuerte, esto delimita correctamente palabras cortas
     * como "g-i-l" y evita absorber una conjunción o palabra posterior.
     */
    let result = String(text).replace(
        PUNCTUATED_SEPARATED_CHARACTER_RUN_PATTERN,
        (match) =>
            compactMatch(
                match,
                3,
                1
            )
    );

    /*
     * Después tratamos la variante formada únicamente por espacios. Aquí se
     * conserva un mínimo más estricto porque el patrón puede aparecer en siglas,
     * iniciales o enumeraciones legítimas.
     */
    result = result.replace(
        WHITESPACE_SEPARATED_CHARACTER_RUN_PATTERN,
        (match) => {
            const characters =
                match.match(/[\p{L}\p{N}]/gu)
                ?? [];

            return compactMatch(
                match,
                4,
                characters.length >= 5
                    ? 0.65
                    : 0.35
            );
        }
    );

    return {
        text: result,
        changed,
        signal: strongestSignal
    };
}

/**
 * Reduce separadores internos densos dentro de tokens cuando el patrón indica
 * una alteración deliberada de la forma escrita. La representación resultante
 * es auxiliar y no reemplaza el texto almacenado por TRAMA.
 *
 * @param {string} text
 * @returns {{text: string, changed: boolean, signal: number}}
 */
function compactDenseInternalSeparators(text) {
    let changed = false;
    let strongestSignal = 0;

    const result = text.replace(
        DENSE_INTERNAL_SEPARATOR_PATTERN,
        (match) => {
            const separatorGroups =
                match.match(/[\p{P}\p{S}_]+/gu)
                ?? [];

            const alphanumericCount =
                (
                    match.match(/[\p{L}\p{N}]/gu)
                    ?? []
                ).length;

            if (
                separatorGroups.length < 2
                || alphanumericCount < 4
                || alphanumericCount > 40
            ) {
                return match;
            }

            changed = true;
            strongestSignal = Math.max(
                strongestSignal,
                0.85
            );

            return match.replace(
                /[\p{P}\p{S}_]+/gu,
                ''
            );
        }
    );

    return {
        text: result,
        changed,
        signal: strongestSignal
    };
}

/**
 * Genera una variante auxiliar corrigiendo sustituciones numéricas frecuentes
 * de leetspeak cuando aparecen mezcladas con letras.
 *
 * La conversión no constituye una decisión de toxicidad: solamente mejora la
 * representación disponible para el modelo y las reglas estructurales.
 *
 * @param {string} text
 * @returns {{text: string, changed: boolean, signal: number}}
 */
function normalizeMixedLeetspeak(text) {
    let changed = false;

    /*
     * Conectores muy cortos pueden quedar fuera del patrón general de tokens
     * mixtos. `d3` es la evasión habitual de `de` dentro de expresiones
     * compuestas (por ejemplo, "c4b3z4 d3 73rm0"). Se corrige únicamente en la
     * representación auxiliar; no modifica el comentario original.
     */
    const connectorNormalized =
        String(text).replace(
            /\bd3\b/gu,
            () => {
                changed = true;
                return 'de';
            }
        );

    const result = connectorNormalized.replace(
        /(?<![\p{L}\p{N}@$!])[\p{L}\p{N}@$!]{3,32}(?![\p{L}\p{N}@$!])/gu,
        (token) => {
            const characters =
                Array.from(token);

            const hasLetter =
                /\p{L}/u.test(token);

            const hasEncodedCharacter =
                /[\p{N}@$!]/u.test(token);

            if (
                !hasLetter
                || !hasEncodedCharacter
            ) {
                return token;
            }

            const reconstructed =
                characters
                    .map(
                        (
                            character,
                            index
                        ) => {
                            /*
                             * "!" al final de una palabra suele ser puntuación
                             * ordinaria. Solo se interpreta como sustitución de
                             * "i" cuando aparece dentro o al inicio del token.
                             */
                            if (
                                character === '!'
                                && index === characters.length - 1
                            ) {
                                return character;
                            }

                            return (
                                LEETSPEAK_PRIMARY_MAP[character]
                                ?? character
                            );
                        }
                    )
                    .join('');

            if (reconstructed === token) {
                return token;
            }

            changed = true;
            return reconstructed;
        }
    );

    return {
        text: result,
        changed,
        signal: changed ? 0.75 : 0
    };
}

/**
 * Reduce repeticiones exageradas de caracteres dentro de tokens para obtener una
 * variante más cercana a su forma léxica normal, por ejemplo alargamientos
 * expresivos utilizados para dificultar la tokenización.
 *
 * @param {string} text
 * @returns {{text: string, changed: boolean, signal: number}}
 */
function collapseExaggeratedCharacterRuns(text) {
    const characters = Array.from(text);
    const result = [];
    let changed = false;

    let index = 0;

    while (index < characters.length) {
        const current = characters[index];
        const currentKey =
            canonicalizeForStructure(current);

        let end = index + 1;

        while (
            end < characters.length
            && canonicalizeForStructure(characters[end])
                === currentKey
            && /\p{L}/u.test(current)
            && /\p{L}/u.test(characters[end])
        ) {
            end += 1;
        }

        const runLength = end - index;

        if (runLength >= 3) {
            result.push(current);
            changed = true;
        } else {
            for (
                let copyIndex = index;
                copyIndex < end;
                copyIndex += 1
            ) {
                result.push(characters[copyIndex]);
            }
        }

        index = end;
    }

    return {
        text: result.join(''),
        changed,
        signal: changed ? 0.55 : 0
    };
}

/*
 * Genera dos representaciones:
 *
 * normalizedText:
 *     copia conservadora, con Unicode normalizado y caracteres invisibles fuera.
 *
 * deobfuscatedText:
 *     copia auxiliar donde además se reconstruyen técnicas genéricas de evasión.
 *
 * El comentario original nunca se modifica.
 */
/**
 * Ejecuta la normalización robusta y conserva varias representaciones del mismo
 * comentario: original normalizado, deobfuscado y canónico.
 *
 * Además resume qué mecanismos modificaron la copia de análisis y calcula una
 * señal general de evasión.
 *
 * @param {string} text
 * @returns {object}
 */
function buildNormalizationBundle(text) {
    const originalText = String(text ?? '');

    let normalizedText =
        originalText.normalize('NFKC');

    const beforeFormatRemoval = normalizedText;

    normalizedText = normalizedText
        .replace(/\p{Cf}+/gu, '')
        .replace(/\s+/gu, ' ')
        .trim();

    const unicodeFormatRemoved =
        beforeFormatRemoval !== normalizedText;

    let deobfuscatedText = normalizedText;

    const spaced =
        compactSeparatedCharacterRuns(
            deobfuscatedText
        );

    deobfuscatedText = spaced.text;

    const dense =
        compactDenseInternalSeparators(
            deobfuscatedText
        );

    deobfuscatedText = dense.text;

    const leetspeak =
        normalizeMixedLeetspeak(
            deobfuscatedText
        );

    deobfuscatedText = leetspeak.text;

    const repeated =
        collapseExaggeratedCharacterRuns(
            deobfuscatedText
        );

    deobfuscatedText = repeated.text
        .replace(/\s+/gu, ' ')
        .trim();

    const obfuscationTypes = [];

    if (unicodeFormatRemoved) {
        obfuscationTypes.push('unicode-format');
    }

    if (spaced.changed) {
        obfuscationTypes.push('separated-characters');
    }

    if (dense.changed) {
        obfuscationTypes.push('internal-separators');
    }

    if (leetspeak.changed) {
        obfuscationTypes.push('leetspeak');
    }

    if (repeated.changed) {
        obfuscationTypes.push('character-repetition');
    }

    const obfuscationSignal =
        Math.max(
            unicodeFormatRemoved ? 0.65 : 0,
            spaced.signal,
            dense.signal,
            leetspeak.signal,
            repeated.signal
        );

    return {
        originalText,
        normalizedText,
        deobfuscatedText,
        canonicalText:
            canonicalizeForStructure(
                deobfuscatedText
            ),
        changed:
            originalText !== normalizedText
            || normalizedText !== deobfuscatedText,
        obfuscation_signal:
            roundScore(obfuscationSignal),
        obfuscation_types:
            obfuscationTypes
    };
}

/*
 * Devuelve la representación auxiliar más robusta utilizada por el análisis de
 * toxicidad.
 *
 * La función obtiene el conjunto completo de representaciones normalizadas y
 * devuelve específicamente `deobfuscatedText`, que incorpora la reconstrucción
 * auxiliar de técnicas generales de evasión.
 *
 * Esta representación se utiliza únicamente para el análisis. El comentario
 * original recibido por TRAMA nunca se modifica.
 *
 * @param {string} text
 * @returns {string}
 */
function normalizeTextForAnalysis(text) {
    return buildNormalizationBundle(text)
        .deobfuscatedText;
}

/* ============================================================================
 * 2. ANÁLISIS DE CONTEXTO
 * ============================================================================ */

/*
 * Estas listas representan clases lingüísticas. No contienen oraciones de la
 * batería. Se utilizan para reconocer funciones del lenguaje: mencionar,
 * documentar, rechazar, insultar, etc.
 */
/*
 * Cues inequívocamente metalingüísticos.
 *
 * v4.1 evita tratar como metalenguaje palabras editoriales demasiado generales
 * como "contexto", "revisión" o "explicación técnica". Esas expresiones aparecen
 * con frecuencia en comentarios normales sobre una nota y, por sí solas, no
 * justifican neutralizar una cláusula completa.
 */
const METALINGUISTIC_CUE_PATTERN =
    /\b(?:palabra|termino|terminologia|expresion|frase|cita|cadena|forma\s+escrita|entrada\s+de\s+prueba|lenguaje|vocabulario|sustantiv[oa]s?|adjetiv[oa]s?|insulto|descalificacion|ejemplo|prueba|bateria|dataset|clasificador|moderacion|documentacion|manual|guia|modelo|auditoria|significado|uso\s+linguistico|uso\s+literal|sentido\s+literal|falso\s+positivo|falso\s+negativo|capacitacion|reglas?|regla\s+de\s+convivencia|reglas?\s+linguisticas?|caso\s+de\s+prueba|revision\s+humana|neutralizacion\s+contextual|uso\s+y\s+mencion|word|term|terminology|expression|phrase|quote|quotation|language|vocabulary|noun|adjective|insult|slur|example|test|battery|dataset|classifier|moderation|documentation|manual|guide|model|audit|meaning|linguistic\s+use|literal\s+use|false\s+positive|false\s+negative|training|rule|rules|human\s+review|contextual\s+analysis|use\s+and\s+mention)\b/u;

const MENTION_ACTION_PATTERN =
    /\b(?:menciona|mencionan|mencionar|cita|citan|citar|citando|citado|citada|transcribe|transcriben|transcribir|archivo|archiva|archivan|archivar|incluye|incluyen|incluir|contiene|contienen|contener|aparece|aparecen|usar|usa|usan|utiliza|utilizan|utilizar|analiza|analizan|analizar|explica|explican|explicar|documenta|documentan|documentar|reproduce|reproducen|reproducir|registra|registran|registrar|muestra|muestran|mostrar|mostrando|ensena|ensenan|ensenar|compara|comparan|comparar|verifica|verifican|verificar|evalua|evaluan|evaluar|conserva|conservan|conservar|marca|marco|marcan|marcar|comprueba|comprueban|comprobar|diferencia|diferencian|diferenciar|describe|describen|describir|enumera|enumeran|enumerar|diciendo|usando|mentions?|mentioning|quotes?|quoted|quoting|cites?|cited|citing|transcribes?|transcribed|transcribing|archives?|archived|archiving|includes?|included|including|contains?|contained|appears?|uses?|using|analy[sz]es?|analy[sz]ing|explains?|explained|documents?|documented|reproduces?|reproduced|records?|recorded|shows?|showing|compares?|compared|verifies?|verified|evaluates?|evaluated|preserves?|preserved|marks?|marked|describes?|described|lists?|listed|enumerates?|enumerated)\b/u;

const REPORTING_CUE_PATTERN =
    /\b(?:dijo|dice|decia|escribio|escribe|publico|publica|menciono|menciona|cito|cita|citan|citando|citado|citada|transcribio|transcribe|transcriben|reprodujo|reproduce|reproducido|reproducida|reporto|reporta|reportado|reportada|informo|informa|informaron|denuncio|denuncia|denunciado|denunciada|declaro|afirmo|recibio|recibe|recibido|recibida|envio|envia|enviado|enviada|explico|explica|explicaba|relato|senalo|indico|manifesto|almaceno|almacena|encontro|encuentra|conserva|conservo|muestra|mostro|recordo|recuerda|recordaba|elimino|borro|retiro|said|says|wrote|writes|posted|published|mentioned|mentions|quoted|quotes|cited|cites|transcribed|transcribes|reproduced|reproduces|reported|reports|informed|informs|stated|states|claimed|claims|received|receives|sent|sends|explained|explains|recounted|noted|indicated|stored|stores|found|finds|preserved|preserves|showed|shows|recorded|records|documented|documents|recalled|recalls)\b/u;

const REPORTING_TRANSITIVE_ACTION_PATTERN =
    /\b(?:registro|registra|incluyo|incluye|incorporo|incorpora|derivo|deriva|almaceno|almacena|archivo|archiva|transcribio|transcribe|encontro|encuentra|conserva|conservo|muestra|mostro|documento|documenta|recibio|recibe|envio|envia|elimino|borro|retiro|recorded|records|included|includes|stored|stores|archived|archives|transcribed|transcribes|found|finds|preserved|preserves|showed|shows|documented|documents|received|receives|sent|sends)\b[^.!?;:\n]{0,90}\b(?:el|la|un|una|los|las|the|a|an)?\s*(?:mensaje|comentario|reporte|denuncia|captura|documento|testimonio|publicacion|frase|texto|cita|message|comment|report|complaint|screenshot|document|testimony|post|phrase|text|statement|quote|quotation)\b/u;

const REPORT_SOURCE_PATTERN =
    /\b(?:comentario|mensaje|reporte|denuncia|expediente|captura|registro|documento|testimonio|publicacion|auditoria|revision|sistema|soporte|ticket|incidente|bitacora|articulo|nota|comment|message|report|complaint|case\s+file|file|screenshot|record|document|testimony|post|publication|audit|review|system|support|documentation|documentacion|support\s+ticket|incident\s+log|moderation\s+report|article|story)\b/u;

const REPORT_CONTENT_OBJECT_PATTERN =
    /\b(?:comentario|mensaje|reporte|denuncia|captura|documento|testimonio|publicacion|frase|texto|cita|contenido\s+citado|comment|message|report|complaint|screenshot|document|testimony|post|phrase|text|statement|quote|quotation|quoted\s+content)\b/u;

const REPORTER_ROLE_PATTERN =
    /\b(?:periodista|reporter[oa]?|moderador(?:a)?|revisor(?:a)?|usuario|usuaria|testigo|equipo\s+de\s+soporte|equipo\s+de\s+moderacion|journalist|reporter|moderator|reviewer|user|witness|support\s+team|moderation\s+team)\b/u;

const REJECTION_CUE_PATTERN =
    /\b(?:no\s+corresponde|no\s+deberiamos|no\s+deberia|no\s+deberian\s+permitirse|no\s+hay\s+que|no\s+hace\s+falta|no\s+quiero|no\s+queremos|no\s+comparto|no\s+se\s+debe|no\s+creo\s+que|no\s+pienso\s+que|no\s+me\s+parece\s+que|no\s+diria\s+que|no\s+sostengo\s+que|no\s+afirmo\s+que|no\s+estoy\s+diciendo|no\s+te\s+estoy\s+llamando|no\s+te\s+considero|jamas\s+dije|nunca\s+dije|evitaria|no\s+tiene\s+sentido|evitemos|evitar|rechazo|rechazar|condeno|condenar|sin\s+necesidad\s+de|do\s+not\s+call|don'?t\s+call|should\s+not\s+call|shouldn'?t\s+call|no\s+need\s+to\s+(?:call|label)|there\s+is\s+no\s+need\s+to\s+(?:call|label)|i\s+am\s+not\s+calling|i'?m\s+not\s+calling|i\s+am\s+not\s+saying|i'?m\s+not\s+saying|i\s+would\s+not\s+call|i\s+wouldn'?t\s+call|i\s+do\s+not\s+think|i\s+don'?t\s+think|i\s+reject|we\s+reject|reject(?:ing)?|avoid(?:ing)?|without\s+calling|without\s+labeling|personal\s+attacks?\s+are\s+unnecessary)\b/u;

const ABUSE_ACTION_PATTERN =
    /\b(?:insultar|insultarlo|insultarla|insultarlos|insultarlas|llamar|llamarlo|llamarla|llamarlos|llamarlas|decir|decirle|decirles|tratar|tratarlo|tratarla|descalificar|agredir|atacar|tildar|calificar|describir|usar|insult|insulting|call|calling|label|labeling|say|saying|describe|describing|attack|attacking|abuse|abusing|use|using)\b/u;

/*
 * Léxico compacto de descalificación personal.
 *
 * No decide por sí solo que un comentario sea tóxico. Solamente permite que la
 * capa estructural reconozca el rol sintáctico de ciertos predicados muy claros.
 * Las expresiones nuevas que no estén aquí siguen pudiendo ser captadas por el
 * modelo neuronal.
 */
const FAMILY_ABUSE_DEROGATORY_SOURCE = String.raw`(?:
    idiot[ao]s?|imbecil(?:es)?|inutil(?:es)?|estupid[ao]s?|tarad[ao]s?|
    pelotud[ao]s?|bolud[ao]s?|salames?|burr[ao]s?|payas[ao]s?|cretin[ao]s?|
    tont[oa]s?|bob[oa]s?|patetic[ao]s?|forr[oa]s?|soretes?|gil(?:a|es|as)?|
    nab[oa]s?|mamert[oa]s?|pajer[oa]s?|cagon(?:a|es|as)?|garcas?|chantas?|
    chorr[oa]s?|caretas?|lacras?|ratas?|ranci[oa]s?|basura|asqueros[oa]s?|
    repugnantes?|culiad[oa]s?|maricon(?:a|es|as)?|trol[oa]s?|mogolic[oa]s?|
    retardad[oa]s?|conchud[oa]s?|cornud[oa]s?|cagador(?:a|es|as)?|
    putas?|putos?
)`.replace(/\s+/gu, '');

const FAMILY_ABUSE_EVASIVE_DEROGATORY_SOURCE =
    String.raw`(?:${FAMILY_ABUSE_DEROGATORY_SOURCE}|p\s*(?:u\s*)?t[ao]s?)`;

const STRONG_FAMILY_ABUSE_CONNECTOR_SOURCE =
    String.raw`(?:d\s*e?|de)`;

const STRONG_FAMILY_ABUSE_SOURCE =
    String.raw`(?:hij[oa]s?\s*${STRONG_FAMILY_ABUSE_CONNECTOR_SOURCE}\s*(?:remil\s*)?${FAMILY_ABUSE_EVASIVE_DEROGATORY_SOURCE})`;

const DEROGATORY_TERM_SOURCE = String.raw`(?:
    idiot[ao]s?|imbecil(?:es)?|inutil(?:es)?|estupid[ao]s?|tarad[ao]s?|
    pelotud[ao]s?|bolud[ao]s?|salames?|burr[ao]s?|payas[ao]s?|cretin[ao]s?|
    tont[oa]s?|bob[oa]s?|patetic[ao]s?|forr[oa]s?|soretes?|gil(?:a|es|as)?|
    nab[oa]s?|mamert[oa]s?|pajer[oa]s?|cagon(?:a|es|as)?|fantasmas?|
    vendehumos?|garcas?|chantas?|chamuyer[oa]s?|cabezas?\s+de\s+(?:termo|tacho)|cabezadetermo|cabezadetacho|
    caraduras?|bocon(?:a|es|as)?|neci[oa]s?|brut[oa]s?|ignorantes?|
    impresentables?|miserables?|ridicul[oa]s?|mediocres?|infeliz(?:es)?|papanatas|
    energumen[oa]s?|engreid[oa]s?|soberbi[oa]s?|delirantes?|irresponsables?|
    incompetentes?|incapaz|incapaces|farsantes?|mentiros[oa]s?|rastrer[oa]s?|
    chupamedias|acomodad[oa]s?|conchud[oa]s?|chupapijas?|ortivas?|
    buchon(?:a|es|as)?|cornud[oa]s?|cagador(?:a|es|as)?|
    chorr[oa]s?|caretas?|lacras?|ratas?|ranci[oa]s?|basura|asqueros[oa]s?|
    repugnantes?|culiad[oa]s?|culiao|culia|maricon(?:a|es|as)?|trol[oa]s?|
    mogolic[oa]s?|retardad[oa]s?|pedazo\s+de\s+(?:salame|nabo|mierda)|pedazode(?:salame|nabo|mierda)|
    ${STRONG_FAMILY_ABUSE_SOURCE}|reverend[oa]\s+pelotud[oa]|reverend[oa]pelotud[oa]|
    tremend[oa]\s+bolud[oa]|tremend[oa]bolud[oa]|flor\s+de\s+forr[oa]|flordeforr[oa]|tremendo\s+sorete|tremendosorete|
    sorete\s+mal\s+cagado|soretemalcagado|payas[oa]\s+barat[oa]|payas[oa]barat[oa]|vendehumo\s+profesional|vendehumoprofesional|
    idiots?|morons?|imbeciles?|fools?|clowns?|losers?|jerks?|assholes?|
    dumbasses?|jackasses?|stupid|pathetic|useless|incompetent|ignorant|
    ridiculous|cowards?|frauds?|buffoons?|cretins?|nitwits?|blockheads?|hacks?
)`.replace(/\s+/gu, '');

const ATTRIBUTIVE_IDENTITY_ABUSE_SOURCE =
    String.raw`(?:put[oa]s?|boton(?:a|es|as)?)`;

/*
 * Fuente estructural combinada para construcciones donde ya existe una
 * atribución inequívoca hacia una persona.
 *
 * `puto/a` y `botón/a` no se agregan al léxico derogatorio general porque pueden
 * aparecer en otros sentidos. Solamente se fusionan aquí cuando la sintaxis ya
 * demuestra que funcionan como predicado personal.
 */
const PERSONAL_DEROGATION_SOURCE =
    String.raw`(?:${DEROGATORY_TERM_SOURCE}|${ATTRIBUTIVE_IDENTITY_ABUSE_SOURCE})`;

const DEROGATORY_TERM_PATTERN =
    new RegExp(
        String.raw`\b${DEROGATORY_TERM_SOURCE}\b`,
        'u'
    );

const STANDALONE_STRONG_FAMILY_ABUSE_PATTERN =
    new RegExp(
        String.raw`^\s*${STRONG_FAMILY_ABUSE_SOURCE}\s*[.!?]*\s*$`,
        'u'
    );

/*
 * Reconoce preguntas cuyo objeto explícito es una palabra, expresión, cita,
 * regla o criterio de moderación. La presencia de una descalificación dentro de
 * una pregunta de este tipo no implica que el usuario la esté dirigiendo a una
 * persona.
 *
 * Se exige simultáneamente:
 *
 * - forma interrogativa;
 * - una señal inequívoca de análisis del lenguaje o de moderación;
 * - una descalificación mencionada como objeto de ese análisis.
 *
 * De esta forma una pregunta retórica ofensiva como "¿por qué sos un idiota?"
 * no obtiene protección solamente por estar formulada como pregunta.
 */
const METALINGUISTIC_QUESTION_PATTERN =
    new RegExp(
        String.raw`^(?:[¿?]\s*)?(?:como|por\s+que|en\s+que|que|cual|cuando|donde|mencionar|una\s+pregunta|how|why|what|when|where)\b[^?]{0,220}\b(?:explicacion|cita|termino|palabra|descalificacion|insulto|sistema|moderacion|regla|criterio|contexto|ofensiv[oa]|clasifica|clasificacion|pregunta|mencionar|usar|uso|explanation|quote|term|word|insult|moderation|rule|criterion|context|offensive|classif(?:y|ied|ication)|question|mention|use)\b[^?]{0,220}\b${DEROGATORY_TERM_SOURCE}\b|^(?:[¿?]\s*)?(?:como|por\s+que|en\s+que|que|cual|cuando|donde|mencionar|una\s+pregunta|how|why|what|when|where)\b[^?]{0,220}\b${DEROGATORY_TERM_SOURCE}\b[^?]{0,220}\b(?:explicacion|cita|termino|palabra|descalificacion|insulto|sistema|moderacion|regla|criterio|contexto|ofensiv[oa]|clasifica|clasificacion|pregunta|mencionar|usar|uso|explanation|quote|term|word|insult|moderation|rule|criterion|context|offensive|classif(?:y|ied|ication)|question|mention|use)\b`,
        'u'
    );

/*
 * Reconoce rechazo explícito situado después de una mención ofensiva.
 *
 * En español es frecuente mencionar primero la expresión cuestionada y recién
 * después rechazarla:
 *
 *     "Decir que alguien es idiota no mejora la discusión."
 *
 * El orden superficial no debe convertir esa mención en un ataque. La regla
 * exige un verbo de mención o atribución y una conclusión explícita de rechazo.
 */
const POSTPOSED_REJECTION_PATTERN =
    new RegExp(
        String.raw`\b(?:decir|decirle|decirles|llamar|llamarlo|llamarla|llamarlos|llamarlas|tratar|tratarlo|tratarla|tildar|calificar|usar)\b[^.!?;:\n]{0,110}\b${DEROGATORY_TERM_SOURCE}\b[^.!?;:\n]{0,90}\b(?:no\s+(?:aporta|mejora|ayuda|contribuye|suma)|deberia\s+(?:moderarse|reportarse|eliminarse|evitarse)|deberian\s+(?:moderarse|reportarse|eliminarse|evitarse))\b`,
        'u'
    );

/*
 * Reconoce prohibiciones o llamados de desescalada sobre la acción de insultar,
 * agredir o descalificar.
 *
 * No exige que aparezca un insulto concreto porque "No insulten al analista" ya
 * expresa por sí mismo el rechazo de una conducta abusiva. Si luego aparece un
 * ataque real en otra cláusula, esa cláusula continúa disponible para análisis.
 */
const EXPLICIT_ABUSE_REJECTION_PATTERN =
    /\bno\s+(?:insultes|insulten|insultemos|descalifiques|descalifiquen|descalifiquemos|agredas|agredan|agredamos|ataques|ataquen|ataquemos|tildes|tilden|califiques|califiquen)\b/u;

/*
 * Rechazo explícito del uso de una descalificación.
 *
 * Se exige una construcción que vincule "no apoyo / no avalo" con el acto de
 * usar, decir, llamar o dirigir una descalificación. Así se evita interpretar
 * como protección una frase distinta del tipo "no apoyo esta medida, el autor
 * es idiota", donde el rechazo se refiere a la medida y el ataque sigue siendo
 * real.
 */
const EXPLICIT_DEROGATORY_REJECTION_PATTERN =
    new RegExp(
        String.raw`\bno\s+(?:apoyo|apoyamos|avalo|avalamos)\b[^.!?;:\n]{0,70}\b(?:usar|use|usen|decir|diga|digan|llamar|llame|llamen|dirigir|dirija|dirijan)\b[^.!?;:\n]{0,60}\b${DEROGATORY_TERM_SOURCE}\b`,
        'u'
    );

/*
 * Detecta referencias a un comentario, mensaje o texto que atribuyen una
 * descalificación y, al mismo tiempo, indican una acción de moderación.
 *
 * La estructura describe contenido ajeno sometido a revisión, no una
 * descalificación nueva del autor del comentario actual.
 */
const MODERATION_REFERENCE_PATTERN =
    new RegExp(
        String.raw`\b(?:comentario|mensaje|texto|publicacion)\b[^.!?;:\n]{0,90}\b(?:llama|llamaba|llamo|califica|califico|tilda|tildo|trata|trato)\b[^.!?;:\n]{0,55}\b${DEROGATORY_TERM_SOURCE}\b[^.!?;:\n]{0,80}\b(?:deberia|debe|tendria\s+que)\s+(?:moderarse|reportarse|eliminarse|revisarse|rechazarse)\b`,
        'u'
    );

/*
 * Una pregunta que reproduce literalmente una expresión entre comillas puede
 * estar preguntando por el acto de escribirla, decirla o publicarla. Esta señal
 * se utiliza únicamente alrededor de una cita concreta.
 */
const QUOTED_REFERENCE_QUESTION_PATTERN =
    /\b(?:por\s+que|como|para\s+que|why|how|what\s+for)\b[^"\n]{0,140}\b(?:alguien\s+|someone\s+)?(?:escribiria|diria|usaria|publicaria|comentaria|mencionaria|citara|citaria|would\s+(?:write|say|use|post|mention|quote))\b/u;

/*
 * Reconoce una expresión citada cuyo rechazo aparece después de la propia cita.
 *
 * Ejemplo conceptual:
 *
 *     La frase "..." no debería usarse para reemplazar un argumento.
 *
 * La cita sigue necesitando una señal explícita de rechazo; el mero hecho de
 * estar precedida por "la frase" no basta para volverla segura.
 */
const QUOTED_POSTPOSED_REJECTION_PATTERN =
    /\b(?:frase|expresion|comentario|mensaje|phrase|expression|comment|message|quote)\b[^.!?;\n]{0,240}\b(?:no\s+(?:aporta|mejora|ayuda|contribuye|suma)(?:\s+(?:al|a\s+la))?\s*(?:debate|discusion)?|no\s+deberia(?:n)?\s+(?:usarse|utilizarse|emplearse|permitirse|repetirse)|(?:seria|es|constituye|representa)\s+(?:un\s+)?(?:ataque\s+personal|insulto|descalificacion|agresion\s+verbal)|does\s+not\s+(?:help|improve|contribute)|doesn'?t\s+(?:help|improve|contribute)|should\s+not\s+be\s+(?:used|repeated)|shouldn'?t\s+be\s+(?:used|repeated)|is\s+not\s+being\s+endorsed)\b/u;

/*
 * Formas canónicas utilizadas exclusivamente para reconstruir dos técnicas
 * estructurales acotadas: omitir una letra o repetir una letra adyacente. No se
 * utiliza distancia léxica abierta ni sustitución arbitraria, lo que reduce el
 * riesgo de transformar palabras comunes por simple semejanza ortográfica.
 */
const DEROGATORY_STRUCTURAL_FORMS =
    Object.freeze([
        /* Componentes de expresiones compuestas: sólo reparan forma; por sí solos no son ataque. */
        'cabeza',
        'pedazo',
        'hijo',
        'hija',
        'idiota',
        'imbecil',
        'inutil',
        'estupido',
        'estupida',
        'tarado',
        'tarada',
        'pelotudo',
        'pelotuda',
        'boludo',
        'boluda',
        'salame',
        'burro',
        'burra',
        'payaso',
        'payasa',
        'cretino',
        'cretina',
        'tonto',
        'tonta',
        'bobo',
        'boba',
        'patetico',
        'patetica',
        'forro',
        'forra',
        'sorete',
        'gil',
        'gila',
        'nabo',
        'naba',
        'mamerto',
        'mamerta',
        'pajero',
        'pajera',
        'cagon',
        'cagona',
        'fantasma',
        'vendehumo',
        'garca',
        'chanta',
        'chamuyero',
        'chamuyera',
        'caradura',
        'bocon',
        'bocona',
        'necio',
        'necia',
        'bruto',
        'bruta',
        'ignorante',
        'impresentable',
        'miserable',
        'ridiculo',
        'ridicula',
        'mediocre',
        'infeliz',
        'papanatas',
        'energumeno',
        'energumena',
        'engreido',
        'engreida',
        'soberbio',
        'soberbia',
        'delirante',
        'irresponsable',
        'incompetente',
        'incapaz',
        'farsante',
        'mentiroso',
        'mentirosa',
        'rastrero',
        'rastrera',
        'chupamedias',
        'acomodado',
        'acomodada',
        'conchudo',
        'conchuda',
        'chupapija',
        'ortiva',
        'buchon',
        'buchona',
        'boton',
        'botona',
        'cornudo',
        'cornuda',
        'cagador',
        'cagadora',
        'chorro',
        'chorra',
        'careta',
        'lacra',
        'rata',
        'rancio',
        'rancia',
        'basura',
        'asqueroso',
        'asquerosa',
        'repugnante',
        'culiado',
        'culiada',
        'culiao',
        'culia',
        'maricon',
        'maricona',
        'trolo',
        'trola',
        'mogolico',
        'mogolica',
        'retardado',
        'retardada',
        'idiot',
        'moron',
        'imbecile',
        'fool',
        'clown',
        'loser',
        'jerk',
        'asshole',
        'dumbass',
        'jackass',
        'stupid',
        'pathetic',
        'useless',
        'incompetent',
        'ignorant',
        'ridiculous',
        'coward',
        'fraud',
        'buffoon',
        'cretin',
        'nitwit',
        'blockhead',
        'hack'
    ]);

/*
 * La reconstrucción ortográfica estructural
    ]);

/*
 * La reconstrucción ortográfica estructural solamente se habilita cuando la cláusula ya
 * contiene un marco compatible con un ataque: segunda persona, rol humano,
 * atribución, vocativo, imperativo despectivo o pregunta retórica personal.
 * De esta forma una palabra común parecida a un insulto no se corrige fuera de
 * una estructura donde esa recuperación tenga sentido lingüístico.
 */
const POTENTIAL_PERSONAL_ATTACK_FRAME_PATTERN =
    /\b(?:vos|tu|usted|ustedes|te|sos|eres|sois|seas|pareces|sonas|podes|puedes|puede|pueden|you|your|youre|you\s+are|you\s+sound|you\s+seem|you\s+look|hay\s+que\s+ser|quien(?:es)?\s+(?:puede(?:n)?\s+)?ser|que\s+clase\s+de|que\s+tan|how\s+can\s+you|what\s+kind\s+of|who\s+could\s+be|callate|anda(?:te)?|aprende|deja\s+de|volve|volvete|no\s+seas|shut\s+up|get\s+lost|fuck\s+off|go\s+away|stop\s+talking|quit|is|are|sounds?|seems?|looks?|es|son|parece|parecen|demuestra\s+ser|queda|quedan)\b/u;

/**
 * Comprueba si `candidate` puede obtenerse eliminando exactamente una letra de
 * una forma descalificadora canónica.
 *
 * @param {string} candidate
 * @returns {string|null}
 */
function recoverSingleDroppedDerogatoryToken(candidate) {
    const normalizedCandidate =
        canonicalizeForStructure(candidate);

    if (
        !normalizedCandidate
        || normalizedCandidate.length < 3
    ) {
        return null;
    }

    for (
        const canonicalForm
        of DEROGATORY_STRUCTURAL_FORMS
    ) {
        if (
            canonicalForm.length
            !== normalizedCandidate.length + 1
        ) {
            continue;
        }

        for (
            let index = 0;
            index < canonicalForm.length;
            index += 1
        ) {
            const withoutCharacter =
                canonicalForm.slice(0, index)
                + canonicalForm.slice(index + 1);

            if (
                withoutCharacter
                === normalizedCandidate
            ) {
                return canonicalForm;
            }
        }
    }

    return null;
}

/**
 * Reconstruye una descalificación cuando la evasión consiste en duplicar una
 * única letra adyacente, por ejemplo una forma léxica con un carácter repetido
 * de más. La comprobación exige que al eliminar exactamente esa repetición se
 * obtenga una forma canónica conocida.
 *
 * @param {string} candidate
 * @returns {string|null}
 */
function recoverSingleRepeatedDerogatoryToken(candidate) {
    const normalizedCandidate =
        canonicalizeForStructure(candidate);

    for (
        const canonicalForm
        of DEROGATORY_STRUCTURAL_FORMS
    ) {
        if (
            normalizedCandidate.length
            !== canonicalForm.length + 1
        ) {
            continue;
        }

        for (
            let index = 0;
            index < normalizedCandidate.length;
            index += 1
        ) {
            const previous =
                normalizedCandidate[index - 1]
                ?? null;

            const next =
                normalizedCandidate[index + 1]
                ?? null;

            if (
                normalizedCandidate[index] !== previous
                && normalizedCandidate[index] !== next
            ) {
                continue;
            }

            const withoutRepeatedCharacter =
                normalizedCandidate.slice(0, index)
                + normalizedCandidate.slice(index + 1);

            if (
                withoutRepeatedCharacter
                === canonicalForm
            ) {
                return canonicalForm;
            }
        }
    }

    return null;
}

/**
 * Genera una segunda vista estructural corrigiendo omisiones de una letra o una
 * única repetición adyacente dentro de un marco personal compatible. La copia se
 * usa solamente para estructura; nunca reemplaza el comentario ni el texto que
 * recibe el modelo neuronal.
 *
 * @param {string} clause
 * @returns {string}
 */
function repairSingleCharacterEvasionForStructure(clause) {
    if (
        !POTENTIAL_PERSONAL_ATTACK_FRAME_PATTERN
            .test(clause)
        && !HUMAN_ROLE_PATTERN.test(clause)
        && !HUMAN_GROUP_PATTERN.test(clause)
        && !/\b(?:el|la|los|las)\s+que\b/u.test(clause)
        && !/^(?:es|son|sos|eres)\b/u.test(clause)
    ) {
        return clause;
    }

    /*
     * Las palabras de tres letras separadas sólo se compactan si el resultado
     * existe realmente en el léxico degradante. Así `g i l` se recupera sin
     * convertir cualquier secuencia normal de iniciales en una palabra.
     */
    const separatedRepaired =
        clause.replace(
            /(?<!\p{L})(?:\p{L}\p{Z}+){2,}\p{L}(?!\p{L})/gu,
            (match) => {
                const compact =
                    canonicalizeForStructure(match)
                        .replace(/[^\p{L}]/gu, '');

                return DEROGATORY_STRUCTURAL_FORMS.includes(compact)
                    ? compact
                    : match;
            }
        );

    return separatedRepaired.replace(
        /\b\p{L}{3,20}\b/gu,
        (token) => {
            const canonicalToken =
                canonicalizeForStructure(token);

            /*
             * Una forma que ya pertenece al léxico no se "corrige" hacia otra
             * más larga. Esto evita, por ejemplo, convertir el insulto válido
             * `gil` en `gila` por interpretarlo erróneamente como letra omitida.
             */
            if (
                DEROGATORY_STRUCTURAL_FORMS
                    .includes(canonicalToken)
            ) {
                return token;
            }

            return (
                recoverSingleDroppedDerogatoryToken(token)
                ?? recoverSingleRepeatedDerogatoryToken(token)
                ?? token
            );
        }
    );
}

const PROFANITY_PATTERN =
    /\b(?:mierda|carajo|joder|puta|puto|forro|forra|sorete|fuck|fucking|bullshit|shit|asshole|dumbass|jackass)\b/u;

/*
 * Divide el texto en cláusulas pequeñas. Además de la puntuación se separan
 * conectores que suelen marcar un cambio de postura.
 *
 * Ejemplo:
 *
 * "No corresponde llamarlo idiota, pero el editor es un tarado"
 *
 * produce dos cláusulas, permitiendo proteger la primera sin esconder el ataque
 * real de la segunda.
 */
/**
 * Divide un comentario en cláusulas suficientemente independientes para analizar
 * contexto sin asumir que toda la oración comparte la misma intención.
 *
 * @param {string} text
 * @returns {string[]}
 */
function splitSemanticClauses(text) {
    return String(text)
        .split(
            /(?:[.!?;:\n]+|,\s*(?=(?:pero|aunque|sino|sin\s+embargo|aun\s+asi|igual|de\s+todos\s+modos|ademas|porque|es|son|sos|eres|but|however|nevertheless|still|yet|anyway|even\s+so|although|though|because|is|are)\b)|\b(?:pero|aunque|sino|sin\s+embargo|aun\s+asi|igual|de\s+todos\s+modos|ademas|but|however|nevertheless|still|yet|anyway|even\s+so|although|though)\b)/iu
        )
        .map((clause) => clause.trim())
        .filter(Boolean);
}

/**
 * Detecta indicadores de que una expresión se está tratando como objeto del
 * lenguaje, ejemplo, documentación o material de prueba, y no necesariamente
 * como ataque del autor del comentario.
 *
 * @param {string} canonicalClause
 * @returns {boolean}
 */
function hasExplicitMetalinguisticMention(canonicalClause) {
    const derogatoryMatch =
        canonicalClause.match(
            DEROGATORY_TERM_PATTERN
        );

    if (!derogatoryMatch) {
        return false;
    }

    const derogatoryIndex =
        derogatoryMatch.index
        ?? Number.POSITIVE_INFINITY;

    /*
     * Cuando la descalificación está presentada explícitamente como palabra,
     * término, expresión o cadena, la persona mencionada dentro de la explicación
     * no es automáticamente su destinatario.
     *
     *     "La palabra idiota puede funcionar como insulto..."
     */
    const lexicalObjectFrame =
        new RegExp(
            String.raw`\b(?:palabra|termino|expresion|cadena|vocabulario|word|term|expression|string|vocabulary)\b[^.!?;:\n]{0,75}\b${DEROGATORY_TERM_SOURCE}\b`,
            'u'
        ).test(canonicalClause);

    if (lexicalObjectFrame) {
        return true;
    }

    const metaCueMatch =
        canonicalClause.match(
            METALINGUISTIC_CUE_PATTERN
        );

    const mentionActionMatch =
        canonicalClause.match(
            MENTION_ACTION_PATTERN
        );

    /*
     * En marcos del tipo "el moderador marcó X como descalificación" o
     * "en esta prueba X aparece como término", la acción de mención o el marco
     * metalingüístico introduce la expresión antes de que ésta pueda interpretarse
     * como una atribución personal.
     *
     * Si tanto la mención como la señal meta aparecen solamente DESPUÉS de un
     * ataque, no se concede protección. Así:
     *
     *     "el autor es idiota y el moderador marca el término"
     *
     * sigue disponible para análisis tóxico.
     */
    return Boolean(
        metaCueMatch
        && mentionActionMatch
        && Math.min(
            metaCueMatch.index
                ?? Number.POSITIVE_INFINITY,
            mentionActionMatch.index
                ?? Number.POSITIVE_INFINITY
        ) <= derogatoryIndex
    );
}


function hasMetalinguisticContext(canonicalClause) {
    const hasMetaCue =
        METALINGUISTIC_CUE_PATTERN.test(
            canonicalClause
        );

    const metalinguisticQuestion =
        METALINGUISTIC_QUESTION_PATTERN.test(
            canonicalClause
        );

    if (
        !hasMetaCue
        && !metalinguisticQuestion
    ) {
        return false;
    }

    /*
     * Algunas palabras pueden aparecer también en contenido periodístico normal.
     * Por ejemplo, "prueba" puede significar evidencia dentro de una causa y no
     * una prueba del clasificador. Por eso no se protege una cláusula solamente
     * por contener ese término.
     */
    /*
     * "frase" se conserva como señal auxiliar, pero no como evidencia fuerte por
     * sí sola. En contenido periodístico ordinario puede aparecer después de un
     * ataque real —por ejemplo "el autor es un salame si cree que esa frase..."—
     * y no debe neutralizar retrospectivamente la descalificación.
     */
    const strongMetalinguisticCue =
        /\b(?:palabra|termino|terminologia|expresion|cadena|forma\s+escrita|entrada\s+de\s+prueba|ejemplo|uso\s+linguistico|uso\s+literal|sentido\s+literal|significado|vocabulario|sustantiv[oa]s?|adjetiv[oa]s?|bateria|dataset|clasificador|moderacion|documentacion|manual|guia|falso\s+positivo|falso\s+negativo|capacitacion|regla\s+de\s+convivencia|reglas?\s+linguisticas?|caso\s+de\s+prueba|revision\s+humana|explicacion\s+tecnica|revision\s+tecnica|neutralizacion\s+contextual|uso\s+y\s+mencion|word|term|terminology|expression|example|linguistic\s+use|literal\s+use|meaning|vocabulary|noun|adjective|dataset|classifier|moderation|documentation|manual|guide|false\s+positive|false\s+negative|training|human\s+review|technical\s+(?:review|explanation)|contextual\s+analysis|use\s+and\s+mention)\b/u
            .test(canonicalClause);

    const contextualTestCue =
        /\b(?:prueba|test)\b/u.test(canonicalClause)
        && /\b(?:bateria|battery|dataset|clasificador|classifier|moderacion|moderation|ejemplo|example|caso|case|automatizad[ao]|automated|verificar|verify|evaluar|evaluate|comprobar|check|neutralizacion|neutralization|contextual|uso|use|mencion|mention)\b/u
            .test(canonicalClause);

    return (
        strongMetalinguisticCue
        || contextualTestCue
        || metalinguisticQuestion
        || (
            hasMetaCue
            && MENTION_ACTION_PATTERN.test(
                canonicalClause
            )
        )
    );
}

/**
 * Detecta señales de discurso referido, denuncia, expediente, reporte o cita de
 * contenido producido por otra persona.
 *
 * @param {string} canonicalClause
 * @returns {boolean}
 */
function hasReportedContext(canonicalClause) {
    /*
     * Los verbos de reporte se interpretan como tales solamente cuando existe
     * un marco que los vincula con una fuente/persona reportante o con un objeto
     * comunicativo. Esto evita homógrafos creados por la normalización de tildes:
     *
     *     documentó -> documento
     *     registró  -> registro
     *     archivó   -> archivo
     *
     * Esas formas ambiguas se aceptan en REPORTING_TRANSITIVE_ACTION_PATTERN,
     * donde deben gobernar explícitamente "mensaje", "comentario", etc.
     */
    if (
        REPORTING_TRANSITIVE_ACTION_PATTERN.test(
            canonicalClause
        )
        || MODERATION_REFERENCE_PATTERN.test(
            canonicalClause
        )
    ) {
        return true;
    }

    const reportingCueMatch =
        canonicalClause.match(
            REPORTING_CUE_PATTERN
        );

    if (!reportingCueMatch) {
        return false;
    }

    const cueIndex =
        reportingCueMatch.index
        ?? 0;

    const cueEnd =
        cueIndex
        + reportingCueMatch[0].length;

    const prefix =
        canonicalClause
            .slice(0, cueIndex)
            .trim();

    /*
     * Si antes del verbo reportativo ya existe una agresión personal inequívoca,
     * el verbo posterior no puede neutralizarla retrospectivamente.
     */
    if (prefix) {
        const prefixStructural =
            analyzeStructuralEvidence(
                prefix
            );

        if (
            prefixStructural.direct_attack_signal >= 0.55
            || prefixStructural.directed_profanity
        ) {
            return false;
        }
    }

    const reporterRoleMatch =
        canonicalClause.match(
            REPORTER_ROLE_PATTERN
        );

    const reportSourceMatch =
        canonicalClause.match(
            REPORT_SOURCE_PATTERN
        );

    const objectAfterCueMatch =
        canonicalClause
            .slice(cueEnd)
            .match(
                REPORT_CONTENT_OBJECT_PATTERN
            );

    const reporterBeforeCue =
        Boolean(reporterRoleMatch)
        && (
            reporterRoleMatch.index
            ?? Number.POSITIVE_INFINITY
        ) <= cueIndex
        && (
            cueIndex
            - (
                reporterRoleMatch.index
                ?? 0
            )
        ) <= 120;

    const sourceBeforeCue =
        Boolean(reportSourceMatch)
        && (
            reportSourceMatch.index
            ?? Number.POSITIVE_INFINITY
        ) <= cueIndex
        && (
            cueIndex
            - (
                reportSourceMatch.index
                ?? 0
            )
        ) <= 120;

    const objectAfterCue =
        Boolean(objectAfterCueMatch)
        && (
            objectAfterCueMatch.index
            ?? Number.POSITIVE_INFINITY
        ) <= 140;

    /*
     * También se admite "citando el comentario", "recibió el mensaje", etc.,
     * donde el objeto aparece después del verbo y no existe una fuente nominal
     * anterior.
     */
    if (objectAfterCue) {
        return true;
    }

    const complementAfterCue =
        /^\s*(?:que|haber|habiendo|that|having)\b/u
            .test(
                canonicalClause
                    .slice(
                        cueEnd,
                        cueEnd + 24
                    )
            );

    return (
        (
            reporterBeforeCue
            || sourceBeforeCue
        )
        && complementAfterCue
    );
}

/**
 * Detecta estructuras donde el usuario rechaza, niega o desalienta una
 * descalificación en lugar de utilizarla como ataque propio.
 *
 * @param {string} canonicalClause
 * @returns {boolean}
 */
function hasRejectionContext(canonicalClause) {
    /*
     * Negación de una atribución, no simple presencia superficial de "no".
     * Se cubren formas declarativas, verbos de opinión/atribución y equivalentes
     * ingleses. Los imperativos "no seas X" / "do not be X" NO entran aquí.
     */
    const negatedAttribution =
        new RegExp(
            String.raw`(?:\b(?:vos\s+)?no\s+(?:sos|eres)\b[^.!?;]{0,55}\b${DEROGATORY_TERM_SOURCE}\b|\busted\s+no\s+es\b[^.!?;]{0,55}\b${DEROGATORY_TERM_SOURCE}\b|\bno\s+(?:creo|pienso|me\s+parece|diria|sostengo|afirmo)\s+que\b[^.!?;]{0,65}\b(?:seas|sos|eres|sea|es)\b[^.!?;]{0,35}\b${DEROGATORY_TERM_SOURCE}\b|\b(?:jamas|nunca)\s+dije\s+que\b[^.!?;]{0,65}\b(?:seas|sos|eres|sea|es)\b[^.!?;]{0,35}\b${DEROGATORY_TERM_SOURCE}\b|\bno\s+(?:te\s+)?considero\b[^.!?;]{0,55}\b${DEROGATORY_TERM_SOURCE}\b|\bno\s+(?:te\s+)?estoy\s+llamando\b[^.!?;]{0,55}\b${DEROGATORY_TERM_SOURCE}\b|\bno\s+estoy\s+diciendo\s+que\b[^.!?;]{0,65}\b(?:seas|sos|eres|sea|es)\b[^.!?;]{0,35}\b${DEROGATORY_TERM_SOURCE}\b|\bi\s+(?:am|'m)\s+not\s+(?:calling|labeling)\b[^.!?;]{0,90}\b${DEROGATORY_TERM_SOURCE}\b|\bi\s+(?:am|'m)\s+not\s+saying\b[^.!?;]{0,90}\b${DEROGATORY_TERM_SOURCE}\b|\bi\s+(?:would\s+not|wouldn'?t)\s+(?:call|label)\b[^.!?;]{0,90}\b${DEROGATORY_TERM_SOURCE}\b|\b(?:i|we)\s+(?:do\s+not|don'?t)\s+(?:consider|think\s+of)\b[^.!?;]{0,90}\b${DEROGATORY_TERM_SOURCE}\b)`,
            'u'
        ).test(canonicalClause);

    const withoutAbuseAction =
        new RegExp(
            String.raw`\b(?:sin|without)\s+(?:tratar|llamar|decir|decirle|decirles|insultar|descalificar|agredir|atacar|tildar|calificar|usar|calling|labeling|insulting|attacking|using)\b[^.!?;]{0,95}\b${DEROGATORY_TERM_SOURCE}\b`,
            'u'
        ).test(canonicalClause);

    const nonConvertingAttribution =
        new RegExp(
            String.raw`\b(?:el\s+)?(?:desacuerdo|error|discusion|diferencia|equivocacion)\b[^.!?;]{0,65}\bno\s+convierte\b[^.!?;]{0,90}\b(?:en|a)\s+(?:un|una)?\s*${DEROGATORY_TERM_SOURCE}\b`,
            'u'
        ).test(canonicalClause);

    const hypotheticalLabelRejection =
        new RegExp(
            String.raw`\b(?:llamar|calificar|tildar|describir)\b[^.!?;]{0,45}\b${DEROGATORY_TERM_SOURCE}\b[^.!?;]{0,90}\b(?:desviaria|empeoraria|no\s+(?:aportaria|ayudaria|mejoraria)|seria\s+innecesario|seria\s+injusto)\b`,
            'u'
        ).test(canonicalClause);

    const quotedNonEndorsement =
        new RegExp(
            String.raw`\bno\s+apoyo\b[^.!?;]{0,65}\b(?:responda|diga|escriba|publique|use|repita|responder|decir|escribir|publicar|usar|repetir)\b[^.!?;]{0,80}\b${DEROGATORY_TERM_SOURCE}\b`,
            'u'
        ).test(canonicalClause);

    const prospectiveSpeechActRejection =
        new RegExp(
            String.raw`(?:\bno\s+(?:voy\s+a|pienso|quiero)\s+(?:llamar|tratar(?:\s+de)?|decir(?:le)?(?:\s+que)?|tildar|calificar)\b[^.!?;]{0,95}\b${DEROGATORY_TERM_SOURCE}\b|\bprefiero\s+no\s+(?:llamar|tratar(?:\s+de)?|decir(?:le)?(?:\s+que)?|tildar|calificar)\b[^.!?;]{0,95}\b${DEROGATORY_TERM_SOURCE}\b|\bno\s+(?:corresponde|hace\s+falta)\s+(?:llamar|decir(?:le)?(?:\s+que)?|tratar(?:\s+de)?|responder)\b[^.!?;]{0,105}\b${DEROGATORY_TERM_SOURCE}\b)`,
            'u'
        ).test(canonicalClause);

    const prospectiveVulgarPredicateRejection =
        new RegExp(
            String.raw`\bno\s+(?:voy\s+a|pienso|quiero)\s+decir(?:\s+que)?\b[^.!?;]{0,100}\b(?:vos|tu|usted|${HUMAN_ROLE_SOURCE})\b[^.!?;]{0,45}\b(?:sos|eres|es|son)\b[^.!?;]{0,20}\b${STRONG_VULGAR_PERSONAL_PREDICATE_SOURCE}\b`,
            'u'
        ).test(canonicalClause);

    const thirdPartySpeechActRejection =
        new RegExp(
            String.raw`\bno\s+(?:apoyo|avalo|acepto|corresponde)\s+que\b[^.!?;]{0,55}\b(?:llamen|traten|digan|califiquen|tilden)\b[^.!?;]{0,75}\b${DEROGATORY_TERM_SOURCE}\b`,
            'u'
        ).test(canonicalClause);

    const explicitAttackAssessment =
        /\b(?:frase|expresion|decir|responder)\b[^.!?;]{0,150}\b(?:seria|es|constituye|representa)\b[^.!?;]{0,35}\b(?:un\s+)?(?:ataque\s+personal|insulto|descalificacion|agresion\s+verbal)\b/u
            .test(canonicalClause);

    const englishLabelRejection =
        new RegExp(
            String.raw`(?:\b(?:do\s+not|don'?t|should\s+not|shouldn'?t)\s+(?:call|label)\b[^.!?;]{0,100}\b${DEROGATORY_TERM_SOURCE}\b|\bthere\s+is\s+no\s+need\s+to\s+(?:call|label)\b[^.!?;]{0,100}\b${DEROGATORY_TERM_SOURCE}\b|\b(?:i|we)\s+reject\s+(?:calling|labeling)\b[^.!?;]{0,100}\b${DEROGATORY_TERM_SOURCE}\b|\bcalling\b[^.!?;]{0,100}\b${DEROGATORY_TERM_SOURCE}\b[^.!?;]{0,90}\b(?:adds\s+nothing|does\s+not\s+(?:help|improve|add)|doesn'?t\s+(?:help|improve|add))\b)`,
            'u'
        ).test(canonicalClause);

    const explicitAbuseRejection =
        EXPLICIT_ABUSE_REJECTION_PATTERN.test(
            canonicalClause
        );

    const postposedRejection =
        POSTPOSED_REJECTION_PATTERN.test(
            canonicalClause
        );

    const explicitDerogatoryRejection =
        EXPLICIT_DEROGATORY_REJECTION_PATTERN.test(
            canonicalClause
        );

    const explicitlyNonDirected =
        /\b(?:sin\s+dirigirse\s+(?:a|contra)\s+(?:ninguna\s+persona|nadie)|sin\s+dirigir(?:lo|la)?\s+contra\s+nadie|no\s+hay\s+(?:un\s+)?destinatario\s+concreto|no\s+se\s+dirige\s+contra\s+nadie|without\s+(?:directing|addressing)\s+(?:it\s+)?(?:at|to)\s+anyone|not\s+directed\s+at\s+anyone|no\s+one\s+is\s+being\s+(?:addressed|insulted)|not\s+a\s+new\s+attack|not\s+a\s+personal\s+attack)\b/u
            .test(canonicalClause)
        && (
            DEROGATORY_TERM_PATTERN.test(canonicalClause)
            || PROFANITY_PATTERN.test(canonicalClause)
        );

    const rejectionCueMatch =
        canonicalClause.match(
            REJECTION_CUE_PATTERN
        );

    const riskyExpressionMatch =
        canonicalClause.match(
            new RegExp(
                String.raw`(?:${DEROGATORY_TERM_SOURCE}|mierda|carajo|puta|puto|forro|forra|sorete|culo|orto|pija|huevos|concha|fuck|fucking|bullshit|hell)`,
                'u'
            )
        );

    const abuseActionMatch =
        canonicalClause.match(
            ABUSE_ACTION_PATTERN
        );

    /*
     * Una negación situada DESPUÉS del ataque no lo neutraliza. Se conserva el
     * control de orden para impedir que una recomendación o rechazo tardío tape
     * una descalificación ya emitida.
     */
    const orderedRejection =
        Boolean(rejectionCueMatch)
        && (
            abuseActionMatch
            || riskyExpressionMatch
        )
        && (
            rejectionCueMatch.index
            ?? Number.POSITIVE_INFINITY
        ) <= Math.min(
            abuseActionMatch?.index
            ?? Number.POSITIVE_INFINITY,
            riskyExpressionMatch?.index
            ?? Number.POSITIVE_INFINITY
        );

    return (
        negatedAttribution
        || withoutAbuseAction
        || nonConvertingAttribution
        || hypotheticalLabelRejection
        || quotedNonEndorsement
        || prospectiveSpeechActRejection
        || prospectiveVulgarPredicateRejection
        || thirdPartySpeechActRejection
        || explicitAttackAssessment
        || englishLabelRejection
        || explicitAbuseRejection
        || explicitDerogatoryRejection
        || postposedRejection
        || explicitlyNonDirected
        || orderedRejection
    );
}

/*
 * Ficción y representación narrativa. Una expresión ofensiva emitida por un
 * personaje dentro de una obra se trata como contenido citado/referido, siempre
 * que exista simultáneamente una señal inequívoca de medio/escena ficticia y
 * una acción de habla, diálogo, cita o reproducción.
 */
const FICTION_CUE_PATTERN =
    /\b(?:novela|pelicula|serie|videojuego|obra\s+de\s+teatro|cuento|guion|escena\s+ficticia|ficcion|personaje|antagonista|villano|dialogo|novel|film|movie|series|video\s+game|play|story|script|fictional\s+scene|fiction|character|antagonist|villain|dialogue)\b/u;

const FICTION_SPEECH_PATTERN =
    /\b(?:dice|dijo|pronuncia|pronuncio|aparece|contiene|reproduce|cita|expresa|dialogo|says|said|utters?|contains?|reproduces?|quotes?|dialogue|line)\b/u;

function hasFictionContext(canonicalClause) {
    return (
        FICTION_CUE_PATTERN.test(canonicalClause)
        && FICTION_SPEECH_PATTERN.test(canonicalClause)
    );
}

/**\n * Comprueba el contexto cercano a un fragmento citado para determinar si las\n * comillas cumplen una función de reporte, ejemplo, documentación o rechazo.
 * Poner un insulto entre comillas, por sí solo, no lo vuelve inocuo.
 *
 * @param {string} text
 * @param {number} start
 * @param {number} end
 * @returns {boolean}
 */
const QUOTED_REPORTING_CUE_PATTERN =
    /\b(?:dijo|dice|decia|escribio|escribe|publico|publica|menciono|menciona|cito|cita|citan|citando|citado|citada|transcribio|transcribe|transcriben|archivo|archiva|archivaron|reprodujo|reproduce|reproducido|reproducida|reporto|reporta|reportado|reportada|informo|informa|denuncio|denuncia|declaro|afirmo|recibio|recibe|envio|envia|explico|explica|relato|senalo|indico|manifesto|almaceno|almacena|encontro|encuentra|conserva|conservo|muestra|mostro|registro|registra|recordo|recuerda|reviso|revisa|revisamos|revisaron|documento|documenta|incluye|contiene|said|says|wrote|writes|posted|published|mentioned|mentions|quoted|quotes|cited|cites|transcribed|transcribes|archived|archives|reproduced|reproduces|reported|reports|informed|informs|stated|states|claimed|claims|received|receives|sent|sends|explained|explains|stored|stores|found|finds|preserved|preserves|showed|shows|recorded|records|documented|documents|reviewed|reviews|contains?|includes?)\b/u;


/**
 * Contexto reportativo específico de una CITA ya localizada.
 *
 * Aquí puede utilizarse una gramática algo más permisiva que en una cláusula
 * completa porque la unidad que se neutralizará está delimitada por comillas.
 * Por eso "registro", "archivo", "reviewed" o "contains" son seguros en este
 * nivel cuando aparecen junto a una fuente/reportante: solamente se protege la
 * cita, nunca el resto de la oración.
 *
 * @param {string} canonicalContext
 * @returns {boolean}
 */
function hasQuotedReportedContext(canonicalContext) {
    const sourceMatch =
        canonicalContext.match(
            REPORT_SOURCE_PATTERN
        )
        ?? canonicalContext.match(
            REPORTER_ROLE_PATTERN
        );

    const cueMatch =
        canonicalContext.match(
            QUOTED_REPORTING_CUE_PATTERN
        );

    if (
        sourceMatch
        && cueMatch
        && Math.abs(
            (
                sourceMatch.index
                ?? 0
            )
            - (
                cueMatch.index
                ?? 0
            )
        ) <= 180
    ) {
        return true;
    }

    /*
     * "Estoy citando el comentario..." tiene verbo antes del objeto nominal y no
     * una fuente anterior. Al estar evaluando una cita concreta, esa relación es
     * suficiente para identificar mención/reporte.
     */
    if (cueMatch) {
        const cueEnd =
            (
                cueMatch.index
                ?? 0
            )
            + cueMatch[0].length;

        const objectAfterCue =
            canonicalContext
                .slice(
                    cueEnd,
                    cueEnd + 120
                )
                .match(
                    REPORT_CONTENT_OBJECT_PATTERN
                );

        if (objectAfterCue) {
            return true;
        }
    }

    return REPORTING_TRANSITIVE_ACTION_PATTERN.test(
        canonicalContext
    );
}


function hasReferenceContextAround(text, start, end) {
    const windowStart = Math.max(0, start - 180);
    const windowEnd = Math.min(
        text.length,
        end + 180
    );

    const context =
        canonicalizeForStructure(
            text.slice(windowStart, windowEnd)
        );

    const quotedExpressionDescription =
        /\b(?:es|seria|representa|constituye|funciona\s+como|is|would\s+be|represents?|constitutes?|functions?\s+as)\s+(?:un|una|an?|the)?\s*(?:ataque\s+personal|insulto|descalificacion|agresion\s+verbal|insult|slur|verbal\s+abuse|abusive\s+language)\b/u
            .test(context);

    const quotedReferenceQuestion =
        QUOTED_REFERENCE_QUESTION_PATTERN.test(
            context
        );

    const quotedPostposedRejection =
        QUOTED_POSTPOSED_REJECTION_PATTERN.test(
            context
        );

    /*
     * Una cita concreta introducida por un verbo de mención puede ser rechazada
     * después de reproducirse aunque no aparezcan palabras metalingüísticas como
     * "frase" o "expresión": `Decir "..." no aporta nada`. Como esta regla se
     * evalúa exclusivamente alrededor de una cita real, no protege ataques libres.
     */
    const quotedSpeechActRejection =
        /\b(?:decir|responder|escribir|usar|repetir|citar|mencionar)\b[^.!?;:\n]{0,190}\bno\s+(?:aporta|ayuda|mejora|contribuye|suma)\b/u
            .test(context);

    const articleQuoteFrame =
        /\b(?:articulo|nota|article|story)\b[^.!?;:\n]{0,80}\b(?:dice|decia|cita|cito|reproduce|reprodujo|says|said|quotes?|quoted|reproduces?|reproduced)\b/u
            .test(context);

    return {
        metalinguistic:
            hasMetalinguisticContext(context)
            || quotedExpressionDescription
            || quotedReferenceQuestion,
        reported:
            hasQuotedReportedContext(context)
            || hasReportedContext(context)
            || articleQuoteFrame,
        rejection:
            hasRejectionContext(context)
            || quotedPostposedRejection
            || quotedSpeechActRejection,
        fictional:
            hasFictionContext(context)
    };
}

/**
 * Neutraliza únicamente citas que poseen señales contextuales suficientes para
 * tratarlas como referencia. Un ataque real fuera de la cita permanece intacto.
 *
 * @param {string} text
 * @returns {object}
 */
function neutralizeProtectedQuotes(text) {
    const quotePattern =
        /"[^"\n]*"|'[^'\n]*'|“[^”\n]*”|‘[^’\n]*’|«[^»\n]*»|\([^()\n]{1,180}\)/gu;

    let cursor = 0;
    let result = '';
    let quotedContext = false;
    let reportedContext = false;
    let metalinguisticContext = false;
    let negationContext = false;
    let fictionContext = false;
    let protectedQuoteCount = 0;

    for (const match of text.matchAll(quotePattern)) {
        const start = match.index ?? 0;
        const end = start + match[0].length;

        const context =
            hasReferenceContextAround(
                text,
                start,
                end
            );

        const shouldProtect =
            context.metalinguistic
            || context.reported
            || context.rejection
            || context.fictional;

        result += text.slice(cursor, start);

        if (shouldProtect) {
            result += ' contenido citado ';
            quotedContext = true;
            reportedContext ||= context.reported;
            metalinguisticContext ||= context.metalinguistic;
            negationContext ||= context.rejection;
            fictionContext ||= context.fictional;
            protectedQuoteCount += 1;
        } else {
            result += match[0];
        }

        cursor = end;
    }

    result += text.slice(cursor);

    return {
        text: result,
        quoted_context: quotedContext,
        reported_context: reportedContext,
        metalinguistic_context: metalinguisticContext,
        negation_context: negationContext,
        fiction_context: fictionContext,
        protected_quote_count: protectedQuoteCount
    };
}

/*
 * Determina si una cláusula describe el lenguaje en lugar de utilizarlo como
 * ataque. Las reglas se basan en funciones lingüísticas, no en oraciones
 * completas de una batería.
 */
/**
 * Clasifica la función contextual de una cláusula y determina si puede
 * neutralizarse de forma segura para la inferencia semántica.
 *
 * @param {string} clause
 * @returns {object}
 */
function hasResidualPersonalAttackPotential(canonicalClause) {
    const residual =
        String(canonicalClause)
            .replace(/\b(?:contenido\s+citado|contexto\s+protegido)\b/gu, ' ')
            .replace(/\s+/gu, ' ')
            .trim();

    if (!residual) {
        return false;
    }

    const structural =
        analyzeStructuralEvidence(residual);

    return (
        structural.direct_attack_signal >= 0.55
        || structural.directed_profanity
    );
}


/**
 * Detecta una segunda aserción personal coordinada que queda fuera del contenido
 * reportado. Ejemplo:
 *
 *     "El expediente registra el dato y el editor es un imbécil."
 *
 * La presencia de "expediente/registra" no debe proteger retrospectivamente el
 * ataque posterior. En cambio, si el propio contenido reportado ya contiene la
 * expresión riesgosa ("el reporte dice que X es idiota y Y es tonto"), se
 * conserva el scope reportativo de la cláusula, porque la coordinación sigue
 * formando parte del discurso referido.
 *
 * @param {string} canonicalClause
 * @returns {boolean}
 */
function hasIndependentAttackAfterReportingFrame(canonicalClause) {
    const reportingCue =
        canonicalClause.match(
            REPORTING_CUE_PATTERN
        )
        ?? canonicalClause.match(
            REPORTING_TRANSITIVE_ACTION_PATTERN
        );

    if (!reportingCue) {
        return false;
    }

    const cueEnd =
        (reportingCue.index ?? 0)
        + reportingCue[0].length;

    const remainder =
        canonicalClause.slice(cueEnd);

    const conjunctionPattern =
        /\b(?:y|and)\b/gu;

    for (const conjunction of remainder.matchAll(conjunctionPattern)) {
        const conjunctionIndex =
            conjunction.index ?? 0;

        const reportedSide =
            remainder.slice(
                0,
                conjunctionIndex
            );

        /*
         * Si antes de la coordinación ya estaba el insulto/descalificación, la
         * conjunción puede pertenecer al mismo contenido referido y no se rompe
         * artificialmente el scope.
         */
        if (
            DEROGATORY_TERM_PATTERN.test(reportedSide)
            || PROFANITY_PATTERN.test(reportedSide)
        ) {
            continue;
        }

        const candidateIndependentAssertion =
            remainder.slice(
                conjunctionIndex
                + conjunction[0].length
            );

        const structural =
            analyzeStructuralEvidence(
                candidateIndependentAssertion
            );

        if (
            structural.direct_attack_signal >= 0.55
            || structural.directed_profanity
        ) {
            return true;
        }
    }

    return false;
}

function classifyProtectedClause(clause) {
    const canonical =
        canonicalizeForStructure(clause);

    if (!canonical) {
        return null;
    }

    /*
     * El rechazo tiene prioridad porque una negación legítima puede contener la
     * misma forma superficial que una atribución ofensiva ("no sos un X").
     */
    if (hasRejectionContext(canonical)) {
        return 'rejection';
    }

    if (hasFictionContext(canonical)) {
        return 'fictional';
    }

    const explicitMetalinguisticMention =
        hasExplicitMetalinguisticMention(
            canonical
        );

    const residualAttack =
        hasResidualPersonalAttackPotential(
            canonical
        );

    /*
     * Una mención inequívocamente lexical ("la palabra X", "marcó X como
     * descalificación", "X aparece en la prueba") puede contener referencias a
     * personas sin convertirlas en destinatarios del término.
     *
     * Para señales metalingüísticas menos explícitas se mantiene la salvaguarda
     * de ataque residual, evitando neutralizar una agresión sólo porque más tarde
     * aparezcan palabras como "moderación" o "contexto".
     */
    if (
        explicitMetalinguisticMention
        || (
            hasMetalinguisticContext(canonical)
            && !residualAttack
        )
    ) {
        return 'metalinguistic';
    }

    /*
     * El discurso referido sin comillas puede contener literalmente un insulto
     * ("el reporte dice que..."). En ese caso el marco reportativo sigue siendo
     * suficiente, salvo que exista una transición contrastiva que haya dejado una
     * segunda intención en la misma cláusula; splitSemanticClauses intenta separar
     * esos límites antes de llegar aquí.
     */
    if (
        hasReportedContext(canonical)
        && !hasIndependentAttackAfterReportingFrame(
            canonical
        )
    ) {
        return 'reported';
    }

    return null;
}

/**
 * Recorre las cláusulas del comentario y neutraliza solamente aquellas cuyo uso
 * es inequívocamente metalingüístico, referido o de rechazo.
 *
 * @param {string} text
 * @returns {object}
 */
function neutralizeContextualClauses(text) {
    const clauses =
        splitSemanticClauses(text);

    const keptClauses = [];

    let protectedClauseCount = 0;
    let reportedContext = false;
    let metalinguisticContext = false;
    let negationContext = false;
    let fictionContext = false;

    for (const clause of clauses) {
        const protectedType =
            classifyProtectedClause(clause);

        if (!protectedType) {
            keptClauses.push(clause);
            continue;
        }

        protectedClauseCount += 1;

        if (protectedType === 'reported') {
            reportedContext = true;
        }

        if (protectedType === 'metalinguistic') {
            metalinguisticContext = true;
        }

        if (protectedType === 'rejection') {
            negationContext = true;
        }

        if (protectedType === 'fictional') {
            fictionContext = true;
        }

        keptClauses.push(' contexto protegido ');
    }

    return {
        text:
            protectedClauseCount === 0
                ? String(text).trim()
                : keptClauses
                    .join('. ')
                    .replace(/\s+/gu, ' ')
                    .trim(),
        protected_clause_count:
            protectedClauseCount,
        reported_context:
            reportedContext,
        metalinguistic_context:
            metalinguisticContext,
        negation_context:
            negationContext,
        fiction_context:
            fictionContext
    };
}

/**
 * Prepara una representación concreta para el modelo aplicando protección de
 * citas y cláusulas contextuales sin destruir ataques reales coexistentes.
 *
 * @param {string} text
 * @returns {object}
 */
function prepareSingleContextRepresentation(text) {
    const quoted =
        neutralizeProtectedQuotes(text);

    const clauses =
        neutralizeContextualClauses(
            quoted.text
        );

    /*
     * Una cita protegida puede quedar dentro de una cláusula que también termina
     * neutralizada. Usar una suma contaba dos veces la misma región y podía llevar
     * artificialmente protection_score a 1. Se usa la mayor cobertura observada.
     */
    const protectedCount =
        Math.max(
            quoted.protected_quote_count,
            clauses.protected_clause_count
        );

    const sourceClauseCount =
        Math.max(
            1,
            splitSemanticClauses(text).length
        );

    const protectionScore =
        clamp01(
            protectedCount
            / sourceClauseCount
        );

    return {
        text:
            clauses.text,
        adjusted:
            protectedCount > 0,
        protection_score:
            roundScore(protectionScore),
        protected_quote_count:
            quoted.protected_quote_count,
        protected_clause_count:
            clauses.protected_clause_count,
        quoted_context:
            quoted.quoted_context,
        reported_context:
            quoted.reported_context
            || clauses.reported_context,
        metalinguistic_context:
            quoted.metalinguistic_context
            || clauses.metalinguistic_context,
        negation_context:
            quoted.negation_context
            || clauses.negation_context,
        fiction_context:
            quoted.fiction_context
            || clauses.fiction_context
    };
}

/**
 * Prepara todas las representaciones contextuales derivadas de la normalización.
 * Conserva metadatos separados para cita, reporte, metalenguaje y negación, y
 * calcula una señal de protección contextual para auditoría.
 *
 * @param {object|string} normalizationBundle
 * @returns {object}
 */
function prepareContextForClassification(normalizationBundle) {
    const normalized =
        prepareSingleContextRepresentation(
            normalizationBundle.normalizedText
        );

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
        negation_context:
            normalized.negation_context
            || deobfuscated.negation_context,
        fiction_context:
            normalized.fiction_context
            || deobfuscated.fiction_context
    };
}

/* ============================================================================
 * 3. MODELO NEURONAL
 * ============================================================================ */

/**
 * Convierte los logits producidos por DistilBERT en probabilidades cuya suma es
 * aproximadamente 1. Se resta previamente el máximo para mejorar estabilidad
 * numérica antes de Math.exp().
 *
 * @param {number[]} values
 * @returns {number[]}
 */
function softmax(values) {
    const maxValue = Math.max(...values);

    const exponentials =
        values.map(
            (value) => Math.exp(value - maxValue)
        );

    const total =
        exponentials.reduce(
            (sum, value) => sum + value,
            0
        );

    return exponentials.map(
        (value) => value / total
    );
}

/*
 * Cache LRU de inferencias ya resueltas dentro del proceso actual.
 *
 * La clave es el texto exacto enviado al tokenizer. No se utiliza una forma
 * canónica porque diferencias de mayúsculas o símbolos pueden producir scores
 * distintos y forman parte deliberada del análisis.
 */
const modelInferenceCache = new Map();

/**
 * Construye el objeto de salida neuronal a partir de las probabilidades de una
 * fila del batch ONNX.
 *
 * @param {number[]} probabilities
 * @returns {object}
 */
function buildModelResult(probabilities) {
    const scores = {};

    probabilities.forEach(
        (probability, index) => {
            const label =
                modelConfig.id2label[String(index)]
                ?? `label_${index}`;

            scores[label] = probability;
        }
    );

    return {
        classification:
            (scores.toxic ?? 0)
            >= (scores['not-toxic'] ?? 0)
                ? 'toxic'
                : 'not-toxic',
        toxicity_score:
            scores.toxic ?? 0,
        scores
    };
}

/**
 * Obtiene una inferencia previamente almacenada y refresca su posición dentro
 * de la cache LRU.
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
 * Guarda una inferencia en la cache y elimina las entradas más antiguas cuando
 * se supera el límite configurado.
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
            modelInferenceCache.keys()
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
 * Ejecuta una sola llamada a ONNX Runtime para varias secuencias ya tokenizadas.
 *
 * Las secuencias se rellenan únicamente hasta la longitud máxima del lote y se
 * acompañan con attention_mask. El padding no aporta evidencia al modelo.
 *
 * @param {Array<object>} encodedEntries
 * Si el modelo local no admite batch dinámico, la función degrada de forma
 * segura a inferencias de una sola secuencia sin cambiar la clasificación.
 *
 * @returns {Promise<{results: object[], run_count: number}>}
 */
async function inferEncodedBatch(encodedEntries) {
    if (!encodedEntries.length) {
        return {
            results: [],
            run_count: 0
        };
    }

    const batchSize =
        encodedEntries.length;

    const maxSequenceLength =
        Math.max(
            ...encodedEntries.map(
                (entry) =>
                    entry.encoded.ids.length
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

    let result;

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
        /*
         * Algunos exports ONNX pueden fijar batch=1. En ese caso conservamos la
         * compatibilidad ejecutando las mismas secuencias individualmente. Un
         * error producido también con batch=1 sí se propaga al llamador.
         */
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
            results: fallbackResults,
            run_count: fallbackRunCount
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
                        rowIndex * classCount;

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
 * Resuelve una colección de textos mediante cache + batches ONNX.
 *
 * Antes de ejecutar el modelo se eliminan duplicados exactos y las secuencias
 * pendientes se ordenan por longitud. Agrupar longitudes semejantes reduce el
 * padding necesario dentro de cada lote.
 *
 * @param {string[]} texts
 * @returns {Promise<object>}
 */
async function inferTextsBatched(texts) {
    const normalizedTexts =
        texts.map(
            (text) => String(text ?? '')
        );

    const results =
        new Array(
            normalizedTexts.length
        );

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

    const unresolvedEntries =
        Array.from(
            unresolvedByText.values()
        )
            .map(
                (entry) => {
                    const encoded =
                        tokenizer.encode(
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

        const batchResults =
            batchInference.results;

        batchCount +=
            batchInference.run_count;

        batch.forEach(
            (entry, batchIndex) => {
                const modelResult =
                    batchResults[batchIndex];

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
 * Ejecuta el modelo comparando una representación con su variante en minúsculas
 * mediante una única operación batch cuando ambas son diferentes.
 *
 * @param {string} analysisText
 * @returns {Promise<object>}
 */
async function inferTextWithCaseVariants(analysisText) {
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
            || result.toxicity_score
                > strongest.toxicity_score
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
            inference.cache_hits
    };
}

/**
 * Divide un segmento excesivamente largo en ventanas superpuestas para evitar
 * que un ataque breve quede diluido o fuera de la zona de mayor atención.
 *
 * @param {string} text
 * @returns {string[]}
 */
function splitLongSegmentIntoWindows(text) {
    const words =
        text
            .split(/\s+/u)
            .filter(Boolean);

    if (
        words.length
        <= LONG_SEGMENT_WINDOW_WORDS
    ) {
        return [text.trim()];
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

/**
 * Agrega una representación al conjunto de unidades de análisis respetando el
 * límite defensivo, evitando duplicados y preservando metadatos de procedencia.
 *
 * @param {Array} units
 * @param {Set<string>} seen
 * @param {string} text
 * @param {string} kind
 * @param {number} index
 * @param {string} representation
 * @returns {void}
 */
function appendAnalysisRepresentation(
    units,
    seen,
    text,
    representation
) {
    const cleanText =
        String(text ?? '')
            .replace(/\s+/gu, ' ')
            .trim();

    if (!cleanText) {
        return;
    }

    const appendUnit = (
        unitText,
        kind,
        index
    ) => {
        if (
            units.length
            >= MAX_ANALYSIS_UNITS
        ) {
            return;
        }

        const key =
            canonicalizeForStructure(
                unitText
            );

        if (!key || seen.has(key)) {
            return;
        }

        seen.add(key);

        units.push({
            text: unitText,
            kind,
            index,
            representation
        });
    };

    appendUnit(
        cleanText,
        'full',
        0
    );

    const segments =
        splitSemanticClauses(cleanText);

    segments.forEach(
        (segment, segmentIndex) => {
            const windows =
                splitLongSegmentIntoWindows(
                    segment
                );

            if (windows.length === 1) {
                appendUnit(
                    windows[0],
                    'segment',
                    segmentIndex
                );
                return;
            }

            windows.forEach(
                (windowText, windowIndex) => {
                    appendUnit(
                        windowText,
                        'window',
                        (
                            segmentIndex * 100
                        ) + windowIndex
                    );
                }
            );
        }
    );
}

/**
 * Construye las unidades que serán enviadas al modelo: comentario completo,
 * segmentos y ventanas largas de las representaciones contextuales disponibles.
 *
 * @param {object} preparedContext
 * @returns {Array<object>}
 */
function buildAnalysisUnits(preparedContext) {
    const units = [];
    const seen = new Set();

    appendAnalysisRepresentation(
        units,
        seen,
        preparedContext.normalized_text,
        'normalized'
    );

    appendAnalysisRepresentation(
        units,
        seen,
        preparedContext.deobfuscated_text,
        'deobfuscated'
    );

    return units;
}

/**
 * Ejecuta todas las unidades de un comentario en batches ONNX.
 *
 * Cada unidad conserva sus variantes de capitalización, pero se aplanan antes de
 * la inferencia para que varias puedan compartir la misma llamada a la sesión.
 * Después se agrega la evidencia con el comentario completo como ancla: una
 * unidad local sólo puede dominar plenamente cuando existe soporte estructural
 * de ataque, evitando que fragmentos breves descontextualizados gobiernen la
 * clasificación completa.
 *
 * @param {Array<object>} analysisUnits
 * @returns {Promise<object>}
 */
async function inferAnalysisUnitsBatched(analysisUnits) {
    const entries = [];
    for (const unit of analysisUnits) {
        const variants = uniqueStrings([
            unit.text,
            unit.text.toLocaleLowerCase('es')
        ]);
        for (const variant of variants) {
            entries.push({ text: variant, unit });
        }
    }

    const inference =
        await inferTextsBatched(
            entries.map((entry) => entry.text)
        );

    let rawStrongestModelResult = null;
    let rawStrongestUnit = null;
    let fullModelResult = null;
    let fullUnit = null;

    inference.results.forEach((result, index) => {
        const entry = entries[index];
        if (
            !rawStrongestModelResult
            || result.toxicity_score
                > rawStrongestModelResult.toxicity_score
        ) {
            rawStrongestModelResult = result;
            rawStrongestUnit = entry?.unit ?? null;
        }
        if (
            entry?.unit?.kind === 'full'
            && (
                !fullModelResult
                || result.toxicity_score
                    > fullModelResult.toxicity_score
            )
        ) {
            fullModelResult = result;
            fullUnit = entry.unit;
        }
    });

    const baseResult =
        fullModelResult
        ?? rawStrongestModelResult
        ?? {
            classification: 'not-toxic',
            toxicity_score: 0,
            scores: { 'not-toxic': 1, toxic: 0 }
        };

    const baseScore =
        clamp01(baseResult.toxicity_score ?? 0);

    let selectedScore = baseScore;
    let selectedUnit = fullUnit ?? rawStrongestUnit;
    let aggregationMode = 'full-comment-anchor';

    for (
        let index = 0;
        index < inference.results.length;
        index += 1
    ) {
        const result = inference.results[index];
        const unit = entries[index]?.unit;

        if (!unit || unit.kind === 'full') {
            continue;
        }

        const localScore =
            clamp01(result?.toxicity_score ?? 0);

        if (localScore <= selectedScore + 0.000001) {
            continue;
        }

        const localStructural =
            analyzeStructuralEvidence(unit.text);

        const wordCount =
            String(unit.text)
                .trim()
                .split(/\s+/u)
                .filter(Boolean)
                .length;

        let weight = LOCAL_SEGMENT_UNGROUNDED_WEIGHT;

        if (
            localStructural.directed_profanity
            || localStructural.direct_attack_signal >= 0.55
        ) {
            weight = 1;
        } else if (localStructural.personal_target) {
            weight = LOCAL_SEGMENT_PERSONAL_WEIGHT;
        } else if (wordCount <= 3) {
            weight = LOCAL_SEGMENT_SHORT_WEIGHT;
        } else if (wordCount >= 12) {
            weight = LOCAL_SEGMENT_LONG_WEIGHT;
        }

        const contextualizedLocalScore =
            baseScore
            + ((localScore - baseScore) * weight);

        if (
            contextualizedLocalScore
            > selectedScore + 0.000001
        ) {
            selectedScore = contextualizedLocalScore;
            selectedUnit = unit;
            aggregationMode =
                weight >= 0.999
                    ? 'structurally-grounded-local'
                    : (
                        localStructural.personal_target
                            ? 'personal-local-uplift'
                            : 'bounded-local-uplift'
                    );
        }
    }

    selectedScore = roundScore(clamp01(selectedScore));

    const selectedModelResult = {
        ...baseResult,
        classification:
            selectedScore >= TOXICITY_THRESHOLD
                ? 'toxic'
                : 'not-toxic',
        toxicity_score: selectedScore,
        scores: {
            ...baseResult.scores,
            'not-toxic': roundScore(1 - selectedScore),
            toxic: selectedScore
        }
    };

    return {
        strongest_model_result: selectedModelResult,
        strongest_unit: selectedUnit,
        raw_strongest_model_result: rawStrongestModelResult,
        raw_strongest_unit: rawStrongestUnit,
        full_model_result: fullModelResult,
        aggregation_mode: aggregationMode,
        variant_count: entries.length,
        batch_count: inference.batch_count,
        cache_hits: inference.cache_hits,
        unique_inference_count: inference.unique_inference_count
    };
}

/* ============================================================================
 * 4. DETECCIÓN ESTRUCTURAL DE ATAQUES
 * ============================================================================ */

const HUMAN_ROLE_SOURCE = String.raw`(?:
    autor(?:a)?|
    periodista|
    editor(?:a)?|
    funcionari[oa]|
    vocer[oa]|
    columnista|
    redactor(?:a)?|
    analista|
    cronista|
    moderador(?:a)?|
    usuari[oa]|
    diputad[oa]|
    senador(?:a)?|
    ministr[oa]|
    president(?:e|a)|
    juez(?:a)?|
    fiscal|
    abogad[oa]|
    medic[oa]|
    docente|
    conductor(?:a)?|
    panelista|
    comentarista|
    gobernador(?:a)?|
    intendent(?:e|a)|
    persona|
    tipo|
    hombre|
    mujer|
    pibe|
    piba|
    candidat[oa]|
    responsable(?:s)?|
    author|
    journalist|
    editor|
    analyst|
    moderator|
    columnist|
    reporter|
    official|
    writer|
    candidate|
    spokesperson|
    reviewer|
    commenter|
    commentator|
    user|
    person|
    guy|
    man|
    woman|
    publisher
)`.replace(/\s+/gu, '');

const SECOND_PERSON_PATTERN =
    /\b(?:vos|tu|tuyo|tuya|tuyos|tuyas|usted|ustedes|vosotros|vosotras|te|ti|contigo|sos|eres|sois|seas|pareces|pareceis|sonas|decis|entendes|entiendes|sabes|podes|puedes|queres|seguis|hablas|escribis|respondes|lees|interpretas|comprendes|demostras|demuestras|necesitas|tenes|tienes|terminas|seguis|sigues|entendiste|volve|revisa|leiste|ignoraste|repetis|volves|inventas|distinguis|you|your|yours|yourself|yourselves|youre|voce|voces)\b/u;

const HUMAN_ROLE_PATTERN =
    new RegExp(
        String.raw`\b${HUMAN_ROLE_SOURCE}\b`,
        'u'
    );

const HUMAN_GROUP_PATTERN =
    /\b(?:autores|autoras|periodistas|editores|editoras|funcionarios|funcionarias|voceros|voceras|columnistas|redactores|redactoras|analistas|cronistas|moderadores|moderadoras|usuarios|usuarias|diputados|diputadas|senadores|senadoras|ministros|ministras|presidentes|presidentas|jueces|fiscales|abogados|abogadas|medicos|medicas|docentes|conductores|conductoras|panelistas|comentaristas|gobernadores|gobernadoras|intendentes|intendentas|personas|tipos|hombres|mujeres|pibes|pibas|responsables|authors|journalists|editors|analysts|moderators|columnists|reporters|officials|writers|candidates|spokespersons|reviewers|commenters|users|people|guys|men|women|publishers)\b/u;

const NON_PERSONAL_TARGET_SOURCE = String.raw`(?:
    nota|
    articulo|
    cobertura|
    argumento|
    razonamiento|
    idea|
    medida|
    decision|
    politica|
    gestion|
    situacion|
    resultado|
    metodologia|
    analisis|
    conclusion|
    explicacion|
    dato(?:s)?|
    sistema|
    servicio|
    demora|
    respuesta|
    resolucion|
    propuesta|
    proyecto|
    ley|
    fallo|
    contenido|
    texto|
    comparacion|
    enfoque|
    procedimiento|
    informe|
    reporte|
    tramite|
    burocracia|
    bug|
    caida\s+del\s+sistema|
    trafico|
    inflacion|
    humedad|
    corte\s+de\s+luz|
    espera|
    formulario|
    conexion|
    interfaz|
    error\s+de\s+carga|
    precio|
    plataforma|
    aplicacion|
    servidor|
    article|
    coverage|
    argument|
    reasoning|
    idea|
    measure|
    decision|
    policy|
    management|
    situation|
    result|
    methodology|
    analysis|
    conclusion|
    explanation|
    data|
    system|
    service|
    delay|
    response|
    resolution|
    proposal|
    project|
    law|
    ruling|
    content|
    text|
    comparison|
    approach|
    procedure|
    report|
    method|
    evidence|
    claim
)`.replace(/\s+/gu, '');

const NON_PERSONAL_TARGET_PATTERN =
    new RegExp(
        String.raw`\b${NON_PERSONAL_TARGET_SOURCE}\b`,
        'u'
    );

/*
 * Entidades inequívocamente no humanas frecuentes en operaciones técnicas,
 * sanitarias o de control de plagas. Se mantiene deliberadamente acotado para
 * no convertir referencias a animales/personas ambiguas en una excepción global.
 */
const NON_HUMAN_ENTITY_SOURCE = String.raw`(?:
    mosquitos?|
    cucarachas?|
    bacterias?|
    plagas?|
    malezas?|
    hongos?|
    termitas?|
    malware|
    virus(?:\s+informaticos?)?|
    procesos?\s+zombis?|
    pests?|
    mosquitoes?|
    cockroaches?|
    bacteria|
    weeds?|
    fungi|
    termites?|
    computer\s+viruses?|
    zombie\s+processes?
)`.replace(/\s+/gu, '');

const NON_HUMAN_TARGET_PATTERN =
    new RegExp(
        String.raw`\b${NON_HUMAN_ENTITY_SOURCE}\b`,
        'u'
    );

const NON_HUMAN_ACTION_PATTERN =
    new RegExp(
        String.raw`(?:\b(?:eliminar|matar|erradicar|combatir|quitar|controlar|destruir|remove|kill|eradicate|combat|eliminate|control|destroy)\b[^.!?;:\n]{0,80}\b${NON_HUMAN_ENTITY_SOURCE}\b|\b${NON_HUMAN_ENTITY_SOURCE}\b[^.!?;:\n]{0,80}\b(?:eliminar|matar|erradicar|combatir|quitar|controlar|destruir|remove|kill|eradicate|combat|eliminate|control|destroy)\b)`,
        'u'
    );

const AFFECTIONATE_ADDRESS_PATTERN =
    /\b(?:bolud[oa]s?|amig[oa]s?|capo|capa|geni[oa]|crack)\b/u;

const AFFECTIONATE_POSITIVE_CUE_PATTERN =
    /\b(?:que\s+(?:buen|gran)\s+(?:dato|aporte)|que\s+buena\s+(?:observacion|fuente)|tremendo\s+aporte|que\s+grande|sos\s+un\s+(?:capo|animal|genio)|sos\s+una\s+(?:capa|genia)|gracias|buenisimo|excelente\s+aporte|bien\s+ahi|mira\s+eso|ahora\s+(?:entiendo|se\s+entiende)|me\s+(?:sirve|ayudaste|hiciste\s+revisar|cambiaste\s+la\s+lectura)|aclara\s+bastante|queda\s+(?:mucho\s+)?mas\s+claro|voy\s+a\s+leer)\b/u;

const EXPLICIT_HOSTILITY_CUE_PATTERN =
    /\b(?:no\s+entend(?:es|iste|io)|no\s+sabes|no\s+tenes|callate|cerra\s+el\s+orto|anda(?:te)?\s+a|metete|chupame|romp(?:er|as)\s+(?:las\s+pelotas|los\s+huevos)|pelotudeces|pavadas?|un\s+carajo)\b/u;

function isAffectionateRioplatenseClause(clause) {
    const canonical = canonicalizeForStructure(clause);
    if (!canonical
        || !AFFECTIONATE_ADDRESS_PATTERN.test(canonical)
        || !AFFECTIONATE_POSITIVE_CUE_PATTERN.test(canonical)
        || EXPLICIT_HOSTILITY_CUE_PATTERN.test(canonical)
    ) {
        return false;
    }
    const residual =
        canonical
            .replace(/\bbolud[oa]s?\b/gu, ' ')
            .replace(/\banimal(?:es)?\b/gu, ' ')
            .replace(/\b(?:capo|capa|geni[oa]|crack)\b/gu, ' ')
            .replace(/\s+/gu, ' ')
            .trim();
    return !DEROGATORY_TERM_PATTERN.test(residual);
}

const ATTRIBUTIVE_IDENTITY_ABUSE_PATTERN =
    new RegExp(
        String.raw`(?:\b(?:vos\s+)?(?:sos|eres|pareces|sonas|quedaste|resultas|terminas\s+sonando\s+como|terminas\s+siendo)\b[^.!?;:\n]{0,28}\b${ATTRIBUTIVE_IDENTITY_ABUSE_SOURCE}\b|\b${HUMAN_ROLE_SOURCE}\b[^.!?;:\n]{0,65}\b(?:es|son|parece|parecen|queda(?:\s+como)?|quedan(?:\s+como)?|resulta|resultan|demuestra\s+ser|demuestra\s+que\s+es|termina\s+siendo|termina\s+sonando\s+como)\b[^.!?;:\n]{0,32}\b${ATTRIBUTIVE_IDENTITY_ABUSE_SOURCE}\b)`,
        'u'
    );

const VOCATIVE_IDENTITY_ABUSE_PATTERN =
    new RegExp(
        String.raw`(?:\b${HUMAN_ROLE_SOURCE}\b[^,!?;:\n]{0,48},[^.!?;:\n]{0,25}\b(?:que\s+)?${ATTRIBUTIVE_IDENTITY_ABUSE_SOURCE}\b|\b(?:el|la|los|las)\s+que\b[^,!?;:\n]{0,100},[^.!?;:\n]{0,25}\b(?:que\s+)?${ATTRIBUTIVE_IDENTITY_ABUSE_SOURCE}\b)`,
        'u'
    );

const STRONG_VULGAR_PERSONAL_PREDICATE_SOURCE =
    String.raw`(?:mierda|cagada|porqueria|reverenda\s+porqueria|desastre\s+de\s+mierda)`;

const PERSONAL_VULGAR_PREDICATE_PATTERN =
    new RegExp(
        String.raw`(?:\b(?:vos|tu|usted)\b\s+(?:sos|eres|es)\s+(?:(?:un|una)\s+)?${STRONG_VULGAR_PERSONAL_PREDICATE_SOURCE}\b|\b${HUMAN_ROLE_SOURCE}\b(?:\s+de\s+arriba|\s+que\s+(?:escribio|respondio|publico)\s+eso)?\s+(?:es|son|resulta|resultan)\s+(?:(?:un|una)\s+)?${STRONG_VULGAR_PERSONAL_PREDICATE_SOURCE}\b|\b(?:autores|autoras|periodistas|editores|editoras|funcionarios|funcionarias|usuarios|usuarias|redactores|redactoras|moderadores|moderadoras|comentaristas)\b\s+(?:son|resultan)\s+(?:(?:unos|unas|un|una)\s+)?${STRONG_VULGAR_PERSONAL_PREDICATE_SOURCE}\b)`,
        'u'
    );

const NEGATIVE_EVALUATION_PATTERN =
    /\b(?:debil|absurd[oa]|ridicul[oa]|vergonzos[oa]|desastre|lamentable|inconsistente|mal[oa]|pesim[oa]|flojo|floja|errone[oa]|equivocad[oa]|insostenible|inaceptable|indignante|deficiente|pobre|terrible|ridiculous|disaster|deeply\s+flawed|flawed|frustrating|poorly\s+supported|incoherent|weak|absurd|inconsistent|wrong|misleading|unsupported)\b/u;

const DIRECT_SECOND_PERSON_ATTACK_PATTERN =
    new RegExp(
        String.raw`(?:\b(?:vos\s+)?(?:sos|eres|pareces|sonas|resultas|quedaste|quedas|terminas\s+(?:siendo|sonando\s+como)|seguis\s+siendo|sigues\s+siendo)\b[^.!?;:\n]{0,65}\b${DEROGATORY_TERM_SOURCE}\b|\byou\b[^.!?;:\n]{0,18}\b(?:are|sound|seem|look|act)\b[^.!?;:\n]{0,55}\b${DEROGATORY_TERM_SOURCE}\b|\b(?:voce|voces)\b[^.!?;:\n]{0,18}\be\b[^.!?;:\n]{0,55}\b${DEROGATORY_TERM_SOURCE}\b|\bsei\b[^.!?;:\n]{0,55}\b${DEROGATORY_TERM_SOURCE}\b|\bwhat\s+(?:an?|a\s+complete|an\s+absolute)\s+${DEROGATORY_TERM_SOURCE}\b[^.!?;:\n]{0,45}\byou\s+are\b)`,
        'u'
    );

const HUMAN_COPULAR_ATTACK_PATTERN =
    new RegExp(
        String.raw`\b${HUMAN_ROLE_SOURCE}\b[^.!?;:\n]{0,65}\b(?:es|son|parece|parecen|resulta|resultan|resulto(?:\s+ser)?|resultaron(?:\s+ser)?|queda(?:\s+como)?|quedan(?:\s+como)?|quedo(?:\s+como)?|demuestra\s+ser|termina\s+(?:siendo|sonando\s+como|pareciendo)|termino\s+(?:siendo|sonando\s+como|pareciendo)|suena\s+como|no\s+puede(?:n)?\s+ser|is|are|seems?|sounds?|looks?|turns?\s+out\s+to\s+be|proves?\s+to\s+be|remains?)\b[^.!?;:\n]{0,50}\b${DEROGATORY_TERM_SOURCE}\b`,
        'u'
    );

const VOCATIVE_ATTACK_PATTERN =
    new RegExp(
        String.raw`(?:\b${HUMAN_ROLE_SOURCE}\b[^,!?;:\n]{0,48},[^.!?;:\n]{0,65}\b${DEROGATORY_TERM_SOURCE}\b|\b(?:el|la|los|las)\s+que\b[^,!?;:\n]{0,100},[^.!?;:\n]{0,65}\b${DEROGATORY_TERM_SOURCE}\b|\b${DEROGATORY_TERM_SOURCE}\b\s*,?\s*(?:vos|tu|usted|you)\b|\byou\s+${DEROGATORY_TERM_SOURCE}\b)`,
        'u'
    );

const RELATIONAL_TARGET_ATTACK_PATTERN =
    new RegExp(
        String.raw`\b(?:quien(?:es)?|(?:el|la|los|las)\s+que|whoever|who|those\s+who|the\s+one\s+who|the\s+person\s+who)\b[^.!?;:\n]{0,120}\b(?:si\s+)?(?:es|son|parece|parecen|resulta|resultan|resulta\s+ser|queda(?:\s+como)?|quedan(?:\s+como)?|termina\s+(?:siendo|sonando\s+como|pareciendo)|demuestra\s+ser|demuestra\s+que\s+es|suena\s+como|is|are|seems?|looks?|shows?\s+itself\s+to\s+be)\b[^.!?;:\n]{0,55}\b${PERSONAL_DEROGATION_SOURCE}\b`,
        'u'
    );

const RELATIONAL_HUMAN_REFERENCE_PATTERN =
    /\b(?:quien(?:es)?\s+(?:escribio|publico|respondio|armo|preparo|redacto|defiende|defendio|comento|explico)|(?:el|la|los|las)\s+que\s+(?:escribio|publico|respondio|armo|preparo|redacto|defiende|defendio|comento|explico)|whoever\s+(?:wrote|published|replied|prepared|defends?)|the\s+(?:person|one)\s+who\s+(?:wrote|published|replied|prepared|defends?))\b/u;

const REVERSED_GROUP_ATTACK_PATTERN =
    new RegExp(
        String.raw`(?:\bque\s+${DEROGATORY_TERM_SOURCE}\b[^.!?;:\n]{0,30}\bson\b[^.!?;:\n]{0,65}\b(?:los\s+que|las\s+que|quienes|los\s+responsables|las\s+responsables|estos\s+periodistas|estas\s+periodistas|estos\s+funcionarios|estas\s+funcionarias|estos\s+redactores|estas\s+redactoras|los\s+autores|las\s+autoras)\b|\bwhat\s+(?:a\s+)?(?:bunch\s+of\s+)?${DEROGATORY_TERM_SOURCE}\b[^.!?;:\n]{0,55}\b(?:those\s+who|these\s+writers|these\s+journalists|the\s+authors|the\s+people)\b)`,
        'u'
    );

const GROUP_COPULAR_ATTACK_PATTERN =
    new RegExp(
        String.raw`\b(?:los|las|estos|estas|quienes)\b[^.!?;:\n]{0,150}\b(?:son|parecen|resultan|quedan|siguen\s+siendo)\b[^.!?;:\n]{0,55}\b${DEROGATORY_TERM_SOURCE}\b`,
        'u'
    );

const CONTINUATION_COPULAR_ATTACK_PATTERN =
    new RegExp(
        String.raw`^(?:es|son|sos|eres|parece|parecen|resulta|resultan|queda|quedan)\b[^.!?;:\n]{0,30}\b(?:${DEROGATORY_TERM_SOURCE}|${ATTRIBUTIVE_IDENTITY_ABUSE_SOURCE}|${STRONG_VULGAR_PERSONAL_PREDICATE_SOURCE})\b`,
        'u'
    );

const RHETORICAL_PERSONAL_ATTACK_PATTERN =
    new RegExp(
        String.raw`(?:\b(?:como|por\s+que|no)\b[^.!?;:\n]{0,70}\b${PERSONAL_DEROGATION_SOURCE}\b[^.!?;:\n]{0,55}\b(?:sos|eres|pareces|sonas|vos|tu|te)\b|\b(?:vos|tu|te)\b[^.!?;:\n]{0,65}\b${PERSONAL_DEROGATION_SOURCE}\b|\b(?:how\s+can\s+you\s+be|are\s+you\s+really|do\s+you\s+try\s+to\s+sound\s+like|how\s+much\s+of|what\s+kind\s+of|who\s+could\s+be)\b[^.!?;:\n]{0,70}\b${PERSONAL_DEROGATION_SOURCE}\b)`,
        'u'
    );

const THIRD_PERSON_RHETORICAL_ATTACK_PATTERN =
    new RegExp(
        String.raw`(?:\b(?:de\s+verdad\s+)?(?:${HUMAN_ROLE_SOURCE}|(?:el|la)\s+que\b[^?!.;:\n]{0,80})\b[^?!.;:\n]{0,65}\b(?:no\s+(?:nota|ve|advierte|se\s+da\s+cuenta))\b[^?!.;:\n]{0,55}\blo\s+${PERSONAL_DEROGATION_SOURCE}\b|\bque\s+clase\s+de\s+${PERSONAL_DEROGATION_SOURCE}\b[^?!.;:\n]{0,25}\b(?:es|son|resulta|parece)\b[^?!.;:\n]{0,70}\b(?:${HUMAN_ROLE_SOURCE}|(?:el|la)\s+que)\b|\bcomo\s+puede\b[^?!.;:\n]{0,80}\b(?:${HUMAN_ROLE_SOURCE}|(?:el|la)\s+que)\b[^?!.;:\n]{0,55}\bser\s+tan\s+${PERSONAL_DEROGATION_SOURCE}\b|\bse\s+puede\s+quedar\s+mas\s+${PERSONAL_DEROGATION_SOURCE}\b[^?!.;:\n]{0,35}\bque\b[^?!.;:\n]{0,100}\b(?:${HUMAN_ROLE_SOURCE}|(?:el|la)\s+que)\b)`,
        'u'
    );

/*
 * Preguntas retóricas en segunda persona donde la descalificación aparece
 * después de un modal o de un verbo de estado:
 *
 *     "¿Cómo podés ser tan X?"
 *     "¿Podés quedar más X después de eso?"
 *
 * El patrón describe la relación sintáctica y no una frase de batería.
 */
const SECOND_PERSON_MODAL_RHETORICAL_ATTACK_PATTERN =
    new RegExp(
        String.raw`(?:\b(?:como|por\s+que)\s+(?:podes|puedes|podrias|podrías)\b[^.!?;:\n]{0,28}\b(?:ser|quedar|parecer|sonar)\b[^.!?;:\n]{0,28}\b(?:tan|mas|como\s+un|como\s+una)?\s*${PERSONAL_DEROGATION_SOURCE}\b|\b(?:podes|puedes)\s+quedar\s+mas\s+${PERSONAL_DEROGATION_SOURCE}\b)`,
        'u'
    );

/*
 * Preguntas impersonales que utilizan una descalificación para degradar a una
 * persona identificada en la misma cláusula:
 *
 *     "¿Hay que ser X para ... o es cosa del autor?"
 *
 * La presencia del objetivo humano evita que "hay que ser ..." funcione como una
 * regla global sobre cualquier uso abstracto de la palabra.
 */
const TARGETED_IMPERSONAL_RHETORICAL_ATTACK_PATTERN =
    new RegExp(
        String.raw`(?:\bhay\s+que\s+ser\b[^.!?;:\n]{0,24}\b${PERSONAL_DEROGATION_SOURCE}\b[^.!?;:\n]{0,130}\b(?:${HUMAN_ROLE_SOURCE}|(?:el|la|los|las)\s+que\b|vos|tu|te|usted|sos|eres|podes|puedes)\b|\b(?:${HUMAN_ROLE_SOURCE}|(?:el|la|los|las)\s+que\b|vos|tu|te|usted)\b[^.!?;:\n]{0,130}\bhay\s+que\s+ser\b[^.!?;:\n]{0,24}\b${PERSONAL_DEROGATION_SOURCE}\b)`,
        'u'
    );

/*
 * Degradación de capacidad. Se modela como una familia de predicados, no como
 * una lista de oraciones: incapacidad de pensar/leer/entender, falta de criterio,
 * insuficiencia intelectual y construcciones equivalentes en inglés.
 */

const COMPETENCE_ATTACK_PATTERN =
    /\b(?:no\s+(?:entendes|entiendes|entiende|entienden|sabes|sabe|saben|servis|sirves|sirve|sirven|podes|puedes|puede|pueden|interpretas|interpreta|interpretan|comprendes|comprende|comprenden)\b[^.!?;:\n]{0,48}\b(?:nada|ni\s+lo\s+(?:mas\s+)?(?:basico|elemental)|ni\s+(?:una|un)\s+(?:comparacion|idea|explicacion|argumento|dato|concepto)\s+(?:basica|basico|simple|elemental)|un\s+carajo)|no\s+(?:sirve|sirven|servis|sirves)\s+para\s+(?:analizar|explicar|interpretar|razonar|pensar|leer|comprender|entender)\b[^.!?;:\n]{0,65}|no\s+(?:(?:te|le|les)\s+)?da\s+la\s+cabeza\b[^.!?;:\n]{0,75}|no\s+(?:(?:te|le|les)\s+)?(?:alcanza|da)\s+para\s+(?:seguir|leer|comparar|interpretar|procesar|comprender|entender|pensar|razonar|analizar)\b[^.!?;:\n]{0,70}|no\s+(?:tenes|tienes|tiene|tienen)\s+(?:capacidad\s+para\s+(?:explicar|analizar|interpretar|razonar|pensar|comprender|entender)|dos\s+dedos\s+de\s+frente|idea\s+de\s+lo\s+que\s+(?:decis|dice|dicen|esta\s+diciendo|estan\s+diciendo)|capacidad\s+para|criterio\s+para)|no\s+(?:podes|puedes|puede|pueden)\s+(?:seguir|leer|comparar|interpretar|procesar|comprender|entender|pensar|razonar)\b[^.!?;:\n]{0,70}|(?:pensar|razonar|leer|comprender|interpretar)\b[^.!?;:\n]{0,40}\bno\s+es\s+(?:lo\s+tuyo|tu\s+fuerte)|parece\s+(?:que\s+)?(?:incapaz\s+de|no\s+tener\s+idea\s+de|que\s+(?:pensar|razonar|leer|comprender|interpretar)\b[^.!?;:\n]{0,30}\bno\s+es\s+(?:lo\s+tuyo|tu\s+fuerte))|demuestra\s+(?:que\s+)?no\s+(?:puede|pueden)\s+(?:pensar|razonar|leer|comprender|interpretar)\b[^.!?;:\n]{0,70}|habla\s+como\s+si\s+no\s+(?:supiera|supiese)\s+(?:leer|pensar|razonar|interpretar|comprender)|no\s+esta(?:n)?\s+a\s+la\s+altura\b[^.!?;:\n]{0,65}|falta\s+de\s+(?:criterio|capacidad|comprension|inteligencia)|torpeza\b[^.!?;:\n]{0,70}\b(?:interpretar|razonar|leer|comprender|analizar)|te\s+falta\s+(?:cerebro|capacidad|inteligencia|comprension|criterio)|te\s+(?:supera|cuesta\s+(?:demasiado|mucho)|queda\s+grande)\b[^.!?;:\n]{0,65}|la\s+comprension\s+lectora\s+no\s+es\s+tu\s+fuerte|se\s+nota\s+que\s+no\s+(?:entendes|entiendes|sabes|comprendes)\b[^.!?;:\n]{0,50}|no\s+(?:sos|eres)\s+capaz\s+de\s+(?:entender|leer|seguir|interpretar|comprender|pensar|razonar)\b[^.!?;:\n]{0,50}|(?:you\s+)?(?:cannot|can\s+not|cant|can'?t)\s+(?:think|reason|read|understand|interpret|follow|process)\b[^.!?;:\n]{0,70}|(?:you\s+)?(?:do\s+not|don'?t)\s+(?:understand|know|grasp)\b[^.!?;:\n]{0,65}|(?:you\s+)?(?:have|has)\s+no\s+(?:idea|clue|capacity|ability)\b[^.!?;:\n]{0,70}|(?:seems?|appears?)\s+(?:incapable|unable)\s+to\s+(?:think|reason|read|understand|interpret)|(?:lacks?|lacking)\s+(?:judgment|reasoning|understanding|intelligence|basic\s+comprehension))\b/u;

const IMPERSONAL_RHETORICAL_ATTACK_PATTERN =
    new RegExp(
        String.raw`\b(?:hay\s+que\s+ser|quien\s+(?:puede\s+)?ser|como\s+(?:alguien|una\s+persona)\s+puede\s+(?:ser|quedar)|que\s+clase\s+de|que\s+tan|(?:en\s+serio\s+)?necesitas\s+ser|how\s+can\s+(?:someone|a\s+person)\s+be|what\s+kind\s+of\s+(?:person|writer)|who\s+could\s+be)\b[^.!?;:\n]{0,65}\b${DEROGATORY_TERM_SOURCE}\b`,
        'u'
    );

const DISMISSIVE_IMPERATIVE_ATTACK_PATTERN =
    new RegExp(
        String.raw`\b(?:callate|anda(?:te)?\s+(?:a\s+)?(?:estudiar|leer|aprender)|(?:primero\s+)?aprende\s+(?:a\s+(?:leer|estudiar|razonar|interpretar)|lo\s+basico)|deja\s+de\s+(?:opinar|comentar|hablar|hacerte|joder)|volve\s+(?:cuando|a\s+(?:revisar|leer|estudiar|mirar))|volvete|no\s+vengas\s+a\s+dar\s+lecciones|no\s+seas(?:\s+(?:un|una))?|no\s+te\s+hagas\s+(?:el|la)|no\s+actues\s+como(?:\s+(?:un|una))?|no\s+sigas\s+comportandote\s+como(?:\s+(?:un|una))?|no\s+quedes\s+como(?:\s+(?:un|una))?|no\s+te\s+comportes\s+como(?:\s+(?:un|una))?|no\s+(?:escribas|opines|vengas)|shut\s+up|fuck\s+off|get\s+lost|go\s+away|stop\s+talking|quit\s+(?:your\s+)?bullshit|stop\s+pretending)\b[^.!?;:\n]{0,90}\b${DEROGATORY_TERM_SOURCE}\b`,
        'u'
    );

const IMPLICIT_HUMAN_AGENT_ATTACK_PATTERN =
    new RegExp(
        String.raw`(?:\b${NON_PERSONAL_TARGET_SOURCE}\b[^.!?;:\n]{0,55}\b(?:lo|la|los|las)?\s*(?:escribio|redacto|publico|preparo|elaboro|hizo|armo|firmo|presento|produjo|was\s+(?:written|prepared|published|produced)\s+by)\b[^.!?;:\n]{0,45}\b(?:un|una|unos|unas|an?|the)?\s*(?:\w+\s+){0,2}${DEROGATORY_TERM_SOURCE}\b|\b${NON_PERSONAL_TARGET_SOURCE}\b[^.!?;:\n]{0,55}\b(?:fue|ha\s+sido)\s+(?:escrito|redactado|publicado|preparado|elaborado|hecho|firmado|presentado|producido)\s+por\b[^.!?;:\n]{0,40}\b(?:un|una|unos|unas)\s+(?:\w+\s+){0,2}${DEROGATORY_TERM_SOURCE}\b)`,
        'u'
    );

/*
 * Detecta una descalificación anafórica inmediatamente posterior a una cláusula
 * que ya identificó una persona. Los conectores expresan continuidad discursiva,
 * no frases completas memorizadas.
 */
const ANAPHORIC_PERSONAL_ATTACK_PATTERN =
    new RegExp(
        String.raw`(?:\b(?:ahi|entonces|en\s+ese\s+(?:momento|punto)|a\s+esa\s+altura|despues\s+de\s+eso|con\s+esa\s+(?:respuesta|reaccion|intervencion)|por\s+eso)\b[^.!?;:\n]{0,60}\b(?:demuestra(?:\s+que)?|queda\s+claro\s+que|se\s+nota\s+que|confirma\s+que|se\s+convierte\s+en|termina\s+siendo|resulta\s+ser|parece|es)\b[^.!?;:\n]{0,45}\b${DEROGATORY_TERM_SOURCE}\b|\bno\s+se\s+como\b[^.!?;:\n]{0,45}\b(?:puede\s+ser|es|queda|resulta)\b[^.!?;:\n]{0,35}\b${DEROGATORY_TERM_SOURCE}\b|\b(?:after\s+that|with\s+that\s+(?:answer|reply|response)|at\s+that\s+point|then)\b[^.!?;:\n]{0,65}\b(?:it\s+is\s+clear\s+that|shows?\s+that|confirms?\s+that|proves?\s+that|seems?\s+to\s+be|is)\b[^.!?;:\n]{0,50}\b${DEROGATORY_TERM_SOURCE}\b)`,
        'u'
    );

const DIRECTED_PROFANITY_PATTERN =
    new RegExp(
        String.raw`(?:\b(?:andate|anda|vete)\b[^.!?;:\n]{0,28}\b(?:a\s+)?(?:la\s+)?(?:mierda|cagar|concha\s+de\s+tu\s+madre|puta\s+que\s+te\s+pario)\b|\b(?:callate|cerra)\b[^.!?;:\n]{0,16}\b(?:el\s+)?orto\b|\b(?:deja|dejate|no\s+me|no)\b[^.!?;:\n]{0,28}\bromp(?:er|as|e)\b[^.!?;:\n]{0,18}\b(?:las\s+(?:pelotas|bolas)|los\s+huevos)\b|\b(?:metete|metetelo|guardate)\b[^.!?;:\n]{0,70}\b(?:culo|orto|donde\s+no\s+te\s+da\s+el\s+sol)\b|\bchupame\b[^.!?;:\n]{0,18}\b(?:la\s+pija|un\s+huevo)\b|\bque\s+(?:mierda|carajo)\b[^.!?;:\n]{0,28}\b(?:te\s+pasa|decis|dices)\b|\bquien\s+(?:mierda|carajo)\b[^.!?;:\n]{0,45}\b(?:te\s+crees|te\s+pensas)\b[^.!?;:\n]{0,20}\b(?:que\s+)?sos\b|\banda\b[^.!?;:\n]{0,18}\ba\s+chamuyar\s+a\s+otro\s+lado\b|\bno\s+vengas\s+a\s+hacer(?:te|se)\s+el\s+vivo\b|\bdeja\s+de\s+(?:hablar\s+al\s+pedo|decir\s+pelotudeces)\b|\bguardate\s+esa\s+soberbia\b|\bla\s+concha\s+de\s+tu\s+madre\b|\b(?:vos|tu|te)\b[^.!?;:\n]{0,55}\b(?:mierda|carajo|forro|forra|sorete|conchudo|conchuda)\b|\b${HUMAN_ROLE_SOURCE}\b[^.!?;:\n]{0,45}\b(?:se\s+puede\s+ir|que\s+se\s+vaya)\b[^.!?;:\n]{0,20}\ba\s+la\s+mierda\b|\bque\s+se\s+vaya\s+a\s+la\s+mierda\b[^.!?;:\n]{0,45}\b${HUMAN_ROLE_SOURCE}\b|\b(?:fuck\s+off|get\s+lost|shut\s+(?:the\s+hell\s+)?up|go\s+away)\b[^.!?;:\n]{0,55}\b(?:you\s+)?${DEROGATORY_TERM_SOURCE}\b|\b(?:you|your)\b[^.!?;:\n]{0,50}\b(?:fuck|fucking|bullshit|asshole|dumbass|jackass)\b)`,
        'u'
    );

/*
 * Analiza únicamente texto contextual ya preparado.

/*
 * Analiza únicamente texto contextual ya preparado. Las citas, reportes y
 * rechazos protegidos fueron neutralizados antes de llegar aquí.
 */
/**
 * Detecta evidencia estructural de ataque sobre contenido no protegido.
 *
 * No busca frases completas memorizadas: combina objetivo humano, segunda
 * persona, atribución, predicado descalificador, vocativo, profanidad dirigida y
 * otras relaciones generales para producir `direct_attack_signal`.
 *
 * @param {string} text
 * @returns {object}
 */
function analyzeStructuralEvidence(text) {
    const canonical =
        canonicalizeForStructure(text);

    /*
     * La puntuación repetida puede utilizarse para separar artificialmente una
     * estructura que sigue siendo una sola unidad lingüística, por ejemplo
     * "sos... un!!! insulto". Se agrega una segunda vista donde únicamente las
     * rachas de puntuación de dos o más signos se tratan como espacios.
     * La puntuación simple conserva su función normal de separar cláusulas.
     */
    const punctuationRelaxed =
        canonical
            .replace(/[.!?,;:…]{2,}/gu, ' ')
            .replace(/\s+/gu, ' ')
            .trim();

    const baseClauses =
        uniqueStrings([
            ...splitSemanticClauses(canonical),
            ...splitSemanticClauses(
                punctuationRelaxed
            )
        ]);

    const clauses =
        uniqueStrings(
            baseClauses.flatMap(
                (clause) => [
                    clause,
                    repairSingleCharacterEvasionForStructure(
                        clause
                    )
                ]
            )
        );

    let directAttackSignal = 0;
    let personalTarget = false;
    let nonPersonalTarget = false;
    let nonHumanTarget = false;
    let derogatoryLexeme = false;
    let directedProfanity = false;

    /*
     * Los vocativos afectuosos pueden separarse de su predicado positivo por la
     * misma segmentación que usamos para ataques ("Amigo, sos un animal").
     * Por eso v4.1 evalúa también el comentario completo y pares de cláusulas
     * adyacentes, sin desactivar ataques residuales presentes en otras cláusulas.
     */
    let affectionateContext =
        isAffectionateRioplatenseClause(
            canonical
        )
        || baseClauses.some(
            (clause, index) =>
                index + 1 < baseClauses.length
                && isAffectionateRioplatenseClause(
                    `${clause}, ${baseClauses[index + 1]}`
                )
        );

    const attackTypes = new Set();

    let previousClauseHadHumanTarget = false;

    for (const clause of clauses) {
        const clauseHasSecondPerson =
            SECOND_PERSON_PATTERN.test(clause);

        const clauseHasHumanRole =
            HUMAN_ROLE_PATTERN.test(clause)
            || HUMAN_GROUP_PATTERN.test(clause);

        const clauseHasRelationalHumanReference =
            RELATIONAL_HUMAN_REFERENCE_PATTERN.test(clause);

        const clauseHasHumanTarget =
            clauseHasSecondPerson
            || clauseHasHumanRole
            || clauseHasRelationalHumanReference;

        const clauseHasCompetenceHumanAnchor =
            clauseHasHumanTarget
            || clauseHasRelationalHumanReference;

        const clauseHasNonPersonalTarget =
            NON_PERSONAL_TARGET_PATTERN.test(clause);

        const clauseHasNonHumanTarget =
            NON_HUMAN_TARGET_PATTERN.test(clause);

        const clauseHasDerogatory =
            DEROGATORY_TERM_PATTERN.test(clause);

        const clauseIsAffectionate =
            isAffectionateRioplatenseClause(
                clause
            );

        affectionateContext ||=
            clauseIsAffectionate;

        personalTarget ||= clauseHasHumanTarget;
        nonPersonalTarget ||= clauseHasNonPersonalTarget;
        nonHumanTarget ||= clauseHasNonHumanTarget;
        derogatoryLexeme ||=
            clauseHasDerogatory
            && !clauseIsAffectionate;

        if (clauseIsAffectionate) {
            previousClauseHadHumanTarget =
                clauseHasHumanTarget
                || clauseHasRelationalHumanReference;
            continue;
        }

        if (
            STANDALONE_STRONG_FAMILY_ABUSE_PATTERN
                .test(clause)
        ) {
            directAttackSignal = Math.max(
                directAttackSignal,
                0.95
            );
            personalTarget = true;
            derogatoryLexeme = true;
            attackTypes.add('standalone-strong-family-abuse');
        }

        if (
            DIRECT_SECOND_PERSON_ATTACK_PATTERN
                .test(clause)
        ) {
            directAttackSignal = Math.max(
                directAttackSignal,
                1
            );
            attackTypes.add('second-person-attribution');
        }

        if (
            HUMAN_COPULAR_ATTACK_PATTERN
                .test(clause)
        ) {
            directAttackSignal = Math.max(
                directAttackSignal,
                0.95
            );
            attackTypes.add('human-copular-attribution');
        }

        if (
            VOCATIVE_ATTACK_PATTERN
                .test(clause)
        ) {
            directAttackSignal = Math.max(
                directAttackSignal,
                0.95
            );
            attackTypes.add('vocative-attack');
        }

        if (
            RELATIONAL_TARGET_ATTACK_PATTERN
                .test(clause)
        ) {
            directAttackSignal = Math.max(
                directAttackSignal,
                0.94
            );
            personalTarget = true;
            derogatoryLexeme = true;
            attackTypes.add('relational-target-attack');
        }

        if (
            REVERSED_GROUP_ATTACK_PATTERN
                .test(clause)
        ) {
            directAttackSignal = Math.max(
                directAttackSignal,
                0.90
            );
            personalTarget = true;
            attackTypes.add('reversed-group-attack');
        }

        if (
            GROUP_COPULAR_ATTACK_PATTERN
                .test(clause)
        ) {
            directAttackSignal = Math.max(
                directAttackSignal,
                0.92
            );
            personalTarget = true;
            attackTypes.add('group-copular-attack');
        }

        if (
            RHETORICAL_PERSONAL_ATTACK_PATTERN
                .test(clause)
        ) {
            directAttackSignal = Math.max(
                directAttackSignal,
                0.85
            );
            attackTypes.add('rhetorical-personal-attack');
        }

        if (
            THIRD_PERSON_RHETORICAL_ATTACK_PATTERN
                .test(clause)
        ) {
            directAttackSignal = Math.max(
                directAttackSignal,
                0.90
            );
            personalTarget = true;
            derogatoryLexeme = true;
            attackTypes.add('third-person-rhetorical-attack');
        }

        if (
            SECOND_PERSON_MODAL_RHETORICAL_ATTACK_PATTERN
                .test(clause)
        ) {
            directAttackSignal = Math.max(
                directAttackSignal,
                0.92
            );
            personalTarget = true;
            derogatoryLexeme = true;
            attackTypes.add('second-person-modal-rhetorical-attack');
        }

        if (
            TARGETED_IMPERSONAL_RHETORICAL_ATTACK_PATTERN
                .test(clause)
        ) {
            directAttackSignal = Math.max(
                directAttackSignal,
                0.90
            );
            personalTarget = true;
            derogatoryLexeme = true;
            attackTypes.add('targeted-impersonal-rhetorical-attack');
        }

        if (
            ATTRIBUTIVE_IDENTITY_ABUSE_PATTERN
                .test(clause)
        ) {
            directAttackSignal = Math.max(
                directAttackSignal,
                0.92
            );
            personalTarget = true;
            derogatoryLexeme = true;
            attackTypes.add('attributive-identity-abuse');
        }

        if (
            VOCATIVE_IDENTITY_ABUSE_PATTERN
                .test(clause)
        ) {
            directAttackSignal = Math.max(
                directAttackSignal,
                0.90
            );
            personalTarget = true;
            derogatoryLexeme = true;
            attackTypes.add('vocative-identity-abuse');
        }

        if (
            PERSONAL_VULGAR_PREDICATE_PATTERN
                .test(clause)
        ) {
            directAttackSignal = Math.max(
                directAttackSignal,
                0.94
            );
            personalTarget = true;
            directedProfanity = true;
            attackTypes.add('personal-vulgar-predicate');
        }

        if (
            IMPERSONAL_RHETORICAL_ATTACK_PATTERN
                .test(clause)
        ) {
            directAttackSignal = Math.max(
                directAttackSignal,
                0.88
            );
            personalTarget = true;
            attackTypes.add('implicit-rhetorical-attack');
        }

        if (
            DISMISSIVE_IMPERATIVE_ATTACK_PATTERN
                .test(clause)
        ) {
            directAttackSignal = Math.max(
                directAttackSignal,
                0.88
            );
            personalTarget = true;
            attackTypes.add('dismissive-imperative-attack');
        }

        if (
            IMPLICIT_HUMAN_AGENT_ATTACK_PATTERN
                .test(clause)
        ) {
            directAttackSignal = Math.max(
                directAttackSignal,
                0.90
            );
            personalTarget = true;
            attackTypes.add('implicit-human-agent-attack');
        }

        if (
            previousClauseHadHumanTarget
            && CONTINUATION_COPULAR_ATTACK_PATTERN
                .test(clause)
        ) {
            directAttackSignal = Math.max(
                directAttackSignal,
                0.95
            );
            personalTarget = true;
            derogatoryLexeme ||= DEROGATORY_TERM_PATTERN.test(clause);
            directedProfanity ||= new RegExp(
                String.raw`\b${STRONG_VULGAR_PERSONAL_PREDICATE_SOURCE}\b`,
                'u'
            ).test(clause);
            attackTypes.add('target-continuation-copular-attack');
        }

        if (
            previousClauseHadHumanTarget
            && ANAPHORIC_PERSONAL_ATTACK_PATTERN
                .test(clause)
        ) {
            directAttackSignal = Math.max(
                directAttackSignal,
                0.88
            );
            personalTarget = true;
            attackTypes.add('anaphoric-personal-attack');
        }

        if (
            COMPETENCE_ATTACK_PATTERN
                .test(clause)
            && clauseHasCompetenceHumanAnchor
        ) {
            directAttackSignal = Math.max(
                directAttackSignal,
                0.82
            );
            personalTarget = true;
            attackTypes.add('competence-degradation');
        }

        if (
            DIRECTED_PROFANITY_PATTERN
                .test(clause)
        ) {
            directAttackSignal = Math.max(
                directAttackSignal,
                0.90
            );
            directedProfanity = true;
            attackTypes.add('directed-profanity');
        }

        /*
         * Respaldo estructural genérico:
         *
         * objetivo humano + descalificación en la misma cláusula.
         *
         * No alcanza para la señal máxima porque todavía puede existir una
         * construcción ambigua, pero aporta evidencia útil si el modelo también
         * observa toxicidad.
         */
        const genericHumanDerogationLink =
            new RegExp(
                String.raw`(?:\b(?:vos|tu|usted|${HUMAN_ROLE_SOURCE})\b[^.!?;:\n]{0,62}\b(?:es|sos|eres|son|parece|parecen|queda(?:\s+como)?|quedan(?:\s+como)?|resulta|resultan|suena|suenan|demuestra\s+ser|termina\s+sonando\s+como|llaman|tratan|tildan|califican)\b[^.!?;:\n]{0,42}\b${PERSONAL_DEROGATION_SOURCE}\b|\b${PERSONAL_DEROGATION_SOURCE}\b[^.!?;:\n]{0,30}\b(?:vos|tu|usted|${HUMAN_ROLE_SOURCE})\b)`,
                'u'
            ).test(clause);

        if (
            clauseHasHumanTarget
            && clauseHasDerogatory
            && genericHumanDerogationLink
        ) {
            directAttackSignal = Math.max(
                directAttackSignal,
                0.65
            );
            attackTypes.add('human-target-plus-derogation');
        }

        previousClauseHadHumanTarget =
            clauseHasHumanTarget
            || clauseHasRelationalHumanReference
            || RELATIONAL_TARGET_ATTACK_PATTERN.test(clause)
            || IMPLICIT_HUMAN_AGENT_ATTACK_PATTERN.test(clause)
            || (
                previousClauseHadHumanTarget
                && /^(?:es|son|sos|eres)\b/u.test(clause)
            );
    }

    return {
        direct_attack_signal:
            roundScore(directAttackSignal),
        personal_target:
            personalTarget,
        non_personal_target:
            nonPersonalTarget,
        non_human_target:
            nonHumanTarget,
        derogatory_lexeme:
            derogatoryLexeme,
        directed_profanity:
            directedProfanity,
        affectionate_context:
            affectionateContext,
        attack_types:
            Array.from(attackTypes)
    };
}

/* ============================================================================
 * 5. CORRECCIONES CONTEXTUALES
 * ============================================================================ */

/*
 * Objetivos no personales frecuentes en expresiones emocionales o figuradas.
 *
 * Esta lista se utiliza únicamente cuando el comentario no contiene segunda
 * persona, rol humano ni otra estructura de ataque. Su función es distinguir
 * enojo con una situación, problema o recurso de una descalificación personal.
 */
const NON_PERSONAL_AFFECT_TARGET_SOURCE = String.raw`(?:
    politica|
    demora|
    burocracia|
    decision|
    error|
    medida|
    situacion|
    gestion|
    servicio|
    sistema|
    problema|
    resultado|
    proceso|
    cambio|
    acuerdo|
    archivo|
    aplicacion|
    computadora|
    servidor|
    documento|
    programa|
    consumo|
    plantas?|
    proyecto|
    respuesta|
    ley|
    fallo|
    metodologia|
    nota|
    articulo|
    cobertura|
    argumento|
    razonamiento|
    idea|
    conclusion|
    explicacion|
    datos?|
    policy|
    delay|
    bureaucracy|
    decision|
    error|
    measure|
    situation|
    management|
    service|
    system|
    problem|
    result|
    process|
    change|
    agreement|
    file|
    application|
    computer|
    server|
    document|
    program|
    consumption|
    plants?|
    project|
    response|
    law|
    ruling|
    methodology|
    article|
    coverage|
    argument|
    reasoning|
    idea|
    conclusion|
    explanation|
    data|
    report|
    analysis|
    content|
    tramite|
    interfaz|
    bug|
    trafico|
    inflacion|
    humedad|
    espera|
    formulario|
    conexion|
    precio|
    caida\s+del\s+sistema|
    corte\s+de\s+luz|
    error\s+de\s+carga|
    suspension|
    aumento|
    procedure|
    interface|
    suspension|
    increase
)`.replace(/\s+/gu, '');

const NON_PERSONAL_PROFANITY_PATTERN =
    new RegExp(
        String.raw`\b(?:esta|este|esa|ese|la|el|this|that|the)?\s*(?:${NON_PERSONAL_AFFECT_TARGET_SOURCE}|caida\s+del\s+sistema|corte\s+de\s+luz|error\s+de\s+carga)\b[^.!?;:\n]{0,70}\b(?:es\s+(?:una\s+)?(?:mierda|cagada|porqueria)|es\s+una\s+reverenda\s+porqueria|es\s+un\s+quilombo|es\s+un\s+desastre\s+de\s+mierda|me\s+tiene\s+podrido|me\s+esta\s+volviendo\s+loco|rompe\s+(?:muchisimo\s+)?las\s+pelotas|es\s+un\s+dolor\s+de\s+huevos|funciona\s+para\s+el\s+orto)\b`,
        'u'
    );

const NON_PERSONAL_EMOTION_PATTERN =
    new RegExp(
        String.raw`\b(?:me\s+da\s+(?:(?:mucha|muchisima)\s+)?bronca|me\s+indigna|me\s+frustra|me\s+molesta(?:\s+muchisimo)?|me\s+resulta\s+(?:frustrante|desesperante)|estoy\s+(?:harto|furioso)\s+de|estoy\s+furioso\s+con|no\s+soporto|no\s+puedo\s+creer|me\s+siento\s+(?:agotado|confundido|frustrado|cansado|abrumado)|i\s+am\s+(?:angry|furious|frustrated|fed\s+up)\s+(?:with|about)|i'?m\s+(?:angry|furious|frustrated|fed\s+up)\s+(?:with|about)|this\s+(?:frustrates|annoys|angers)\s+me|i\s+cannot\s+stand|i\s+can'?t\s+stand)\b[^.!?;:\n]{0,90}\b(?:esta|este|esa|ese|la|el|this|that|the)\s+${NON_PERSONAL_AFFECT_TARGET_SOURCE}\b`,
        'u'
    );

/*
 * Evaluaciones impersonales breves sin destinatario humano.
 *
 * Una expresión como "Es una vergüenza" puede ser severa, pero si no existe un
 * objetivo humano ni segunda persona no debe convertirse automáticamente en una
 * descalificación personal por un score neuronal alto.
 */
const IMPERSONAL_NON_DIRECTED_EVALUATION_PATTERN =
    /^(?:(?:esto|this)\s+)?(?:es|resulta|parece|is|seems)\s+(?:un|una|an?)?\s*(?:verguenza|indignante|frustrante|lamentable|inaceptable|shame|outrageous|frustrating|unfortunate|unacceptable)\.?$/u;

/*
 * Uso figurado de verbos de daño sobre objetos, sistemas, procesos o fenómenos.
 *
 * Se exige un sujeto no humano y un objetivo igualmente no humano. Por ejemplo,
 * "este bug está matando el rendimiento" no tiene la misma estructura que
 * "este usuario está atacando al periodista".
 */
const FIGURATIVE_NON_PERSONAL_PATTERN =
    new RegExp(
        String.raw`(?:\b(?:este|esta|ese|esa|el|la|this|that|the)?\s*(?:problema|error|bug|cambio|proceso|demora|burocracia|inflacion|humedad|medida|politica|situacion|gestion|latencia|costo|coste|problem|change|process|delay|bureaucracy|inflation|humidity|measure|policy|situation|management|latency|cost)\b[^.!?;:\n]{0,70}\b(?:esta\s+|is\s+)?(?:matando|mata|matar|puede\s+matar|mato|hundiendo|hunde|hundir|destruyendo|destruye|destruir|liquidando|liquida|liquidar|rompiendo|rompe|romper|killing|kills?|destroying|destroys?|breaking|breaks?|crushing|ruining|ruins?)\b[^.!?;:\n]{0,50}\b(?:el|la|los|las|este|esta|estos|estas|the|this|these)?\s*(?:archivo|computadora|servidor|sistema|aplicacion|programa|documento|acuerdo|consumo|proyecto|plantas?|iniciativa|rendimiento|servicio|proceso|experiencia|presupuesto|file|computer|server|system|application|program|document|agreement|consumption|project|plants?|initiative|performance|service|process|experience|budget)\b|\b(?:matar\s+el\s+tiempo|kill\s+time)\b)`,
        'u'
    );

const LITERAL_NON_PERSONAL_PATTERN =
    new RegExp(
        String.raw`(?:\b(?:burro|burra|donkey)\b[^.!?;:\n]{0,90}\b(?:sendero|herramientas|carga|campo|animal|trail|tools|load|field)\b|\b(?:payaso|payasa|clown)\b[^.!?;:\n]{0,90}\b(?:escenario|circo|actuacion|obra|numero|funcion|stage|circus|performance|play|show)\b|\bsalame\b[^.!?;:\n]{0,90}\b(?:tabla|comida|cocina|fiambre|picada|heladera|board|food|kitchen)\b|\bnabo\b[^.!?;:\n]{0,90}\b(?:sopa|verdura|cocina|comida)\b|\bgil\b[^.!?;:\n]{0,90}\b(?:pez|marino|glosario|regional)\b|\bfantasma\b[^.!?;:\n]{0,90}\b(?:pelicula|titulo|personaje|obra|ficcion|movie|film|title|character|fiction)\b|\bboton\b[^.!?;:\n]{0,75}\b(?:presionar|pantalla|interfaz|camisa|control|teclado|press|button)\b|\brata\b[^.!?;:\n]{0,75}\b(?:animal|roedor|laboratorio|jaula|raton)\b|\bbasura\b[^.!?;:\n]{0,80}\b(?:reciclar|reciclaje|residuos)\b|\btrash\b[^.!?;:\n]{0,80}\b(?:recycle|recycling|waste)\b|\b${DEROGATORY_TERM_SOURCE}\b[^.!?;:\n]{0,90}\b(?:personaje|obra|ficcion|character|play|fiction)\b)`,
        'u'
    );


/*
 * Uso corporal/literal de vocabulario vulgar sin destinatario humano.
 *
 * Se limita a experiencias o estados en primera persona. De esta forma
 * "me caí de culo", "me duele el culo" o "me golpeé el culo" no heredan un
 * score neuronal alto sólo por la palabra vulgar, mientras construcciones
 * dirigidas como "te voy a patear el culo" quedan completamente fuera.
 */
const LITERAL_BODILY_USAGE_PATTERN =
    /\b(?:me\s+(?:cai|caigo|cayo|golpee|golpeo|lastime|lastimo|sente|siento|quede)|tengo\s+dolor|me\s+duele)\b[^.!?;:\n]{0,34}\b(?:culo|orto)\b/u;

const INSTITUTIONAL_NON_ABUSIVE_ACTION_PATTERN =
    /\b(?:autor|periodista|editor|funcionario|vocero|columnista|redactor|analista|ministro|diputado|senador|moderador|candidato|presidente|gobernador|intendente|official|journalist|editor|writer|candidate|president)\b[^.!?;:\n]{0,45}\b(?:deberia\s+(?:renunciar|dar\s+explicaciones|quedar\s+bajo\s+investigacion|presentarse\s+ante|someterse\s+a\s+una\s+auditoria|publicar\s+la\s+documentacion|responder\s+politicamente)|tendria\s+que\s+dejar\s+el\s+cargo|tiene\s+que\s+(?:responder\s+ante\s+la\s+justicia|cumplir\s+la\s+resolucion)|should\s+(?:resign|explain|be\s+investigated|appear\s+before|publish\s+the\s+documents)|must\s+(?:answer\s+to\s+the\s+court|comply\s+with\s+the\s+ruling))\b/u;

const OBJECT_ACTION_NON_PERSONAL_PATTERN =
    /\b(?:quiero|necesito|hay\s+que|i\s+need\s+to|i\s+want\s+to)\s+(?:romper|tirar|eliminar|borrar|destruir|reemplazar|descartar|reiniciar|matar|bajar|cancelar|cerrar|terminar|cortar|break|throw\s+away|remove|delete|destroy|replace|discard|restart|kill|stop|cancel|close|terminate)\b[^.!?;:\n]{0,80}\b(?:computadora|teclado|documento|archivo|servidor|puerta|borrador|cache|contenedor|instancia|proceso|pod|servicio|job|worker|tarea|conexion|computer|keyboard|document|file|server|door|draft|cache|container|instance|process|service|task|connection)\b/u;

/**
 * Determina si el comentario completo expresa una crítica fuerte contra un
 * objetivo no humano —por ejemplo una nota, metodología, decisión o política—
 * sin contener un ataque personal.
 *
 * @param {string} text
 * @returns {boolean}
 */
function isEntirelyNonPersonalCriticism(text) {
    const canonical =
        canonicalizeForStructure(text);

    if (!canonical) {
        return false;
    }

    /*
     * Algunas aclaraciones seguras nombran literalmente "persona" para negar que
     * el uso figurado tenga un objetivo humano:
     *
     *     "este bug está matando el servidor, sin relación con ninguna persona"
     *
     * Esa palabra no debe convertirse por sí sola en un objetivo personal. Para
     * la comprobación de destinatarios se elimina únicamente esa aclaración
     * explícita, conservando intacto cualquier otro rol humano del comentario.
     */
    const targetInspectionText =
        canonical.replace(
            /\b(?:sin\s+relacion\s+con\s+(?:ninguna\s+)?persona|no\s+tiene\s+relacion\s+con\s+(?:una|ninguna)\s+persona|no\s+se\s+refiere\s+a\s+(?:una|ninguna)\s+persona|no\s+hay\s+(?:un\s+)?objetivo\s+humano|el\s+objetivo\s+no\s+es\s+humano|no\s+existe\s+una\s+descalificacion\s+personal|not\s+(?:about|directed\s+at)\s+a\s+person|no\s+human\s+target|no\s+one\s+is\s+being\s+insulted|this\s+is\s+not\s+a\s+personal\s+attack|not\s+a\s+personal\s+attack|not\s+a\s+person|the\s+criticism\s+is\s+about\s+the\s+content|the\s+disagreement\s+concerns\s+the\s+work\s+itself)\b/gu,
            ' '
        );

    /*
     * Usos inequívocamente no personales se reconocen antes de inspeccionar
     * aclaraciones como "no se refiere a una persona". Esas aclaraciones pueden
     * contener literalmente la palabra persona/author sin constituir un objetivo.
     * El llamador ya exige direct_attack_signal < 0.35 antes de aplicar este cap.
     */
    if (
        NON_PERSONAL_PROFANITY_PATTERN.test(canonical)
        || NON_PERSONAL_EMOTION_PATTERN.test(canonical)
        || IMPERSONAL_NON_DIRECTED_EVALUATION_PATTERN.test(
            canonical
        )
        || FIGURATIVE_NON_PERSONAL_PATTERN.test(
            canonical
        )
        || LITERAL_NON_PERSONAL_PATTERN.test(
            canonical
        )
        || LITERAL_BODILY_USAGE_PATTERN.test(
            canonical
        )
        || NON_HUMAN_ACTION_PATTERN.test(
            canonical
        )
        || INSTITUTIONAL_NON_ABUSIVE_ACTION_PATTERN.test(
            canonical
        )
        || OBJECT_ACTION_NON_PERSONAL_PATTERN.test(
            canonical
        )
    ) {
        return true;
    }

    if (
        SECOND_PERSON_PATTERN.test(targetInspectionText)
        || HUMAN_ROLE_PATTERN.test(targetInspectionText)
        || HUMAN_GROUP_PATTERN.test(targetInspectionText)
        || IMPLICIT_HUMAN_AGENT_ATTACK_PATTERN.test(
            targetInspectionText
        )
        || IMPERSONAL_RHETORICAL_ATTACK_PATTERN.test(
            targetInspectionText
        )
        || DISMISSIVE_IMPERATIVE_ATTACK_PATTERN.test(
            targetInspectionText
        )
    ) {
        return false;
    }


    const wholeTextTargetsNonPersonalObject =
        new RegExp(
            String.raw`^(?:(?:esta|este|esa|ese|aquella|aquel|la|el|this|that|the|these|those)\s+${NON_PERSONAL_TARGET_SOURCE}\b|(?:esto|eso|aquello|this|that)\b)`,
            'u'
        ).test(canonical);

    if (!wholeTextTargetsNonPersonalObject) {
        return false;
    }

    /*
     * Si existe una evaluación negativa conocida, la señal es especialmente
     * clara. Si no existe, se permite igualmente la protección cuando el texto
     * completo está anclado en un objetivo no personal; el modelo conserva su
     * score original en los metadatos para auditoría.
     */
    return (
        NEGATIVE_EVALUATION_PATTERN.test(canonical)
        || NON_PERSONAL_TARGET_PATTERN.test(canonical)
    );
}

/* ============================================================================
 * 6. FUSIÓN DE EVIDENCIAS
 * ============================================================================ */

/**
 * Fusiona la señal neuronal, la evidencia estructural, la normalización, el
 * contexto y la detección de crítica no personal.
 *
 * La fusión evita que una única fuente sea autoridad absoluta, conserva el score
 * neuronal original y marca conflictos o cercanía al umbral como incertidumbre.
 *
 * v4.1 refuerza especialmente ataques personales escondidos en comentarios
 * largos y reduce falsos positivos de afecto rioplatense, vulgaridad dirigida a
 * objetos/situaciones y usos corporales literales.
 *
 * @param {object} evidence
 * @returns {object}
 */
function fuseEvidence({
    modelScore,
    structural,
    normalization,
    context,
    nonPersonalCriticism
}) {
    let fusedScore =
        clamp01(modelScore);

    const originalModelScore =
        fusedScore;

    const structuralContribution =
        0.82
        * structural.direct_attack_signal;

    if (structuralContribution > 0) {
        /*
         * Unión probabilística de evidencias independientes aproximadas:
         *
         * 1 - (1 - modelo) * (1 - estructura)
         *
         * De esta manera una estructura inequívoca puede rescatar un falso
         * negativo neuronal sin reemplazar al modelo por una regla binaria.
         */
        fusedScore =
            1
            - (
                (1 - fusedScore)
                * (1 - structuralContribution)
            );
    }

    /*
     * La evasión aumenta el riesgo únicamente cuando ya existe una estructura
     * personal. Obfuscación por sí sola nunca convierte un texto en tóxico.
     */
    if (
        normalization.obfuscation_signal > 0
        && structural.direct_attack_signal >= 0.45
    ) {
        const evasionContribution =
            0.18
            * normalization.obfuscation_signal;

        fusedScore =
            1
            - (
                (1 - fusedScore)
                * (1 - evasionContribution)
            );
    }

    const structuralRaisedScore =
        fusedScore > originalModelScore + 0.000001;

    let affectionateContextAdjusted = false;

    if (
        structural.affectionate_context
        && structural.direct_attack_signal < 0.35
        && fusedScore > AFFECTIONATE_CONTEXT_SCORE_CAP
    ) {
        fusedScore =
            AFFECTIONATE_CONTEXT_SCORE_CAP;

        affectionateContextAdjusted = true;
    }

    let nonPersonalCriticismAdjusted = false;

    if (
        nonPersonalCriticism
        && structural.direct_attack_signal < 0.35
        && fusedScore
            > NON_PERSONAL_CRITICISM_SCORE_CAP
    ) {
        fusedScore =
            NON_PERSONAL_CRITICISM_SCORE_CAP;

        nonPersonalCriticismAdjusted = true;
    }

    /*
     * El modelo ya se ejecutó sobre la representación donde las regiones
     * contextualmente protegidas fueron neutralizadas. v3.1 aplicaba además un
     * cap global cuando direct_attack_signal era 0. Eso confundía "la estructura
     * no reconoció el ataque" con "no existe ataque" y podía borrar una agresión
     * real coexistente con una cita. En v4.1 no se vuelve a reducir globalmente el
     * score por contexto: la protección actúa en el span/cláusula antes del modelo.
     */
    const contextAdjustedScore = false;

    fusedScore =
        roundScore(fusedScore);

    const modelStructuralConflict =
        (
            modelScore <= 0.20
            && structural.direct_attack_signal >= 0.75
        )
        || (
            modelScore >= 0.80
            && fusedScore < TOXICITY_THRESHOLD
            && (
                context.adjusted
                || nonPersonalCriticism
            )
        );

    const nearBoundary =
        fusedScore >= UNCERTAIN_LOW
        && fusedScore <= UNCERTAIN_HIGH;

    let certainty = 'uncertain';

    if (
        fusedScore >= CLEAR_TOXIC_MIN
        && (
            !modelStructuralConflict
            || structural.direct_attack_signal >= 0.90
        )
    ) {
        certainty = 'clear-toxic';
    } else if (
        fusedScore <= CLEAR_NOT_TOXIC_MAX
        && !modelStructuralConflict
    ) {
        certainty = 'clear-not-toxic';
    }

    const uncertain =
        certainty === 'uncertain'
        || nearBoundary;

    return {
        score:
            fusedScore,
        structural_raised_score:
            structuralRaisedScore,
        context_adjusted_score:
            contextAdjustedScore,
        non_personal_criticism_adjusted:
            nonPersonalCriticismAdjusted,
        affectionate_context_adjusted:
            affectionateContextAdjusted,
        model_structural_conflict:
            modelStructuralConflict,
        certainty,
        uncertain,
        review_recommended:
            uncertain
    };
}

/* ============================================================================
 * 7. CLASIFICACIÓN FINAL
 * ============================================================================ */

/**
 * Clasifica la toxicidad mediante la arquitectura híbrida contextual v4.1 de TRAMA.
 *
 * Procedimiento:
 *
 * 1. conservar el comentario original y crear representaciones normalizadas;
 * 2. identificar y neutralizar solamente contexto protegido;
 * 3. ejecutar DistilBERT sobre comentario, segmentos y ventanas;
 * 4. detectar estructuras generales de ataque personal;
 * 5. detectar objetivos inequívocamente no personales;
 * 6. fusionar todas las evidencias;
 * 7. devolver TOXIC / NOT-TOXIC, scores, auditoría e incertidumbre.
 *
 * La función no decide approved/pending/rejected. Esa política corresponde a la
 * capa de moderación de Laravel.
 *
 * @param {string} text
 * @param {object} [options]
 * @param {boolean} [options.include_raw_model_audit=true]
 *   Cuando es false se omite la inferencia neuronal adicional que solamente
 *   alimenta raw_model_toxicity_score. La clasificación final no depende de
 *   esa inferencia, por lo que es seguro desactivarla en benchmarks masivos.
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

    if (!originalText) {
        return {
            classification: 'not-toxic',
            toxicity_score: 0,
            scores: {
                'not-toxic': 1,
                toxic: 0
            },
            score_type: 'hybrid',

            /*
             * Contrato operativo para Laravel.
             *
             * Estas señales se exponen también en el nivel superior para que la
             * política approved / pending / rejected no dependa de leer `analysis`.
             */
            certainty: 'clear-not-toxic',
            uncertain: false,
            review_recommended: false,

            analysis: {
                architecture_version:
                    'contextual-target-scope-fusion-v4.1.1',
                model_toxicity_score: 0,
                raw_model_toxicity_score: 0,
                raw_model_audit_skipped: false,
                model_full_comment_score: 0,
                model_raw_segment_max_score: 0,
                model_aggregation_mode: 'empty',
                strongest_unit_kind: null,
                strongest_unit_index: null,
                strongest_unit_representation: null,
                analysis_unit_count: 0,
                model_variant_count: 0,
                model_batch_count: 0,
                model_cache_hits: 0,
                model_unique_inference_count: 0,
                context_adjusted: false,
                direct_attack_reinforced: false,
                affectionate_context: false,
                affectionate_context_adjusted: false,
                non_personal_criticism_adjusted: false,
                obfuscation_signal: 0,
                obfuscation_types: [],
                direct_attack_signal: 0,
                attack_types: [],
                quoted_context: false,
                reported_context: false,
                metalinguistic_context: false,
                negation_context: false,
                personal_target: false,
                non_personal_target: false,
                non_human_target: false,
                fiction_context: false,
                uncertain: false,
                certainty: 'clear-not-toxic',
                review_recommended: false
            }
        };
    }

    /* ------------------------------------------------------------------------
     * Paso 1: normalización robusta.
     * --------------------------------------------------------------------- */
    const normalization =
        buildNormalizationBundle(
            originalText
        );

    /* ------------------------------------------------------------------------
     * Paso 2: contexto.
     * --------------------------------------------------------------------- */
    const preparedContext =
        prepareContextForClassification(
            normalization
        );

    /*
     * Si todo el contenido quedó neutralizado por contexto, mantenemos una unidad
     * neutra para que la salida siga siendo consistente y auditable.
     */
    if (
        !preparedContext.normalized_text
        && !preparedContext.deobfuscated_text
    ) {
        preparedContext.normalized_text =
            'contexto protegido';
        preparedContext.deobfuscated_text =
            'contexto protegido';
    }

    /* ------------------------------------------------------------------------
     * Paso 3: modelo neuronal sobre comentario, segmentos y ventanas.
     * --------------------------------------------------------------------- */
    const analysisUnits =
        buildAnalysisUnits(
            preparedContext
        );

    const modelInference =
        await inferAnalysisUnitsBatched(
            analysisUnits
        );

    const strongestModelResult =
        modelInference.strongest_model_result;

    const strongestUnit =
        modelInference.strongest_unit;

    const rawStrongestModelResult =
        modelInference.raw_strongest_model_result;

    const fullModelResult =
        modelInference.full_model_result;

    const modelToxicityScore =
        strongestModelResult?.toxicity_score
        ?? 0;

    let modelBatchCount =
        modelInference.batch_count;

    let modelCacheHits =
        modelInference.cache_hits;

    let modelVariantCount =
        modelInference.variant_count;

    let modelUniqueInferenceCount =
        modelInference.unique_inference_count;

    /*
     * Si se neutralizó contexto, guardamos además la respuesta neuronal del texto
     * normalizado sin neutralización. Es solamente auditoría: la decisión utiliza
     * el score contextual para no castigar una cita o un reporte legítimo.
     */
    let rawModelToxicityScore =
        modelToxicityScore;

    if (
        preparedContext.adjusted
        && includeRawModelAudit
    ) {
        const rawInference =
            await inferTextWithCaseVariants(
                normalization.normalizedText
            );

        rawModelToxicityScore =
            rawInference.result?.toxicity_score
            ?? modelToxicityScore;

        modelBatchCount +=
            rawInference.batch_count;

        modelCacheHits +=
            rawInference.cache_hits;

        modelVariantCount +=
            rawInference.variant_count;

        modelUniqueInferenceCount +=
            rawInference.variant_count
            - rawInference.cache_hits;
    }

    /* ------------------------------------------------------------------------
     * Paso 4: estructura de ataque sobre contenido NO protegido.
     * --------------------------------------------------------------------- */
    const structuralText =
        uniqueStrings([
            preparedContext.deobfuscated_text,
            preparedContext.normalized_text
        ]).join('. ');

    const structural =
        analyzeStructuralEvidence(
            structuralText
        );

    /* ------------------------------------------------------------------------
     * Paso 5: objetivo no personal.
     * --------------------------------------------------------------------- */
    const nonPersonalCriticism =
        structural.direct_attack_signal < 0.35
        && isEntirelyNonPersonalCriticism(
            structuralText
        );

    /* ------------------------------------------------------------------------
     * Paso 6: fusión de evidencias.
     * --------------------------------------------------------------------- */
    const fusion =
        fuseEvidence({
            modelScore:
                modelToxicityScore,
            structural,
            normalization,
            context:
                preparedContext,
            nonPersonalCriticism
        });

    const finalToxicityScore =
        roundScore(
            fusion.score
        );

    const finalNotToxicScore =
        roundScore(
            1 - finalToxicityScore
        );

    const classification =
        finalToxicityScore
            >= TOXICITY_THRESHOLD
                ? 'toxic'
                : 'not-toxic';

    const hybridIntervention =
        normalization.changed
        || preparedContext.adjusted
        || fusion.structural_raised_score
        || fusion.context_adjusted_score
        || fusion.non_personal_criticism_adjusted
        || fusion.affectionate_context_adjusted;

    return {
        classification,
        toxicity_score:
            finalToxicityScore,
        scores: {
            'not-toxic':
                finalNotToxicScore,
            toxic:
                finalToxicityScore
        },
        score_type:
            hybridIntervention
                ? 'hybrid'
                : 'model',

        /*
         * Contrato operativo para Laravel.
         *
         * `classification` conserva la decisión binaria del detector. Estas señales
         * permiten aplicar la política de moderación sin reinterpretar
         * `toxicity_score` como una probabilidad pura del modelo.
         */
        certainty:
            fusion.certainty,
        uncertain:
            fusion.uncertain,
        review_recommended:
            fusion.review_recommended,

        analysis: {
            architecture_version:
                'contextual-target-scope-fusion-v4.1.1',
            model:
                MODEL_NAME,

            /* Señal neuronal principal y señal cruda previa al contexto. */
            model_toxicity_score:
                roundScore(modelToxicityScore),
            raw_model_toxicity_score:
                roundScore(rawModelToxicityScore),
            raw_model_audit_skipped:
                preparedContext.adjusted
                && !includeRawModelAudit,
            model_full_comment_score:
                roundScore(
                    fullModelResult?.toxicity_score
                    ?? modelToxicityScore
                ),
            model_raw_segment_max_score:
                roundScore(
                    rawStrongestModelResult?.toxicity_score
                    ?? modelToxicityScore
                ),
            model_aggregation_mode:
                modelInference.aggregation_mode,

            /* Unidad contextual finalmente utilizada. */
            strongest_unit_kind:
                strongestUnit?.kind
                ?? null,
            strongest_unit_index:
                strongestUnit?.index
                ?? null,
            strongest_unit_representation:
                strongestUnit?.representation
                ?? null,
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

            /* Normalización / evasión. */
            normalization_changed:
                normalization.changed,
            obfuscation_signal:
                normalization.obfuscation_signal,
            obfuscation_types:
                normalization.obfuscation_types,

            /* Contexto lingüístico. */
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
            negation_context:
                preparedContext.negation_context,
            fiction_context:
                preparedContext.fiction_context,

            /* Estructura del ataque y del objetivo. */
            direct_attack_signal:
                structural.direct_attack_signal,
            direct_attack_reinforced:
                fusion.structural_raised_score,
            affectionate_context:
                structural.affectionate_context,
            affectionate_context_adjusted:
                fusion.affectionate_context_adjusted,
            attack_types:
                structural.attack_types,
            personal_target:
                structural.personal_target,
            non_personal_target:
                structural.non_personal_target,
            non_human_target:
                structural.non_human_target,
            derogatory_lexeme:
                structural.derogatory_lexeme,
            directed_profanity:
                structural.directed_profanity,

            /* Corrección de crítica no personal. */
            non_personal_criticism:
                nonPersonalCriticism,
            non_personal_criticism_adjusted:
                fusion.non_personal_criticism_adjusted,

            /* Auditoría de la fusión y manejo de incertidumbre. */
            context_score_adjusted:
                fusion.context_adjusted_score,
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
 * EXPORTS
 * ============================================================================ */

/*
 * El módulo expone `classify()` para que pueda reutilizarse directamente desde
 * otras partes de TRAMA, incluidas las baterías de pruebas y los procesos de
 * moderación que necesiten ejecutar la clasificación sin invocar el script como
 * comando independiente.
 */
export {
    analyzeStructuralEvidence,
    buildNormalizationBundle,
    classify,
    normalizeTextForAnalysis
};

/* ============================================================================
 * ENTRADA DEL SCRIPT
 * ============================================================================ */

/**
 * Ejecuta la interface de línea de comandos cuando el archivo se invoca
 * directamente con Node.
 *
 * Cuando el módulo se importa desde una batería de pruebas, esta función no se
 * ejecuta y `classify()` puede reutilizarse directamente sin crear copias
 * temporales del archivo.
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
         * stdout contiene solamente JSON para mantener compatibilidad con
         * Laravel.
         */
        console.log(
            JSON.stringify(result)
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
        path.resolve(process.argv[1])
    ).href === import.meta.url;

if (isDirectExecution) {
    await runScript();
}
