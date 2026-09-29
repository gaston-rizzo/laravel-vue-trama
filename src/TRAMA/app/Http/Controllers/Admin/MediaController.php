<?php

/* ============================================================================
 * CONTROLLER: MediaController.php
 * ============================================================================
 *
 * Gestiona imágenes destinadas a insertarse dentro del cuerpo de una noticia.
 *
 * IMPORTANTE: Esta funcionalidad no se utiliza actualmente, pero se conserva 
 * para una futura implementación de imágenes dentro del editor enriquecido.
 *
 * Los archivos se almacenan físicamente en:
 *
 *      public/images/uploads/articles/content
 *
 * Cada imagen recibe un nombre único generado con UUID y queda disponible
 * mediante una URL como:
 *
 *      /images/uploads/articles/content/uuid.webp
 *
 * Además, sus datos se registran en la tabla media_assets:
 * noticia asociada, usuario que realizó la carga, ruta pública, texto
 * alternativo, tipo de uso y dimensiones de la imagen.
 *
 * Si el registro en la base de datos falla, se elimina el archivo físico
 * para evitar imágenes sin referencia dentro del sistema.
 * ============================================================================ */

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Models\MediaAsset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Throwable;

class MediaController extends Controller
{
    /**
     * Carpeta pública donde se guardan imágenes insertadas dentro del cuerpo.
     */
    private const INLINE_IMAGE_DIRECTORY = 'images/uploads/articles/content';

    /**
     * Guarda una imagen interna y devuelve su URL para insertarla en el editor.
     * 
     * El endpoint queda disponible para una futura interface de carga
     * dentro del editor enriquecido. Actualmente el formulario editorial
     * solamente permite seleccionar una imagen de portada.     
     */
    public function store(Request $request): JsonResponse
    {
        // Solo se aceptan imágenes livianas y formatos que el navegador muestra bien.
        $validated = $request->validate([
            // Archivo obligatorio, debe ser imagen y pesar hasta 5 MB.
            'image' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            // Si existe article_id, la imagen queda asociada de inmediato a esa noticia.
            'article_id' => ['nullable', 'integer', 'exists:articles,id'],
            // Texto alternativo opcional para accesibilidad y contexto editorial.
            'alt_text' => ['nullable', 'string', 'max:180'],
        ]);

        // Si la imagen pertenece a una noticia, se valida contra esa noticia concreta.
        $article = isset($validated['article_id'])
            ? Article::query()->findOrFail($validated['article_id'])
            : null;

        if ($article !== null) {
            // Obtiene el usuario que intenta adjuntar la imagen.
            $user = $request->user();

            // Comprueba si la noticia pertenece al usuario actual.
            $isOwner = $article->author_id === $user->id;

            // El periodista puede adjuntar imágenes únicamente
            // a sus propias noticias que todavía admiten edición.
            $canJournalistUpload =
                ! $user->canReviewArticles()
                && $isOwner
                && in_array(
                    $article->status,
                    Article::JOURNALIST_EDITABLE_STATUSES,
                    true
                );

            // El editor puede adjuntar imágenes a sus propias noticias
            // o a noticias ajenas que ya ingresaron al flujo editorial.
            $canEditorUpload =
                $user->canReviewArticles()
                && (
                    $isOwner
                    || $article->status !== 'draft'
                );

            // Rechaza la carga cuando la noticia queda fuera
            // del alcance editorial del usuario.
            if (! $canJournalistUpload && ! $canEditorUpload) {
                abort(
                    403,
                    'No tenés permisos para adjuntar imágenes a esta noticia.'
                );
            }
        }

        // La imagen se guarda en public para que el editor pueda verla inmediatamente.
        $directory = public_path(self::INLINE_IMAGE_DIRECTORY);
        // Crea la carpeta si todavía no existe.
        File::ensureDirectoryExists($directory);
        $file = $request->file('image');
        // Se toma la extensión detectada por Laravel y se normaliza a minúsculas.
        $extension = strtolower($file->extension() ?: $file->getClientOriginalExtension());
        // UUID evita colisiones y oculta el nombre original del archivo.
        $filename = Str::uuid()->toString().'.'.$extension;
        // move escribe el archivo físico dentro de public.
        $file->move($directory, $filename);
        // Ruta pública que se inserta en el HTML del editor.
        $path = '/'.self::INLINE_IMAGE_DIRECTORY.'/'.$filename;

        try {
            // La metadata se guarda en base. Si falla, el catch borra el archivo.
            $asset = DB::transaction(function () use ($article, $request, $validated, $path): MediaAsset {
                // Se registra metadata para auditar quién subió la imagen y dónde se usa.
                $dimensions = @getimagesize(public_path(ltrim($path, '/'))) ?: [null, null];

                return MediaAsset::query()->create([
                    // Puede ser null si la imagen se subió antes de que exista la noticia.
                    'article_id' => $article?->id,
                    // Usuario que realizó la carga.
                    'uploaded_by' => $request->user()->id,
                    // Disco lógico usado por el proyecto.
                    'disk' => 'public',
                    // inline indica que se usa dentro del cuerpo, no como portada.
                    'usage' => 'inline',
                    // Ruta que luego se usa en el atributo src del img.
                    'path' => $path,
                    // Descripción alternativa recibida desde el editor.
                    'alt_text' => $validated['alt_text'] ?? null,
                    // Dimensiones reales si PHP pudo leer la imagen.
                    'width' => $dimensions[0],
                    'height' => $dimensions[1],
                ]);
            }, attempts: 3);
        } catch (Throwable $exception) {
            // Si falla el registro en base, se elimina el archivo recién subido.
            File::delete(public_path(ltrim($path, '/')));
            throw $exception;
        }

        return response()->json([
            // Id del asset registrado en base.
            'id' => $asset->id,
            // URL que el editor debe insertar en el cuerpo HTML.
            'url' => $path,
            // Texto alternativo final guardado.
            'alt_text' => $asset->alt_text,
        ], 201);
    }
}
