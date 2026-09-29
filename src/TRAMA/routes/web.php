<?php

/* ============================================================================
 * ROUTES: web.php
 * ============================================================================
 *
 * Define las rutas públicas y privadas de TRAMA.
 *
 * El portal público permite leer, buscar y comentar. El editorial
 * agrupa noticias, comentarios y flujo editorial; los módulos estructurales de
 * cuentas, categorías, etiquetas y publicidades requieren rol administrador.
 * ============================================================================ */

use App\Http\Controllers\Admin\ArticleController as AdminArticleController;
use App\Http\Controllers\Admin\ArticleRevisionController;
use App\Http\Controllers\Admin\ArticleWorkflowController;
use App\Http\Controllers\Admin\AdvertisementController as AdminAdvertisementController;
use App\Http\Controllers\Admin\CategoryController as AdminCategoryController;
use App\Http\Controllers\Admin\CommentController as AdminCommentController;
use App\Http\Controllers\Admin\DashboardController as AdminDashboardController;
// use App\Http\Controllers\Admin\MediaController as AdminMediaController;
use App\Http\Controllers\Admin\TagController as AdminTagController;
use App\Http\Controllers\Admin\UserController as AdminUserController;

use App\Http\Controllers\Auth\EmailVerificationPromptController;
use App\Http\Controllers\Auth\EmailVerificationNotificationController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\Auth\VerifyEmailController;
use App\Http\Controllers\Public\ArticleController;
use App\Http\Controllers\Public\AdvertiserDemoController;
use App\Http\Controllers\Public\AdvertisementClickController;
use App\Http\Controllers\Public\AdvertisementImpressionController;
use App\Http\Controllers\Public\CategoryController;
use App\Http\Controllers\Public\CommentController;
use App\Http\Controllers\Public\HomeController;
use App\Http\Controllers\Public\InstitutionalController;
use App\Http\Controllers\Public\JournalistController;
use App\Http\Controllers\Public\MyCommentController;
use App\Http\Controllers\Public\SearchController;
use App\Http\Controllers\Public\CommentReportController;

use App\Http\Middleware\EnsureEditorialAccess;
use App\Http\Middleware\EnsureAdminRole;
use App\Http\Middleware\EnsureArticleAccess;
use App\Http\Middleware\EnsureCommentModerationAccess;
use App\Http\Middleware\ThrottleCommentAttempts;

use App\Models\User;

use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Auth\Middleware\EnsureEmailIsVerified;
use Illuminate\Auth\Middleware\RedirectIfAuthenticated;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Middleware\ValidateSignature;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

// Muestra la portada pública con noticia principal, ranking, secciones y banner de header.
Route::get('/', HomeController::class)->name('home');

// Muestra la pantalla pública de búsqueda de noticias por texto.
Route::get('/buscar', SearchController::class)->name('search');

// Muestra una sección pública, por ejemplo política, economía o cultura.
Route::get('/seccion/{slug}', [CategoryController::class, 'show'])->name('categories.show');

// Muestra la página pública de lectura de una noticia usando su slug.
Route::get('/noticias/{slug}', [ArticleController::class, 'show'])->name('articles.show');

// Muestra el perfil público de un periodista y su archivo de noticias publicadas.
Route::get('/periodistas/{user}/{slug?}', [JournalistController::class, 'show'])->name('journalists.show');

// Páginas públicas que completan la información legal y de contacto de TRAMA.
Route::get('/quienes-somos', [InstitutionalController::class, 'about'])->name('about');
Route::get('/contacto', [InstitutionalController::class, 'contact'])->name('contact');
Route::post('/contacto', [InstitutionalController::class, 'storeContact'])->name('contact.store');
Route::get('/privacidad', [InstitutionalController::class, 'privacy'])->name('privacy');
Route::get('/terminos', [InstitutionalController::class, 'terms'])->name('terms');

// Landings internas para los anunciantes ficticios usados por el proyecto de demostración.
Route::get('/demo/anunciantes/{advertiser}', AdvertiserDemoController::class)
    ->name('advertisers.demo');

// Registra en segundo plano el click sobre un banner. La navegación hacia el
// destino del anunciante se realiza directamente desde AdvertisementBanner.vue.
Route::get('/ads/{advertisement}/click', AdvertisementClickController::class)
    ->name('advertisements.click');

Route::get('/ads/{advertisement}/impression', AdvertisementImpressionController::class)
    ->name('advertisements.impression');

// Devuelve una página de comentarios principales para la noticia.
// Se usa cuando el usuario presiona "Ver más comentarios".
Route::get('/noticias/{article}/comentarios', [CommentController::class, 'index'])->name('comments.index');

// Informa si esta cuenta todavía tiene un comentario o una respuesta de la
// noticia atravesando la moderación automática. La página lo consulta sólo
// mientras muestra el mensaje "Comentario recibido".
Route::get(
    '/noticias/{article}/comentarios/estado-procesamiento',
    [CommentController::class, 'processingStatus']
)
    ->middleware(Authenticate::class)
    ->name('comments.processing-status');

// Guarda un comentario principal o una respuesta.
// Requiere sesión porque solo usuarios autenticados pueden comentar.
//
// ThrottleCommentAttempts limita a 5 solicitudes cada 10 minutos y se ejecuta
// antes del controlador, por lo que también contabiliza intentos que terminen
// posteriormente en una excepción inesperada.
Route::post('/noticias/{article}/comentarios', [CommentController::class, 'store'])
    ->middleware([
        Authenticate::class,
        ThrottleCommentAttempts::class,
    ])
    ->name('comments.store');

// Devuelve más respuestas de un comentario principal.
// Permite cargar respuestas de forma progresiva sin llenar la página de golpe.
Route::get('/comentarios/{comment}/respuestas', [CommentController::class, 'replies'])->name('comments.replies');

// Actualiza un comentario propio mientras el estado permite edición.
// Requiere sesión para saber quién está intentando modificarlo.
Route::patch('/comentarios/{comment}', [CommentController::class, 'update'])
    ->middleware(Authenticate::class)
    ->name('comments.update');

// Elimina un comentario propio permitido por las reglas del controlador.
// Requiere sesión para validar que el usuario sea el autor.
Route::delete('/comentarios/{comment}', [CommentController::class, 'destroy'])
    ->middleware(Authenticate::class)
    ->name('comments.destroy');

// Activa o quita el "Me gusta" del usuario sobre un comentario.
// La base impide duplicar votos del mismo usuario sobre el mismo comentario.
Route::post('/comentarios/{comment}/me-gusta', [CommentController::class, 'toggleLike'])
    ->middleware(Authenticate::class)
    ->name('comments.like');

// Registra un reporte de un lector sobre un comentario publicado.
// El comentario continúa visible hasta que un editor revise el reporte.
Route::post(
    '/comentarios/{comment}/reportes',
    [CommentReportController::class, 'store']
)
    ->middleware(Authenticate::class)
    ->name('comments.reports.store');

// Muestra la grilla privada con comentarios y respuestas escritos por el usuario.
Route::get('/mis-comentarios', MyCommentController::class)
    ->middleware(Authenticate::class)
    ->name('my-comments.index');

// Registro y verificación propios de TRAMA, sin login automático posterior al alta.
Route::middleware(RedirectIfAuthenticated::class)->group(function (): void {
    // Muestra el formulario de registro público cuando no hay sesión iniciada.
    Route::get('/register', [RegisteredUserController::class, 'create'])->name('register');

    // Crea la cuenta, envía el correo de verificación y no inicia sesión automáticamente.
    Route::post('/register', [RegisteredUserController::class, 'store'])->name('register.store');
});

// Muestra la pantalla que indica que falta confirmar el correo o que la cuenta ya fue verificada.
Route::get('/verify-email', EmailVerificationPromptController::class)->name('verification.notice');

// Procesa el enlace firmado de verificación recibido por correo.
// ValidateSignature comprueba que el enlace no haya sido alterado y throttle limita intentos.
Route::get('/verify-email/{id}/{hash}', VerifyEmailController::class)
    ->middleware([ValidateSignature::class, ThrottleRequests::class.':6,1'])
    ->name('verification.verify');

// Reenvía el correo de verificación para una cuenta autenticada.
// Authenticate exige sesión y throttle evita abuso del reenvío.
Route::post('/email/verification-notification', [EmailVerificationNotificationController::class, 'store'])
    ->middleware([Authenticate::class, ThrottleRequests::class.':6,1'])
    ->name('verification.send');

// Fortify usa esta ruta como destino después del login.
// La ruta decide si el usuario entra al panel editorial o vuelve a la portada.
Route::get('/dashboard', function () {
    // El comentario de tipo evita que el IDE marque canAccessEditorial como indefinido.
    /** @var User|null $user */
    $user = Auth::user();

    // Usuarios internos activos van al panel; usuarios registrados verificados vuelven a portada.
    if ($user?->canAccessEditorial()) {
        return redirect()->route('admin.dashboard');
    }

    return redirect()->route('home');
})->middleware([Authenticate::class, EnsureEmailIsVerified::class])->name('dashboard');

// #######################################################################################################################
// Grupo privado del panel editorial.
// #######################################################################################################################
// Todas las rutas internas requieren sesión, correo verificado y rol editorial activo.
Route::prefix('admin')
    ->name('admin.')
    ->middleware([Authenticate::class, EnsureEmailIsVerified::class, EnsureEditorialAccess::class])
    ->group(function (): void {
        // Muestra el dashboard del panel con métricas, actividad y tareas pendientes.
        Route::get('/', AdminDashboardController::class)->name('dashboard');

        // ====================================================================================================
        // Noticias: acceso exclusivo de periodistas y editores activos.        
        // El administrador no participa del flujo editorial y queda bloqueado.
        // ====================================================================================================
        Route::middleware(EnsureArticleAccess::class)->group(function (): void {
            // Envía una noticia nuevamente al autor con pedidos de corrección.
            Route::patch('articles/{article}/request-changes', [ArticleWorkflowController::class, 'requestChanges'])
                ->name('articles.request-changes');

            // Guarda automáticamente cambios parciales del formulario de noticia.
            Route::patch('articles/{article}/autosave', [ArticleWorkflowController::class, 'autosave'])
                ->name('articles.autosave');

            // Cancela una publicación programada y devuelve la noticia a un estado privado editable.
            Route::patch('articles/{article}/cancel-schedule', [ArticleWorkflowController::class, 'cancelSchedule'])
                ->name('articles.cancel-schedule');

            // Restaura una noticia usando una versión histórica guardada en revisiones.
            Route::post('articles/{article}/revisions/{revision}/restore', [ArticleRevisionController::class, 'restore'])
                ->name('articles.revisions.restore');

            // Actualiza únicamente las marcas editoriales Urgente y Destacada de una noticia.
            Route::patch('articles/{article}/homepage', [ArticleWorkflowController::class, 'updateHomepage'])
                ->name('articles.homepage.update');

            // Elimina definitivamente un borrador o una noticia devuelta.
            // Solamente puede ejecutarla el autor mientras la noticia siga siendo privada.
            Route::delete(
                'articles/{article}/delete-permanently',
                [AdminArticleController::class, 'deletePermanently']
            )->name('articles.delete-permanently');

            /*
            * Ruta reservada para una futura implementación de imágenes
            * dentro del cuerpo de las noticias.
            *
            * Actualmente permanece deshabilitada porque RichTextEditor
            * no ofrece la opción de cargar imágenes internas.
            */
            // Route::post('media', [AdminMediaController::class, 'store'])
            //     ->name('media.store');

            // Crea, lista, edita, actualiza y archiva noticias desde el panel.
            // Se excluye show porque la lectura pública usa /noticias/{slug}.
            Route::resource('articles', AdminArticleController::class)->except(['show']);
        });

        // ====================================================================================================
        // Comentarios: acceso exclusivo de editores activos.
        // Periodistas y administradores no participan de la moderación.
        // ====================================================================================================
        Route::middleware(EnsureCommentModerationAccess::class)->group(function (): void {
            // Muestra comentarios para moderar dentro del panel editorial.
            Route::get('comments', [AdminCommentController::class, 'index'])->name('comments.index');

            // Devuelve el detalle completo utilizado por el modal de moderación.
            Route::get('comments/{comment}', [AdminCommentController::class, 'show'])->name('comments.show');

            // Aplica aprobar, rechazar/retirar o mantener un comentario reportado.
            Route::patch('comments/{comment}', [AdminCommentController::class, 'update'])->name('comments.update');

            // Deriva manualmente al administrador la cuenta pública autora del comentario.
            Route::patch(
                'comments/{comment}/refer-to-admin',
                [AdminCommentController::class, 'referToAdmin']
            )->name('comments.refer-to-admin');

            // Elimina definitivamente un comentario desde el modal de moderación.
            Route::delete('comments/{comment}', [AdminCommentController::class, 'destroy'])->name('comments.destroy');
        });

        // ====================================================================================================
        // Estructura y cuentas del CMS: acceso exclusivo de administrador.
        // ====================================================================================================        
        Route::middleware(EnsureAdminRole::class)->group(function (): void {
            // Lista empleados internos: administradores, editores y periodistas.
            Route::get('users/employees', [AdminUserController::class, 'employees'])->name('users.employees');

            // Lista usuarios registrados que participan en el portal.
            Route::get('users/readers', [AdminUserController::class, 'readers'])->name('users.readers');

            // Crea una cuenta interna desde el panel administrador.
            Route::post('users', [AdminUserController::class, 'store'])->name('users.store');

            // Actualiza identidad, rol, cargo, biografía y avatar de empleados internos.
            // Nombre y correo de lectores públicos no se modifican desde administración.
            Route::patch('users/{user}', [AdminUserController::class, 'update'])->name('users.update');

            // Activa o desactiva una cuenta sin borrar su historial.
            Route::patch('users/{user}/toggle-active', [AdminUserController::class, 'toggleActive'])->name('users.toggle-active');

            // Cierra una revisión administrativa sin bloquear la cuenta.
            Route::patch(
                'users/{user}/moderation-review/resolve',
                [AdminUserController::class, 'resolveModerationReview']
            )->name('users.moderation-review.resolve');

            // Envía un enlace de recuperación para restablecer el acceso de una cuenta.
            Route::post('users/{user}/reset-access', [AdminUserController::class, 'resetAccess'])->name('users.reset-access');

            // Elimina una cuenta cuando no conserva historial que deba auditarse.
            Route::delete('users/{user}', [AdminUserController::class, 'destroy'])->name('users.destroy');

            // Lista categorías públicas usadas como secciones del portal.
            Route::get('categories', [AdminCategoryController::class, 'index'])->name('categories.index');

            // Crea una categoría nueva para clasificar noticias.
            Route::post('categories', [AdminCategoryController::class, 'store'])->name('categories.store');

            // Actualiza nombre, descripción, color, orden y visibilidad de una categoría.
            // El slug histórico se conserva aunque cambie el nombre visible.
            // La portada puede reemplazarse desde el mismo formulario administrativo.
            Route::patch('categories/{category}', [AdminCategoryController::class, 'update'])->name('categories.update');

            // Activa o desactiva una categoría sin eliminar noticias asociadas.
            Route::patch('categories/{category}/toggle-active', [AdminCategoryController::class, 'toggleActive'])->name('categories.toggle-active');

            // Elimina una categoría solo si no tiene noticias asociadas.
            Route::delete('categories/{category}', [AdminCategoryController::class, 'destroy'])->name('categories.destroy');

            // Lista etiquetas usadas para relacionar noticias por tema.
            Route::get('tags', [AdminTagController::class, 'index'])->name('tags.index');

            // Crea una etiqueta nueva para clasificar noticias.
            Route::post('tags', [AdminTagController::class, 'store'])->name('tags.store');

            // Actualiza nombre, descripción y estado; el slug histórico se conserva.
            Route::patch('tags/{tag}', [AdminTagController::class, 'update'])->name('tags.update');

            // Activa o desactiva una etiqueta sin borrar referencias históricas.
            Route::patch('tags/{tag}/toggle-active', [AdminTagController::class, 'toggleActive'])->name('tags.toggle-active');

            // Fusiona una etiqueta secundaria dentro de una etiqueta principal.
            Route::post('tags/{tag}/merge', [AdminTagController::class, 'merge'])->name('tags.merge');

            // Elimina una etiqueta solo si no tiene noticias asociadas ni historial de fusión.
            Route::delete('tags/{tag}', [AdminTagController::class, 'destroy'])->name('tags.destroy');

            // Lista banners, métricas, gráfico y filtros del reporte de publicidades.
            Route::get('advertisements', [AdminAdvertisementController::class, 'index'])->name('advertisements.index');

            // Crea una publicidad completa con campaña, ubicación, imagen, destino y vigencia.
            Route::post('advertisements', [AdminAdvertisementController::class, 'store'])->name('advertisements.store');

            // Actualiza nombre, marca, ubicación, destino, fechas y estado habilitado.
            // La imagen puede reemplazarse y es obligatoria si cambia la ubicación.
            Route::patch('advertisements/{advertisement}', [AdminAdvertisementController::class, 'update'])->name('advertisements.update');

            // Elimina una campaña junto con sus métricas históricas.
            Route::delete('advertisements/{advertisement}', [AdminAdvertisementController::class, 'destroy'])->name('advertisements.destroy');
        });
    });
