<?php

/* ============================================================================
 * SERVICE: ArticleImageSafetyClassifier.php
 * ============================================================================
 *
 * Clasifica las imágenes de portada mediante el modelo de seguridad incluido
 * en TRAMA y devuelve la probabilidad correspondiente a tres categorías:
 *
 * - NSFL: contenido gráfico extremo, gore o imágenes perturbadoras.
 * - NSFW: contenido sexual, desnudez o material altamente sugerente.
 * - SFW: contenido considerado seguro o permitido.
 *
 * También indica cuál de las tres categorías obtuvo la probabilidad más alta. 
 *
 * Ejecuta un script de Node.js que prepara la imagen y convierte sus píxeles
 * en un tensor, es decir, una estructura numérica multidimensional utilizada
 * para representar la imagen en el formato que espera el modelo.
 *
 * En este caso, el tensor organiza los datos mediante cuatro dimensiones:
 * cantidad de imágenes, canales RGB, altura y ancho. Su estructura es
 * [1, 3, 224, 224]&#58; una imagen, tres canales de color y 224 × 224 píxeles.
 *
 * El modelo utiliza el formato ONNX, sigla de Open Neural Network Exchange.
 * ONNX es un formato estándar para almacenar modelos de aprendizaje automático
 * de manera independiente de la biblioteca con la que fueron entrenados.
 *
 * ONNX Runtime carga y ejecuta ese modelo dentro de Node.js. A partir de los
 * valores numéricos del tensor, calcula las probabilidades correspondientes a
 * contenido gráfico extremo, contenido sexual y contenido permitido.
 *
 * Este servicio solamente devuelve la clasificación. La decisión de aceptar
 * o rechazar una portada se aplica posteriormente mediante las reglas
 * editoriales de la aplicación.
 * ============================================================================ */

namespace App\Services\Images;

use Illuminate\Support\Facades\Process;
use App\Support\TramaLog;

use RuntimeException;
use JsonException;
use Throwable;

class ArticleImageSafetyClassifier
{
    /**
     * Ruta relativa del script que ejecuta la clasificación de imágenes.
     *
     * El script forma parte del código de TRAMA y conserva la misma ubicación en
     * todos los entornos. La ruta física completa se obtiene posteriormente con
     * base_path(), por lo que no es necesario configurarla mediante el archivo .env.
     */
    private const SCRIPT_PATH = 'scripts/images/classify-image-safety.mjs';

    /**
     * Tiempo máximo permitido para clasificar una imagen.
     *
     * Evita que una ejecución bloqueada mantenga esperando indefinidamente
     * la petición utilizada para cargar una portada.
     */
    private const PROCESS_TIMEOUT_SECONDS = 30;

    /**
     * Categorías que obligatoriamente debe devolver el modelo.
     */
    private const LABELS = [
        'NSFL',
        'NSFW',
        'SFW',
    ];

    /**
     * Clasifica una imagen y devuelve sus probabilidades de seguridad.
     *
     * Categorías:
     * - NSFL: contenido gráfico extremo o gore.
     * - NSFW: contenido sexual o altamente sugerente.
     * - SFW: contenido permitido.
     *
     * @return array{
     *     primary: string,
     *     probabilities: array{
     *         NSFL: float,
     *         NSFW: float,
     *         SFW: float
     *     }
     * }
     */
    public function classify(string $imagePath): array
    {
        $absoluteImagePath = realpath($imagePath);

        if (
            $absoluteImagePath === false
            || ! is_file($absoluteImagePath)
        ) {
            $this->failClassification(
                'No se encontró la imagen que debía clasificarse.',
                __FUNCTION__,
                [
                    'received_path' => $imagePath,
                ]
            );
        }

        $scriptPath = base_path(self::SCRIPT_PATH);

        if (! is_file($scriptPath)) {
            /*
             * Registra el problema técnico en el log personalizado de TRAMA.
             *
             * La ruta esperada permite detectar inmediatamente si el archivo fue
             * eliminado, movido o no fue incluido durante el despliegue.
             */
            $this->failClassification(
                    'No se encontró el script de clasificación de imágenes.',
                    __FUNCTION__,
                    [
                        'expected_path' => $scriptPath,
                        'relative_path' => self::SCRIPT_PATH,
                    ]
                );
        }

        try {
            /*
            * Ejecuta el script de Node.js encargado de cargar el modelo ONNX, preparar
            * la imagen y calcular las probabilidades NSFL, NSFW y SFW.
            *
            * Process::path() establece la raíz del proyecto como directorio de trabajo.
            * timeout() limita cuánto tiempo puede permanecer ejecutándose el proceso.
            *
            * El comando se entrega como un array para que cada argumento sea tratado por
            * separado y la ruta de la imagen no sea interpretada como parte de un comando
            * del sistema.
            *
            * El comando ejecutado equivale a:
            *
            *   node scripts/images/classify-image-safety.mjs "ruta/de/la/imagen"
            */
            $result = Process::path(base_path())
                ->timeout(self::PROCESS_TIMEOUT_SECONDS)
                /*
                * En Windows, cuando el proceso de Node (classify-image-safety.mjs) se lanza
                * a través de php.exe -> cmd.exe -> node.exe, Node a veces no logra inicializar
                * su generador de números aleatorios criptográfico (CSPRNG) y el proceso aborta
                * con "Assertion failed: ncrypto::CSPRNG(nullptr, 0)" antes de ejecutar el script.
                *
                * Esto ocurría porque el proceso no estaba recibiendo correctamente las variables
                * de entorno SystemRoot y PATH.
                *
                * Pasar explícitamente SystemRoot y PATH asegura que el subproceso reciba las
                * variables de entorno que Windows necesita para acceder a sus componentes
                * criptográficos mediante BCryptGenRandom, evitando el fallo.
                */
                ->env([
                    // Ruta de instalación de Windows (ej: C:\Windows), requerida por Node para acceder a BCryptGenRandom
                    // Necesario para que Node pueda acceder al proveedor de criptografía de Windows (CSPRNG)
                    'SystemRoot' => getenv('SystemRoot'),
                     // Lista de rutas donde el sistema busca ejecutables y DLLs
                     // Necesario para que el sistema pueda localizar los ejecutables y DLLs requeridos
                    'PATH' => getenv('PATH'),
                ])
                ->run([
                    /*
                    * Obtiene desde config/trama.php la ruta absoluta del ejecutable
                    * de Node.js configurada mediante TRAMA_NODE_BINARY en .env.
                    */                
                    (string) config('trama.node_binary'),
                    /*
                    * Ruta absoluta del script de Node.js encargado de ejecutar
                    * la clasificación de seguridad.
                    */
                    $scriptPath,
                    /*
                    * Ruta absoluta de la imagen original que debe analizar
                    * el clasificador.
                    */
                    $absoluteImagePath,
                ]);

            } catch (Throwable $exception) {
               /*
                * Registra errores producidos antes de obtener un resultado:
                * imposibilidad de iniciar el proceso, timeout u otro fallo interno.
                */
                $this->failClassification(
                    'No se pudo ejecutar el clasificador de imágenes.',
                    __FUNCTION__,
                    [
                        'script_path' => $scriptPath,
                        'node_binary' => (string) config('trama.node_binary'),
                        'exception_class' => $exception::class,
                        'exception_message' => $exception->getMessage(),
                    ],
                    $exception
                );
            }

        /*
        * Node pudo iniciarse, pero terminó con un código de error.
        *
        * La salida técnica se registra en el log y nunca se muestra
        * directamente en el formulario.
        */
        if ($result->failed()) {
            $this->failClassification(
                'El clasificador de imágenes terminó con un error.',
                __FUNCTION__,
                [
                    'script_path' => $scriptPath,
                    'node_binary' => (string) config('trama.node_binary'),
                    'exit_code' => $result->exitCode(),
                    'error_output' => trim($result->errorOutput()),
                ]
            );
        }

        try {
           /*
            * Convierte el JSON enviado por Node.js mediante stdout en un array de PHP.
            *
            * trim() elimina espacios sobrantes.
            * true genera arrays asociativos.
            * 512 limita la cantidad de niveles anidados dentro del JSON
            * JSON_THROW_ON_ERROR lanza una excepción si el JSON no es válido.
            */
            $classification = json_decode(
                trim($result->output()),
                true,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (JsonException $exception) {
            $this->failClassification(
                'El clasificador devolvió una respuesta JSON inválida.',
                __FUNCTION__,
                [
                    'script_path' => $scriptPath,
                    'process_output' => trim($result->output()),
                    'error_output' => trim($result->errorOutput()),
                    'exception_class' => $exception::class,
                    'exception_message' => $exception->getMessage(),
                ],
                $exception
            );
        }

        return $this->validateClassification($classification);
    }

    /**
     * Verifica que la respuesta contenga todas las categorías esperadas y que
     * cada probabilidad sea un número válido comprendido entre cero y uno.
     *
     * @return array{
     *     primary: string,
     *     probabilities: array{
     *         NSFL: float,
     *         NSFW: float,
     *         SFW: float
     *     }
     * }
     */
    private function validateClassification(mixed $classification): array
    {
        /*
        * json_decode() podría devolver distintos tipos de datos dependiendo del
        * contenido recibido. La respuesta válida debe ser obligatoriamente un
        * array asociativo con la clasificación y sus probabilidades.
        */
        if (! is_array($classification)) {
            $this->failClassification(
                'El clasificador devolvió una estructura inválida.',
                __FUNCTION__,
                [
                    'classification' => $classification,
                ]
            );
        }

        /*
        * El operador ?? permite obtener null cuando alguna clave no existe.
        * De esta manera, una respuesta incompleta será detectada por las
        * validaciones siguientes sin producir un aviso de índice inexistente.
        */
        $primary = $classification['primary'] ?? null;
        $probabilities = $classification['probabilities'] ?? null;

        /*
        * La categoría principal debe ser un texto incluido en la lista cerrada
        * de categorías admitidas por TRAMA.
        *
        * El tercer argumento true de in_array() obliga a comparar también el tipo
        * del valor y evita aceptar conversiones automáticas de PHP.
        *
        * Las probabilidades deben estar agrupadas dentro de un array asociativo.
        */
        if (
            ! is_string($primary)
            || ! in_array($primary, self::LABELS, true)
            || ! is_array($probabilities)
        ) {
            $this->failClassification(
                'El clasificador devolvió categorías inválidas.',
                __FUNCTION__,
                [
                    'primary' => $primary,
                    'probabilities' => $probabilities,
                ]
            );
        }

        /*
        * Se construye un array nuevo en lugar de devolver directamente la
        * respuesta recibida. Así solamente se conservan las categorías previstas
        * y todas sus probabilidades quedan normalizadas como valores float.
        */
        $validatedProbabilities = [];

        foreach (self::LABELS as $label) {
            /*
            * Cada categoría declarada por la aplicación debe existir en la
            * respuesta. Cuando falta, se utiliza null para que la validación falle
            * de forma controlada.
            */
            $probability = $probabilities[$label] ?? null;

            /*
            * Una probabilidad válida debe:
            *
            * - ser numérica;
            * - ser finita, por lo que no puede ser infinito ni NaN;
            * - encontrarse entre 0 y 1.
            *
            * El valor 0 representa una probabilidad del 0 % y el valor 1
            * representa una probabilidad del 100 %.
            */
            if (
                ! is_numeric($probability)
                || ! is_finite((float) $probability)
                || (float) $probability < 0
                || (float) $probability > 1
            ) {
                $this->failClassification(
                    'El clasificador devolvió una probabilidad inválida.',
                    __FUNCTION__,
                    [
                        'label' => $label,
                        'probability' => $probability,
                    ]
                );
            }

            /*
            * La conversión explícita garantiza que el resultado final contenga
            * siempre números float, aunque el JSON haya devuelto un entero o una
            * representación numérica compatible.
            */
            $validatedProbabilities[$label] = (float) $probability;
        }

        /*
        * Se devuelve una estructura controlada  para que el resto de la
        * aplicación no dependa directamente del contenido sin validar recibido
        * desde el proceso de Node.js.
        *
        * Ejemplo del resultado:
        *
        * [
        *     'primary' => 'SFW',
        *     'probabilities' => [
        *         'NSFL' => 0.0290,         // 2,90 %
        *         'NSFW' => 0.0351,         // 3,51 %
        *         'SFW' => 0.9359,          // 93,59 %
        *     ],
        * ]
        *
        * primary contiene la categoría con la probabilidad más alta, mientras que
        * probabilities conserva el valor calculado para cada categoría admitida.
        * Las probabilidades se expresan como valores entre 0 y 1. Para convertirlas
        * a porcentaje se multiplican por 100.
        */
        return [
            'primary' => $primary,
            'probabilities' => $validatedProbabilities,
        ];
    }

    /**
     * Registra un fallo interno del clasificador y devuelve al formulario
     * un mensaje que no expone detalles técnicos.
     *
     * @param string $logMessage Descripción técnica almacenada en el log.
     * @param string $method Método donde se detectó el problema.
     * @param array<string, mixed> $context Información adicional para diagnosticarlo.
     */
    private function failClassification(
        string $logMessage,
        string $method,
        array $context = [],
        ?Throwable $previous = null
    ): never {
        TramaLog::error(
            $logMessage,
            [
                'service' => self::class,
                'method' => $method,
                ...$context,
            ]
        );

        throw new RuntimeException(
            'No se pudo verificar la imagen de portada. Intentá nuevamente.',
            previous: $previous
        );
    }
}