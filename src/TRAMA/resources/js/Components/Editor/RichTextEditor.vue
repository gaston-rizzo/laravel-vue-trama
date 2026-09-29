<script setup>
/* ============================================================================
 * COMPONENT: RichTextEditor.vue
 * ============================================================================
 *
 * Editor enriquecido y acotado para el cuerpo de noticias.
 *
 * Permite aplicar formatos editoriales básicos, encabezados y listas.
 * El pegado se limpia como texto estructurado y el backend vuelve a sanitizar
 * todo el HTML antes de guardarlo.
 * ============================================================================ */

import { computed, nextTick, onMounted, ref, watch } from 'vue';
import {
    Bold,
    Heading2,
    Heading3,
    Italic,
    List,
    ListOrdered,
    Redo2,
    Undo2,
} from '@lucide/vue';

// Props del editor:
//
// modelValue contiene el HTML actual.
// minCharacters y maxCharacters definen los límites del cuerpo.
// disabled bloquea la edición cuando la noticia es solamente de lectura.
//
// articleId queda preparado para una implementación futura de imágenes internas.
// Permitirá asociar inmediatamente una imagen con una noticia ya existente.
const props = defineProps({
    modelValue: {
        type: String,
        default: '',
    },
    articleId: {
        type: [Number, String],
        default: null,
    },
    // Cantidad mínima de caracteres visibles requerida para el cuerpo.
    minCharacters: {
        type: Number,
        default: 300,
    },
    // Cantidad máxima de caracteres visibles permitida para el cuerpo.
    maxCharacters: {
        type: Number,
        default: 10000,
    },
    disabled: {
        type: Boolean,
        default: false,
    },
});

// Evento emitido para sincronizar el HTML editado con el formulario padre.
const emit = defineEmits(['update:modelValue']);

// Nodo editable donde el usuario escribe el cuerpo de la noticia.
const editor = ref(null);

// Historial propio de deshacer/rehacer: guardamos snapshots del HTML.
const historyStack = ref(['']);
const historyIndex = ref(0);
let historyTimer = null;

// Habilitados solo si hay a dónde ir.
const canUndo = computed(() => historyIndex.value > 0);
const canRedo = computed(() => historyIndex.value < historyStack.value.length - 1);

// Texto visible del HTML, sin etiquetas, para contar palabras y caracteres.
const visibleText = computed(() => {
    if (typeof document === 'undefined') {
        return String(props.modelValue || '').replace(/<[^>]*>/g, ' ');
    }

    const container = document.createElement('div');
    container.innerHTML = props.modelValue || '';

    return (container.textContent || '').replace(/\s+/g, ' ').trim();
});

// Cantidad de caracteres reales que verá el lector.
const characterCount = computed(() => visibleText.value.length);

// Marca advertencia cuando el texto está muy corto o cerca del máximo permitido.
const counterWarning = computed(() => (
    characterCount.value > props.maxCharacters * 0.9
    || (props.minCharacters > 0 && characterCount.value < props.minCharacters)
));

// Construye el contador del cuerpo con el mismo formato
// que título, bajada y resumen.
//
// Vacío cuando existe un mínimo activo:
//      0/10000 · faltan 300 caracteres
//
// Incompleto:
//      125/10000 · faltan 175 caracteres
//
// Válido:
//      540/10000
const bodyCounterText = computed(() => {
    const length = characterCount.value;

    // Durante un borrador el formulario padre envía minCharacters=0: en ese
    // caso no se muestra ninguna exigencia hasta intentar una acción formal.
    if (props.minCharacters <= 0) {
        return `${length}/${props.maxCharacters}`;
    }

    // Mientras no alcance el mínimo, incluso desde cero, informa cuánto falta.
    if (length < props.minCharacters) {
        const remaining = props.minCharacters - length;

        const verb = remaining === 1 ? 'falta' : 'faltan';
        const unit = remaining === 1 ? 'carácter' : 'caracteres';

        return `${length}/${props.maxCharacters} · ${verb} ${remaining} ${unit}`;
    }

    // Cuando alcanza el mínimo, deja solamente el contador.
    return `${length}/${props.maxCharacters}`;
});

// Guarda el estado actual como un paso del historial (si cambió algo).
function commitHistory() {
    if (!editor.value) return;

    const current = editor.value.innerHTML;

    if (current === historyStack.value[historyIndex.value]) return;

    historyStack.value = historyStack.value.slice(0, historyIndex.value + 1);
    historyStack.value.push(current);

    if (historyStack.value.length > 100) {
        historyStack.value.shift();
    }

    historyIndex.value = historyStack.value.length - 1;
}

// Agrupa tecleo continuo en un solo paso de historial (como un editor real).
function scheduleHistoryCommit() {
    clearTimeout(historyTimer);
    historyTimer = window.setTimeout(commitHistory, 500);
}

// Reinicia el historial cuando el contenido cambia por fuera (props.modelValue).
function resetHistory(html) {
    clearTimeout(historyTimer);
    historyStack.value = [html || ''];
    historyIndex.value = 0;
}

// Emite el HTML actual para que el formulario padre actualice su v-model.
function emitContent() {
    emit('update:modelValue', editor.value?.innerHTML || '');
}

/**
 * Obtiene el bloque principal del editor donde está ubicado actualmente el cursor.
 *
 * El editor usa contenteditable, por lo que el cursor puede quedar dentro de:
 *
 *     <p>Texto...</p>
 *
 * pero también dentro de elementos anidados:
 *
 *     <p><strong>Texto...</strong></p>
 *
 * En ambos casos necesitamos obtener el bloque raíz:
 *
 *     <p>...</p>
 *
 * porque el control de Enter compara bloques completos para saber si
 * el usuario ya dejó una línea vacía.
 *
 * No normalizamos todo el HTML mientras el usuario escribe porque modificar
 * el DOM durante @input puede mover el cursor o eliminar el bloque vacío
 * que contenteditable necesita para continuar escribiendo correctamente.
 *
 * @returns {HTMLElement|null}
 * El bloque raíz donde está el cursor, o null si no puede determinarse.
 */
function currentRootEditorBlock() {
    // Elemento contenteditable completo.
    const root = editor.value;

    // Obtiene la selección actual del navegador.
    // La selección contiene la posición real del cursor.
    const selection = window.getSelection();

    // Si el editor todavía no existe o no hay una selección válida,
    // no podemos determinar dónde está el cursor.
    if (!root || !selection || selection.rangeCount === 0) {
        return null;
    }

    // anchorNode es el nodo exacto donde está anclado el cursor.
    //
    // Por ejemplo, si tenemos:
    //
    //     <p>Hola</p>
    //
    // normalmente anchorNode será el nodo de texto "Hola",
    // no directamente el elemento <p>.
    let node = selection.anchorNode;

    // Si no existe el nodo o el cursor quedó fuera de nuestro editor,
    // no intentamos seguir recorriendo el DOM.
    if (!node || !root.contains(node)) {
        return null;
    }

    // A veces contenteditable deja el cursor directamente sobre el elemento
    // raíz del editor, en lugar de dejarlo dentro de un <p>, <div>, etc.
    //
    // En ese caso selection.anchorOffset indica aproximadamente entre qué
    // hijos del editor está ubicado el cursor.
    if (node === root) {
        // Evita intentar acceder a una posición que no exista.
        const childIndex = Math.min(
            selection.anchorOffset,
            Math.max(root.childNodes.length - 1, 0)
        );

        // Intenta obtener el hijo correspondiente.
        // Si no existe, usa el último nodo disponible.
        node = root.childNodes[childIndex] || root.lastChild;
    }

    // Si el navegador nos devolvió un nodo de texto:
    //
    //     "Hola"
    //
    // subimos primero a su elemento contenedor:
    //
    //     <p>Hola</p>
    if (node?.nodeType === Node.TEXT_NODE) {
        node = node.parentElement;
    }

    // A partir de acá necesitamos trabajar únicamente con elementos HTML.
    if (!(node instanceof HTMLElement)) {
        return null;
    }

    // El cursor podría estar dentro de elementos anidados.
    //
    // Ejemplo:
    //
    //     editor
    //       └── <p>
    //            └── <strong>
    //                 └── "Hola"
    //
    // En ese caso inicialmente podemos estar en <strong>.
    //
    // Subimos por sus padres hasta llegar al elemento que cuelga
    // directamente del editor. En este ejemplo terminamos en <p>.
    while (node.parentElement && node.parentElement !== root) {
        node = node.parentElement;
    }

    // Solo devolvemos el nodo si realmente es un hijo directo del editor.
    // Si por alguna razón la estructura no coincide, devolvemos null.
    return node.parentElement === root ? node : null;
}

/**
 * Obtiene el bloque raíz inmediatamente anterior al bloque actual.
 *
 * Se usa durante el control de Enter para comprobar si el bloque anterior
 * también está vacío.
 *
 * Ejemplo:
 *
 *     <p>Texto</p>
 *     <p><br></p>
 *     <p><br></p>   ← bloque actual
 *
 * Si el bloque anterior también es:
 *
 *     <p><br></p>
 *
 * significa que ya existen dos bloques vacíos consecutivos y cualquier
 * Enter adicional debe bloquearse.
 *
 * Entre dos elementos HTML, el navegador puede dejar nodos de texto que
 * solamente contienen espacios, tabulaciones o saltos de línea.
 *
 * Ejemplo real del DOM:
 *
 *     <p>Texto</p>
 *     "\n    "
 *     <p><br></p>
 *
 * Esos nodos de texto no representan contenido visible, por lo que deben
 * ignorarse al buscar el bloque anterior real.
 *
 * @param {HTMLElement|null} node
 * Bloque raíz actual del editor.
 *
 * @returns {HTMLElement|null}
 * El elemento HTML anterior real, o null si no existe.
 */
function previousRootEditorElement(node) {
    // previousSibling devuelve cualquier tipo de nodo anterior:
    // puede ser un HTMLElement, un nodo de texto, un comentario, etc.
    let previous = node?.previousSibling || null;

    // Ignora nodos de texto que no contienen contenido visible.
    //
    // Por ejemplo:
    //
    //     "\n"
    //     "    "
    //     "\n    "
    //
    // Estos nodos pueden aparecer entre dos <p> por cómo el navegador
    // construye o modifica el DOM de un contenteditable.
    while (
        previous
        && previous.nodeType === Node.TEXT_NODE
        && String(previous.textContent || '')
            // Convierte &nbsp; en espacio normal para que también
            // pueda detectarse correctamente como contenido vacío.
            .replace(/\u00a0/g, ' ')
            .trim() === ''
    ) {
        // Sigue retrocediendo hasta encontrar un nodo con contenido
        // o un elemento HTML real.
        previous = previous.previousSibling;
    }

    // Solo nos interesa un elemento HTML como <p>, <h2>, <ul>, etc.
    //
    // Si no existe uno válido antes del bloque actual, devuelve null.
    return previous instanceof HTMLElement ? previous : null;
}

/**
 * Controla la tecla Enter dentro del cuerpo de la noticia en tiempo real.
 *
 * Objetivo:
 *
 *     permitir texto normal;
 *     permitir un Enter normal;
 *     permitir una única línea vacía entre bloques;
 *     impedir que el usuario acumule varias líneas vacías consecutivas.
 *
 * En términos de bloques HTML:
 *
 *     <p>Texto</p>
 *     <p><br></p>
 *     <p>Más texto</p>
 *
 * está permitido.
 *
 * En cambio:
 *
 *     <p>Texto</p>
 *     <p><br></p>
 *     <p><br></p>
 *     <p><br></p>
 *
 * no debe poder seguir creciendo.
 *
 * La comprobación se hace en @keydown, antes de que el navegador procese
 * la tecla y modifique el DOM. Esto permite usar event.preventDefault()
 * para impedir directamente la creación del siguiente bloque vacío.
 *
 * No normalizamos todo el HTML mientras el usuario escribe porque modificar
 * la estructura completa del contenteditable en cada tecla podría mover
 * el cursor, alterar la selección o interferir con el comportamiento natural
 * del editor.
 *
 * La normalización completa sigue ejecutándose al perder el foco y también
 * en Laravel como protección adicional frente a pegados, HTML inesperado
 * o contenido que no haya pasado por esta interacción del teclado.
 */
function handleEditorKeydown(event) {
    // Esta función solamente debe intervenir sobre un Enter normal.
    //
    // Si el editor está bloqueado, la tecla no es Enter, el usuario está
    // escribiendo mediante un método de composición de texto o está usando
    // alguna combinación con Ctrl, Cmd o Alt, no hacemos nada.
    if (
        props.disabled
        || event.key !== 'Enter'
        || event.isComposing
        || event.ctrlKey
        || event.metaKey
        || event.altKey
    ) {
        return;
    }

    // Obtiene el bloque principal donde está actualmente el cursor.
    //
    // Por ejemplo, aunque el cursor esté realmente dentro de:
    //
    //     <strong>Texto</strong>
    //
    // currentRootEditorBlock() devolverá el bloque raíz:
    //
    //     <p><strong>Texto</strong></p>
    //
    // Esto permite trabajar con la estructura real del editor
    // en lugar de con el nodo exacto donde quedó la selección.
    const currentBlock = currentRootEditorBlock();

    // Si no podemos determinar el bloque actual, no interferimos.
    //
    // Tampoco hacemos nada si el bloque contiene texto o contenido real.
    //
    // Ejemplo:
    //
    //     <p>Hola mundo|</p>
    //
    // En ese caso Enter debe funcionar normalmente y crear el siguiente bloque.
    if (!currentBlock || !isEmptyEditorBlock(currentBlock)) {
        return;
    }

    // Si llegamos hasta acá, significa que el cursor está dentro
    // de un bloque vacío.
    //
    // Ejemplo:
    //
    //     <p>Primer párrafo</p>
    //     <p><br></p>   ← cursor acá
    //
    // Ahora necesitamos saber qué bloque existe inmediatamente antes.
    const previousBlock = previousRootEditorElement(currentBlock);

    // Si el bloque actual está vacío Y el bloque anterior también está vacío,
    // ya tenemos el máximo espacio vertical permitido.
    //
    // Ejemplo:
    //
    //     <p>Primer párrafo</p>
    //     <p><br></p>
    //     <p><br></p>   ← cursor acá
    //
    // Si permitiéramos otro Enter, el navegador generaría otro bloque vacío:
    //
    //     <p><br></p>
    //
    // y el usuario podría seguir agregando espacio vertical indefinidamente.
    if (previousBlock && isEmptyEditorBlock(previousBlock)) {
        // Cancela el comportamiento nativo de Enter ANTES de que Chrome
        // cree un nuevo bloque vacío dentro del contenteditable.
        //
        // Por eso el tercer Enter consecutivo no se agrega y visualmente
        // el editor queda limitado a una única línea vacía entre contenidos.
        event.preventDefault();
    }
}

/* ============================================================================
 * NORMALIZACIÓN DEL HTML GENERADO POR CONTENTEDITABLE
 *
 * Funciones auxiliares para corregir y unificar la estructura HTML
 * generada automáticamente por el navegador.
 * ============================================================================ */

/**
 * Convierte los bloques <div> creados por contenteditable
 * al presionar Enter en párrafos reales.
 *
 * Entrada:
 *
 *     <div>aaaa</div>
 *     <div><br></div>
 *     <div><br></div>
 *     <div>bbbb</div>
 *
 * Resultado:
 *
 *     <p>aaaa</p>
 *     <p><br></p>
 *     <p><br></p>
 *     <p>bbbb</p>
 *
 * Después, normalizeEditorSpacing() conserva como máximo
 * un párrafo vacío entre dos bloques con contenido.
 */
function normalizeEditorParagraphBlocks(root) {
    for (const node of Array.from(root.childNodes)) {
        // Solamente convierte etiquetas <div> ubicadas
        // directamente dentro del editor.
        if (
            !(node instanceof HTMLElement)
            || node.tagName.toLowerCase() !== 'div'
        ) {
            continue;
        }

        // Crea el párrafo que reemplazará al <div>.
        const paragraph = document.createElement('p');

        // Mueve todo el contenido existente al nuevo párrafo.
        while (node.firstChild) {
            paragraph.appendChild(node.firstChild);
        }

        // Reemplaza el <div> original.
        node.replaceWith(paragraph);
    }
}

/**
 * Envuelve en párrafos el texto que contenteditable
 * haya dejado directamente dentro del editor.
 *
 * Entrada:
 *
 *     aaaa
 *     <p>bbbb</p>
 *
 * Resultado:
 *
 *     <p>aaaa</p>
 *     <p>bbbb</p>
 *
 * Esto impide que Laravel termine guardando:
 *
 *     aaaabbbb
 */
function normalizeLooseRootContent(root) {
    const blockTags = new Set([
        'p',
        'h2',
        'h3',
        'blockquote',
        'ul',
        'ol',
        'hr',
    ]);

    let paragraph = null;

    for (const node of Array.from(root.childNodes)) {
        // Los bloques editoriales válidos ya tienen
        // su propia estructura y no deben envolverse.
        const isBlock =
            node instanceof HTMLElement
            && blockTags.has(node.tagName.toLowerCase());

        if (isBlock) {
            paragraph = null;
            continue;
        }

        // Elimina nodos de texto que solo contengan espacios.
        if (
            node.nodeType === Node.TEXT_NODE
            && String(node.textContent || '')
                .replace(/\u00a0/g, ' ')
                .trim() === ''
        ) {
            node.remove();
            continue;
        }

        // Crea un párrafo para agrupar contenido suelto.
        if (!paragraph) {
            paragraph = document.createElement('p');
            root.insertBefore(paragraph, node);
        }

        // Mueve el contenido suelto dentro del párrafo.
        paragraph.appendChild(node);
    }
}

/**
 * Indica si un elemento representa únicamente espacio vacío.
 *
 * Se consideran vacíos:
 *
 *     <br>
 *     <p></p>
 *     <p><br></p>
 *     <p>&nbsp;</p>
 *
 * No se consideran vacíos:
 *
 *     <p>Texto real</p>
 *     <ul><li>Elemento</li></ul>
 *     <hr>
 *     <p><img src="imagen.webp"></p>
 */
function isEmptyEditorBlock(element) {
    const tag = element.tagName.toLowerCase();

    // Un <br> ubicado solo representa espacio vertical.
    if (tag === 'br') {
        return true;
    }

    // Estos elementos siempre representan contenido real.
    if (
        ['img', 'hr', 'ul', 'ol'].includes(tag)
        || element.querySelector('img, hr, ul, ol')
    ) {
        return false;
    }

    // Solamente estos bloques pueden considerarse vacíos.
    if (
        !['p', 'div', 'h2', 'h3', 'blockquote'].includes(tag)
    ) {
        return false;
    }

    // Convierte &nbsp; en un espacio común para comprobar
    // correctamente si existe texto visible.
    const text = String(element.textContent || '')
        .replace(/\u00a0/g, ' ')
        .trim();

    return text.length === 0;
}

/**
 * Deja como máximo una etiqueta <br> consecutiva.
 *
 * Entrada:
 *
 *     Texto<br><br><br>Más texto
 *
 * Resultado:
 *
 *     Texto<br>Más texto
 */
function collapseEditorBreaks(parent) {
    let consecutiveBreaks = 0;

    for (const node of Array.from(parent.childNodes)) {
        if (
            node instanceof HTMLElement
            && node.tagName.toLowerCase() === 'br'
        ) {
            consecutiveBreaks++;

            // Conserva solamente un salto de línea.
            if (consecutiveBreaks > 1) {
                node.remove();
            }

            continue;
        }

        // Los espacios entre etiquetas no interrumpen
        // el conteo de saltos consecutivos.
        if (
            node.nodeType === Node.TEXT_NODE
            && String(node.textContent || '')
                .replace(/\u00a0/g, ' ')
                .trim() === ''
        ) {
            continue;
        }

        // Apareció contenido real.
        consecutiveBreaks = 0;

        // También normaliza los saltos dentro
        // de párrafos, títulos y otros elementos.
        if (node instanceof HTMLElement) {
            collapseEditorBreaks(node);
        }
    }
}

/**
 * Normaliza el contenido antes de actualizar el formulario.
 *
 * Entrada:
 *
 *     <div>aaaa</div>
 *     <div><br></div>
 *     <div><br></div>
 *     <div>bbbb</div>
 *
 * Resultado:
 *
 *     <p>aaaa</p>
 *     <p><br></p>
 *     <p>bbbb</p>
 *
 * Se conserva como máximo un párrafo vacío entre dos bloques con contenido.
 * Los vacíos iniciales, finales o repetidos se eliminan.
 */
function normalizeEditorSpacing() {
    if (props.disabled || !editor.value) {
        return;
    }

    const root = editor.value;

    // Convierte los <div> creados por Enter en párrafos.
    normalizeEditorParagraphBlocks(root);

    // Envuelve en párrafos cualquier texto que haya quedado
    // directamente dentro del editor.
    normalizeLooseRootContent(root);

    // Reduce varios <br> consecutivos a uno solo dentro de cada bloque.
    collapseEditorBreaks(root);

    let hasContentBefore = false;
    let keptEmptyBlock = false;

    for (const node of Array.from(root.childNodes)) {
        // Elimina nodos de texto sueltos que solamente
        // contengan espacios o saltos.
        if (node.nodeType === Node.TEXT_NODE) {
            const text = String(node.textContent || '')
                .replace(/\u00a0/g, ' ')
                .trim();

            if (text === '') {
                node.remove();
            } else {
                hasContentBefore = true;
                keptEmptyBlock = false;
            }

            continue;
        }

        if (!(node instanceof HTMLElement)) {
            continue;
        }

        if (!isEmptyEditorBlock(node)) {
            hasContentBefore = true;
            keptEmptyBlock = false;
            continue;
        }

        // Solo un párrafo vacío puede representar la línea en blanco permitida.
        // Vacíos de títulos/citas o un segundo vacío consecutivo se eliminan.
        const isParagraph = node.tagName.toLowerCase() === 'p';

        if (!hasContentBefore || keptEmptyBlock || !isParagraph) {
            node.remove();
            continue;
        }

        // Canoniza el separador permitido para que quede exactamente <p><br></p>.
        node.innerHTML = '<br>';
        keptEmptyBlock = true;
    }

    // No se conserva un separador vacío al final del cuerpo.
    for (const node of Array.from(root.childNodes).reverse()) {
        if (
            node.nodeType === Node.TEXT_NODE
            && String(node.textContent || '')
                .replace(/\u00a0/g, ' ')
                .trim() === ''
        ) {
            node.remove();
            continue;
        }

        if (
            node instanceof HTMLElement
            && isEmptyEditorBlock(node)
        ) {
            node.remove();
            continue;
        }

        break;
    }

    // Guarda el HTML normalizado en el historial
    // y actualiza el v-model del formulario.
    commitHistory();
    emitContent();
}

/* ============================================================================ */

// Ejecuta comandos nativos del editor y avisa el cambio al formulario padre.
function runCommand(command, value = null) {
    if (props.disabled) return;

    editor.value?.focus();
    document.execCommand(command, false, value);
    commitHistory();
    emitContent();
}

// Aplica un bloque semántico como párrafo o subtítulo.
function applyBlock(tag) {
    runCommand('formatBlock', tag);
}

// Pega texto limpio, respetando párrafos y saltos, sin arrastrar HTML externo.
function handlePaste(event) {
    if (props.disabled) return;

    event.preventDefault();

    const text = event.clipboardData?.getData('text/plain') || '';

    const escaped = text
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;');

    const paragraphs = escaped
        .split(/\n{2,}/)
        .map((paragraph) => `<p>${paragraph.replace(/\n/g, '<br>')}</p>`)
        .join('');

    document.execCommand('insertHTML', false, paragraphs || escaped);
    commitHistory();
    emitContent();
}

// Deshace un paso propio del historial (no usa el undo nativo del navegador).
function runUndo() {
    if (props.disabled || !canUndo.value) return;

    clearTimeout(historyTimer);
    commitHistory();

    if (historyIndex.value <= 0) return;

    historyIndex.value -= 1;
    editor.value.innerHTML = historyStack.value[historyIndex.value];
    emitContent();
}

// Rehace el paso siguiente del historial, si existe.
function runRedo() {
    if (props.disabled || !canRedo.value) return;

    if (historyIndex.value >= historyStack.value.length - 1) return;

    historyIndex.value += 1;
    editor.value.innerHTML = historyStack.value[historyIndex.value];
    emitContent();
}

// Mantiene sincronizado el editor cuando el valor externo cambia desde Inertia.
watch(() => props.modelValue, async (value) => {
    await nextTick();

    if (!editor.value || document.activeElement === editor.value) {
        return;
    }

    if (editor.value.innerHTML !== (value || '')) {
        editor.value.innerHTML = value || '';
        resetHistory(editor.value.innerHTML);
    }
});

// Al montar, copia el HTML recibido desde Laravel dentro del editor editable.
onMounted(() => {
    if (editor.value && editor.value.innerHTML !== props.modelValue) {
        editor.value.innerHTML = props.modelValue || '';
    }

    resetHistory(editor.value?.innerHTML || '');
});
</script>

<template>
    <!-- Envuelve por separado la caja del editor y su contador. -->
    <div class="rich-editor-wrapper">
        <section class="rich-editor-shell">

            <div
                class="rich-editor-toolbar"
                role="toolbar"
                aria-label="Formato del cuerpo"
            >
                <button
                    type="button"
                    title="Párrafo"
                    :disabled="disabled"
                    @mousedown.prevent="applyBlock('p')"
                >
                    P
                </button>

                <button
                    type="button"
                    title="Título 2"
                    :disabled="disabled"
                    @mousedown.prevent="applyBlock('h2')"
                >
                    <Heading2 :size="17" />
                </button>

                <button
                    type="button"
                    title="Título 3"
                    :disabled="disabled"
                    @mousedown.prevent="applyBlock('h3')"
                >
                    <Heading3 :size="17" />
                </button>

                <span class="toolbar-separator" />

                <button
                    type="button"
                    title="Negrita"
                    :disabled="disabled"
                    @mousedown.prevent="runCommand('bold')"
                >
                    <Bold :size="17" />
                </button>

                <button
                    type="button"
                    title="Cursiva"
                    :disabled="disabled"
                    @mousedown.prevent="runCommand('italic')"
                >
                    <Italic :size="17" />
                </button>

                <span class="toolbar-separator" />

                <button
                    type="button"
                    title="Lista"
                    :disabled="disabled"
                    @mousedown.prevent="runCommand('insertUnorderedList')"
                >
                    <List :size="17" />
                </button>

                <button
                    type="button"
                    title="Lista numerada"
                    :disabled="disabled"
                    @mousedown.prevent="runCommand('insertOrderedList')"
                >
                    <ListOrdered :size="17" />
                </button>

                <span class="toolbar-separator" />

                <button
                    type="button"
                    title="Deshacer"
                    :disabled="disabled || !canUndo"
                    @mousedown.prevent="runUndo"
                >
                    <Undo2 :size="17" />
                </button>

                <button
                    type="button"
                    title="Rehacer"
                    :disabled="disabled || !canRedo"
                    @mousedown.prevent="runRedo"
                >
                    <Redo2 :size="17" />
                </button>
            </div>

            <div
                ref="editor"
                class="rich-editor-content"
                :contenteditable="disabled ? 'false' : 'true'"
                role="textbox"
                aria-multiline="true"
                data-placeholder="Escribí el cuerpo completo de la noticia..."
                @keydown="handleEditorKeydown"
                @input="disabled || (emitContent(), scheduleHistoryCommit())"
                @blur="disabled || normalizeEditorSpacing()"
                @paste="handlePaste"
            />

        </section>

        <div class="field-meta">
            <strong
                class="character-counter"
                :class="{ warning: counterWarning }"
            >
                {{ bodyCounterText }}
            </strong>
        </div>
    </div>
</template>