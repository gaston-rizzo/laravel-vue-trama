<?php

/* ============================================================================
 * SERVICE: HtmlSanitizer.php
 * ============================================================================
 *
 * Limpia el HTML escrito en el editor de noticias de TRAMA.
 * 
 * Actualmente el editor permite texto, títulos, enlaces, listas,
 * citas y separadores.
 *
 * Antes de guardar el cuerpo de una noticia, esta clase elimina código o
 * atributos que podrían ejecutar JavaScript en el navegador. También deja pasar
 * solamente las etiquetas que el portal necesita actualmente para mostrar
 * texto, enlaces, listas y citas dentro de un artículo.
 *
 * Si recibe texto plano, lo convierte a párrafos HTML para que todas las
 * noticias queden guardadas con el mismo formato.
 * 
 * IMPORTANTE: Se conserva el soporte seguro para imágenes internas
 * como parte de una futura implementación. Aunque RichTextEditor
 * todavía no permite cargarlas, el sanitizador ya restringe sus
 * rutas al directorio administrado por TRAMA.
 * ============================================================================ */

namespace App\Services;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

class HtmlSanitizer
{
    /**
     * Etiquetas admitidas dentro del cuerpo de una noticia.
     *
     * La etiqueta img se mantiene porque el backend ya tiene preparado
     * el soporte para imágenes internas. El RichTextEditor todavía no
     * expone esa funcionalidad en su barra de herramientas.
     *
     * @var list<string>
     */
    private const ALLOWED_TAGS = [
        'p', 'h2', 'h3', 'strong', 'b', 'em', 'i', 'u', 'a',
        'ul', 'ol', 'li', 'blockquote', 'hr', 'br', 'img',
    ];

    /**
     * Elementos cuyo contenido completo se descarta por seguridad.
     *
     * @var list<string>
     */
    private const BLOCKED_TAGS = [
        'script', 'style', 'iframe', 'object', 'embed', 'svg', 'math',
        'form', 'input', 'button', 'textarea', 'select', 'option',
    ];

    /**
     * Limpia el contenido enriquecido y devuelve HTML seguro.
     */
    public function sanitize(?string $html): string
    {
        // Normaliza valores nulos y elimina espacios externos antes de procesar.
        $html = trim((string) $html);

        // Si no hay contenido, se guarda una cadena vacía.
        if ($html === '') {
            return '';
        }

        // Si no detecta etiquetas HTML, se trata como texto plano.
        //
        // Por ejemplo, este contenido:
        //
        //     Primera línea
        //     Segunda línea
        //
        // se convierte en:
        //
        //     <p>Primera línea<br>Segunda línea</p>
        //
        // Además, plainTextToHtml() escapa caracteres especiales para impedir
        // que texto escrito por el usuario se interprete como código HTML.
        if (! preg_match('/<[a-z][^>]*>/i', $html)) {
            return $this->plainTextToHtml($html);
        }

        // Crea un documento para analizar el HTML como una estructura
        // de etiquetas, atributos y contenido.
        $document = new DOMDocument('1.0', 'UTF-8');
        // Evita que los errores de HTML mal formado se muestren en pantalla.
        // Guarda la configuración anterior para restaurarla después.
        $previous = libxml_use_internal_errors(true);

        // Coloca temporalmente todo el cuerpo dentro de un único contenedor.
        //
        // Esto permite agrupar los párrafos, títulos, listas y demás elementos
        // recibidos bajo una misma raíz, para recorrerlos y limpiarlos sin depender
        // de la estructura adicional que DOMDocument pueda generar.
        //
        // El contenedor trama-root se elimina del resultado final.
        //
        // Ejemplo:
        //
        //     <p>Texto de la noticia</p>
        //
        // se procesa internamente como:
        //
        //     <div id="trama-root"><p>Texto de la noticia</p></div>        
        $document->loadHTML(
            '<?xml encoding="UTF-8"><div id="trama-root">'.$html.'</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        // Borra los errores de lectura acumulados por libxml.
        libxml_clear_errors();
        // Restaura la configuración de errores que existía antes.
        libxml_use_internal_errors($previous);

        // Busca dentro del documento el elemento que tenga:
        //
        //     id="trama-root"
        //
        // Por ejemplo, en este HTML:
        //
        //     <div id="trama-root">
        //         <p>Texto de la noticia</p>
        //     </div>
        //
        // query() encuentra ese <div> e item(0) obtiene
        // el primer resultado encontrado.
        $root = (new DOMXPath($document))->query('//*[@id="trama-root"]')->item(0);

        // Si el contenedor principal no pudo encontrarse,
        // no hay contenido seguro para procesar.
        if (! $root instanceof DOMElement) {
            return '';
        }

        // contenteditable puede crear etiquetas <div> al presionar Enter.
        // Primero se convierten esos bloques en párrafos reales para impedir
        // que su contenido termine unido al eliminar las etiquetas <div>.
        $this->normalizeEditorParagraphBlocks($root);

        $this->cleanChildren($root);

        // Reduce saltos <br> repetidos dentro de un mismo párrafo.
        $this->collapseConsecutiveBreaks($root);

        // Conserva como máximo un párrafo vacío entre contenidos.
        $this->normalizeVerticalSpacing($root);

        // Reduce saltos de línea repetidos dentro de los bloques.
        $this->collapseConsecutiveBreaks($root);

        // Elimina espacios vacíos al principio y al final,
        // y deja como máximo una línea vacía entre bloques.
        $this->normalizeVerticalSpacing($root);

        $clean = '';
        foreach ($root->childNodes as $child) {
            // Se recompone el HTML final con los nodos que sobrevivieron a la limpieza.
            $clean .= $document->saveHTML($child);
        }

        return trim($clean);
    }

    /**
     * Convierte HTML seguro en texto plano normalizado.
     *
     * Antes de eliminar las etiquetas, reemplaza los cierres de bloques y los
     * saltos de línea por espacios. Así evita que dos párrafos terminen unidos,
     * por ejemplo: "economíaargentina".
     */
    public function toPlainText(?string $html): string
    {
        $html = trim((string) $html);

        if ($html === '') {
            return '';
        }

        $html = preg_replace(
            '/<(?:br\s*\/?|hr\s*\/?|\/(?:p|h[1-6]|li|blockquote|div|ul|ol))\s*>/iu',
            ' ',
            $html
        ) ?? $html;

        $text = html_entity_decode(
            strip_tags($html),
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        );

        return trim(
            preg_replace('/[\s\p{Z}]+/u', ' ', $text) ?? ''
        );
    }

    /**
     * Devuelve la cantidad de caracteres visibles para validar límites.
     */
    public function textLength(?string $html): int
    {
        $text = $this->toPlainText($html);

        return function_exists('mb_strlen')
            ? mb_strlen($text)
            : strlen($text);
    }

    /**
     * Recorre el árbol y elimina nodos o atributos no autorizados.
     */
    private function cleanChildren(DOMNode $parent): void
    {
        foreach (iterator_to_array($parent->childNodes) as $node) {
            // Los nodos de texto no tienen atributos ni etiqueta, por eso se conservan.
            if (! $node instanceof DOMElement) {
                continue;
            }

            // DOMDocument puede normalizar etiquetas en mayúsculas; se comparan en minúscula.
            $tag = strtolower($node->tagName);

            if (! in_array($tag, self::ALLOWED_TAGS, true)) {
                if (in_array($tag, self::BLOCKED_TAGS, true)) {
                    // Scripts, formularios y contenidos embebidos se eliminan
                    // completos: no se conserva ni su estructura ni su texto.
                    $parent->removeChild($node);
                    continue;
                }

                // Antes de desenvolver una etiqueta desconocida se limpian sus
                // descendientes. Así un contenedor no permitido no puede ocultar
                // imágenes, enlaces o atributos inseguros en su interior.
                $this->cleanChildren($node);

                while ($node->firstChild) {
                    $parent->insertBefore($node->firstChild, $node);
                }

                $parent->removeChild($node);
                continue;
            }

            $this->cleanAttributes($node, $tag);

            // Una imagen con URL insegura puede haber sido retirada del árbol.
            if ($node->parentNode !== null) {
                $this->cleanChildren($node);
            }
        }
    }

    /**
     * Conserva únicamente atributos seguros según la etiqueta HTML.
     *
     * Los atributos son la parte más riesgosa del HTML recibido: un enlace puede
     * usar javascript:, una imagen puede apuntar a una URL inválida y cualquier
     * etiqueta puede traer eventos como onclick. Este método deja solo los
     * atributos necesarios para enlaces e imágenes y elimina todo lo demás.
     */
    private function cleanAttributes(DOMElement $element, string $tag): void
    {
        // Cada etiqueta tiene una lista mínima de atributos permitidos.
        $allowed = match ($tag) {
            // En enlaces se permite destino y título, pero la URL se valida aparte.
            'a' => ['href', 'title', 'target', 'rel'],
            // En imágenes se permite origen y textos descriptivos.
            'img' => ['src', 'alt', 'title'],
            // El resto de las etiquetas no necesita atributos para el cuerpo editorial.
            default => [],
        };

        foreach (iterator_to_array($element->attributes) as $attribute) {
            // Se compara en minúscula para cubrir HTML escrito con mayúsculas mezcladas.
            $name = strtolower($attribute->name);

            if (! in_array($name, $allowed, true) || str_starts_with($name, 'on')) {
                // Elimina atributos no autorizados y eventos como onclick, onload u onerror.
                $element->removeAttribute($attribute->name);
            }
        }

        if ($tag === 'a') {
            // El href se valida después de limpiar atributos para bloquear protocolos peligrosos.
            $href = trim($element->getAttribute('href'));
            if (! $this->isSafeUrl($href, allowRelative: true)) {
                // Si el enlace no es seguro, se deja el texto pero se quita la URL.
                $element->removeAttribute('href');
            }

            if ($element->getAttribute('target') === '_blank') {
                // Un enlace en pestaña nueva debe bloquear acceso a window.opener.
                $element->setAttribute('rel', 'noopener noreferrer');
            } else {
                // Si no abre en pestaña nueva, rel y target no son necesarios.
                $element->removeAttribute('target');
                $element->removeAttribute('rel');
            }
        }

        if ($tag === 'img') {
            // Las imágenes del cuerpo solamente pueden utilizar archivos
            // cargados y controlados por TRAMA.
            //
            // Se admite, por ejemplo:
            //
            //     /images/uploads/articles/content/uuid.webp
            //
            // Se rechaza, por ejemplo:
            //
            //     https://sitio-externo.com/imagen.webp
            //
            // Esto impide insertar imágenes externas y evita hotlinking.
            $src = trim($element->getAttribute('src'));

            if (! $this->isSafeInlineImageUrl($src)) {
                $element->parentNode?->removeChild($element);
            }
        }
    }

    /**
     * Comprueba que una imagen del cuerpo pertenezca
     * al directorio interno administrado por TRAMA.
     *
     * Ruta admitida:
     *
     *     /images/uploads/articles/content/uuid.webp
     *
     * Rutas rechazadas:
     *
     *     https://sitio-externo.com/imagen.webp
     *     //sitio-externo.com/imagen.webp
     *     /otra-carpeta/imagen.webp
     *     /images/uploads/articles/content/../../archivo.webp
     */
    private function isSafeInlineImageUrl(string $url): bool
    {
        // Una imagen sin origen no es válida.
        if ($url === '') {
            return false;
        }

        // No admite parámetros ni fragmentos en la URL.
        //
        // Ejemplos rechazados:
        //
        //     imagen.webp?usuario=123
        //     imagen.webp#seccion
        if (
            str_contains($url, '?')
            || str_contains($url, '#')
        ) {
            return false;
        }

        // No permite recorrer directorios mediante ../
        if (str_contains($url, '..')) {
            return false;
        }

        // Construye el patrón que deben cumplir las imágenes internas.
        //
        // MediaController no conserva el nombre original del archivo.
        // Cuando recibe una imagen, genera un nombre nuevo mediante:
        //
        //     Str::uuid()->toString()
        //
        // Por ejemplo:
        //
        //     550e8400-e29b-41d4-a716-446655440000.webp
        //
        // Por eso se valida el formato UUID: no porque el navegador
        // necesite un UUID, sino porque ese es el formato exacto que
        // utiliza el sistema al crear imágenes internas.
        //
        // Esto impide aceptar rutas inventadas como:
        //
        //     /images/uploads/articles/content/foto.webp
        //     /images/uploads/articles/content/logo-empresa.png
        //
        // aunque estén ubicadas dentro del directorio permitido.
        $pattern =
            // Solo acepta archivos dentro del directorio utilizado
            // por MediaController para imágenes del cuerpo.
            '#^/images/uploads/articles/content/'

            // El nombre debe respetar el formato UUID generado por Laravel:
            //
            //     8-4-4-4-12
            //
            // Ejemplo:
            //
            //     550e8400-e29b-41d4-a716-446655440000

            // Primer grupo del UUID.
            . '[0-9a-f]{8}-'

            // Segundo grupo del UUID.
            . '[0-9a-f]{4}-'

            // Tercer grupo del UUID.
            . '[0-9a-f]{4}-'

            // Cuarto grupo del UUID.
            . '[0-9a-f]{4}-'

            // Quinto y último grupo del UUID.
            . '[0-9a-f]{12}'

            // Solo permite formatos admitidos por el endpoint de carga.
            . '\.(?:jpg|jpeg|png|webp)$#i';

        // Devuelve true solamente cuando la ruta completa coincide
        // con el formato de una imagen generada por MediaController.
        return preg_match($pattern, $url) === 1;
    }

    /**
     * Valida URLs utilizadas por enlaces.
     *
     * Admite HTTP, HTTPS, correo, anclas y rutas internas.
     * Las imágenes utilizan una validación más estricta mediante
     * isSafeInlineImageUrl().
     */
    private function isSafeUrl(string $url, bool $allowRelative): bool
    {
        // Un atributo vacío no debe quedar como enlace o imagen válida.
        if ($url === '') {
            return false;
        }

        // Los enlaces a anclas internas del mismo artículo son seguros.
        if ($allowRelative && str_starts_with($url, '#')) {
            return true;
        }

        // Las rutas internas son válidas, pero //dominio representa una URL
        // externa sin protocolo y no debe pasar como una ruta del portal.
        if ($allowRelative && str_starts_with($url, '/') && ! str_starts_with($url, '//')) {
            return true;
        }

        // Para URLs completas se obtiene el protocolo y se compara con la lista segura.
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https', 'mailto'], true);
    }

    /**
     * Convierte los bloques <div> generados por contenteditable
     * en párrafos HTML reales.
     *
     * Chrome puede generar este contenido al presionar Enter:
     *
     *     <div>aaaa</div>
     *     <div><br></div>
     *     <div><br></div>
     *     <div>bbbb</div>
     *
     * Primero se convierte en:
     *
     *     <p>aaaa</p>
     *     <p><br></p>
     *     <p><br></p>
     *     <p>bbbb</p>
     *
     * Después normalizeVerticalSpacing() conserva como máximo un vacío:
     *
     *     <p>aaaa</p>
     *     <p><br></p>
     *     <p>bbbb</p>
     *
     * Esta conversión es necesaria porque, si simplemente se eliminaran
     * las etiquetas <div>, sus textos podrían terminar unidos:
     *
     *     aaaabbbb
     */
    private function normalizeEditorParagraphBlocks(DOMElement $root): void
    {
        $document = $root->ownerDocument;

        if (! $document instanceof DOMDocument) {
            return;
        }

        foreach (iterator_to_array($root->childNodes) as $node) {
            // Solamente convierte etiquetas <div> ubicadas directamente
            // dentro del contenido principal de la noticia.
            if (
                ! $node instanceof DOMElement
                || strtolower($node->tagName) !== 'div'
            ) {
                continue;
            }

            // Crea el párrafo que reemplazará al <div>.
            $paragraph = $document->createElement('p');

            // Mueve todo el contenido del <div> al nuevo párrafo.
            //
            // Ejemplo:
            //
            //     <div><strong>Texto</strong></div>
            //
            // se convierte en:
            //
            //     <p><strong>Texto</strong></p>
            while ($node->firstChild !== null) {
                $paragraph->appendChild($node->firstChild);
            }

            // Sustituye el <div> original por el párrafo.
            $root->replaceChild($paragraph, $node);
        }
    }

    /**
     * Deja como máximo una etiqueta <br> consecutiva
     * dentro de cada bloque del cuerpo.
     *
     * Ejemplo:
     *
     *     <p>Primer texto<br><br><br>Segundo texto</p>
     *
     * se convierte en:
     *
     *     <p>Primer texto<br>Segundo texto</p>
     *
     * Un único <br> representa un salto de línea.
     * Dos o más producirían espacios verticales adicionales.
     */
    private function collapseConsecutiveBreaks(DOMNode $parent): void
    {
        $consecutiveBreaks = 0;

        foreach (iterator_to_array($parent->childNodes) as $node) {
            // Cuenta etiquetas br consecutivas.
            if (
                $node instanceof DOMElement
                && strtolower($node->tagName) === 'br'
            ) {
                $consecutiveBreaks++;

                // Conserva solamente un <br>.
                // Dos o más producirían una línea vacía adicional.
                if ($consecutiveBreaks > 1) {
                    $parent->removeChild($node);
                }

                continue;
            }

            // Los espacios HTML entre etiquetas no interrumpen
            // el conteo de saltos consecutivos.
            if (
                $node->nodeType === XML_TEXT_NODE
                && trim(
                    str_replace(
                        "\u{00A0}",
                        ' ',
                        $node->textContent ?? ''
                    )
                ) === ''
            ) {
                continue;
            }

            // Apareció contenido real, por lo tanto comienza
            // nuevamente el conteo de saltos.
            $consecutiveBreaks = 0;

            // Repite la limpieza dentro de párrafos,
            // títulos, citas y otros bloques permitidos.
            if ($node instanceof DOMElement) {
                $this->collapseConsecutiveBreaks($node);
            }
        }
    }

    /**
     * Deja como máximo un párrafo vacío entre dos bloques con contenido.
     *
     * Ejemplo:
     *
     *     <p>Primer párrafo</p>
     *     <p><br></p>
     *     <p><br></p>
     *     <p>Segundo párrafo</p>
     *
     * se convierte en:
     *
     *     <p>Primer párrafo</p>
     *     <p><br></p>
     *     <p>Segundo párrafo</p>
     *
     * Los bloques vacíos al principio o al final siempre se eliminan.
     */
    private function normalizeVerticalSpacing(DOMElement $root): void
    {
        $hasContentBefore = false;
        $keptEmptyBlock = false;
        $document = $root->ownerDocument;

        foreach (iterator_to_array($root->childNodes) as $node) {
            // Elimina nodos de texto que solamente contienen
            // espacios, saltos de línea o espacios HTML especiales.
            if ($node->nodeType === XML_TEXT_NODE) {
                $text = str_replace(
                    "\u{00A0}",
                    ' ',
                    $node->textContent ?? ''
                );

                if (trim($text) === '') {
                    $root->removeChild($node);
                } else {
                    $hasContentBefore = true;
                    $keptEmptyBlock = false;
                }

                continue;
            }

            if (! $node instanceof DOMElement) {
                continue;
            }

            if (! $this->isEmptySpacingElement($node)) {
                $hasContentBefore = true;
                $keptEmptyBlock = false;
                continue;
            }

            // Solo <p> puede representar la línea vacía permitida.
            // Un segundo vacío consecutivo, un vacío inicial o un título/cita
            // vacíos se eliminan.
            if (
                ! $hasContentBefore
                || $keptEmptyBlock
                || strtolower($node->tagName) !== 'p'
                || ! $document instanceof DOMDocument
            ) {
                $root->removeChild($node);
                continue;
            }

            // Canoniza el separador permitido a <p><br></p>.
            while ($node->firstChild !== null) {
                $node->removeChild($node->firstChild);
            }

            $node->appendChild($document->createElement('br'));
            $keptEmptyBlock = true;
        }

        // El separador permitido solo tiene sentido entre dos contenidos.
        // Si quedó al final, se elimina.
        $node = $root->lastChild;

        while ($node !== null) {
            $previous = $node->previousSibling;

            if ($node->nodeType === XML_TEXT_NODE) {
                $text = str_replace(
                    "\u{00A0}",
                    ' ',
                    $node->textContent ?? ''
                );

                if (trim($text) === '') {
                    $root->removeChild($node);
                    $node = $previous;
                    continue;
                }
            }

            if (
                $node instanceof DOMElement
                && $this->isEmptySpacingElement($node)
            ) {
                $root->removeChild($node);
                $node = $previous;
                continue;
            }

            break;
        }
    }

    /**
     * Indica si un elemento representa solamente espacio vertical.
     *
     * Se consideran vacíos, por ejemplo:
     *
     *     <p></p>
     *     <p><br></p>
     *     <p>&nbsp;</p>
     *     <blockquote><br></blockquote>
     *
     * No se consideran vacíos:
     *
     *     <p>Texto real</p>
     *     <p><img src="imagen.webp"></p>
     *     <hr>
     */
    private function isEmptySpacingElement(DOMElement $element): bool
    {
        $tag = strtolower($element->tagName);

        // Un br ubicado directamente en el cuerpo
        // representa un salto vacío.
        if ($tag === 'br') {
            return true;
        }

        // Solo estos bloques pueden considerarse espacios vacíos.
        if (! in_array(
            $tag,
            ['p', 'h2', 'h3', 'blockquote'],
            true
        )) {
            return false;
        }

        // Una imagen o una separación horizontal es contenido real,
        // aunque no contenga texto.
        if (
            $element->getElementsByTagName('img')->length > 0
            || $element->getElementsByTagName('hr')->length > 0
        ) {
            return false;
        }

        // Convierte espacios HTML especiales en espacios normales
        // para detectar correctamente párrafos aparentemente vacíos.
        $text = str_replace(
            "\u{00A0}",
            ' ',
            $element->textContent ?? ''
        );

        return trim($text) === '';
    }

    /**
     * Convierte texto plano en párrafos HTML escapados y normalizados.
     *
     * Ejemplo:
     *
     *     Primera línea
     *     Segunda línea
     *
     *     Otro párrafo
     *
     * se convierte en:
     *
     *     <p>Primera línea<br>Segunda línea</p>
     *     <p>Otro párrafo</p>
     */
    private function plainTextToHtml(string $text): string
    {
        // Unifica saltos de línea de Windows y Mac al formato \n.
        $text = trim(str_replace(["\r\n", "\r"], "\n", $text));

        // Evita crear párrafos vacíos cuando el texto solo tiene espacios.
        if ($text === '') {
            return '';
        }

        // Dos o más saltos de línea separan párrafos.
        $paragraphs = preg_split('/\n{2,}/', $text) ?: [];

        return collect($paragraphs)
            ->map(function (string $paragraph): string {
                // Dentro de un párrafo, cada línea queda limpia de espacios repetidos.
                $lines = array_map(
                    fn (string $line): string => trim(preg_replace('/[\t ]+/u', ' ', $line) ?? ''),
                    explode("\n", trim($paragraph))
                );
                // Se escapa el texto para impedir que se guarde HTML ejecutable.
                $escaped = array_map(
                    fn (string $line): string => htmlspecialchars($line, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                    $lines
                );

                // Los saltos simples dentro del mismo párrafo se muestran con <br>.
                return '<p>'.implode('<br>', $escaped).'</p>';
            })
            // Descarta párrafos que quedaron vacíos después de normalizar.
            ->filter(fn (string $paragraph): bool => $paragraph !== '<p></p>')
            ->implode('');
    }
}
