<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class TramaIntegritySeeder extends Seeder
{
    public function run(): void
    {
        $this->assertSame(2500, DB::table('users')->where('role', 'reader')->count(), 'usuarios registrados');
        $this->assertSame(6, DB::table('users')->whereIn('role', ['admin', 'editor', 'journalist'])->count(), 'empleados');
        $this->assertSame(90, DB::table('articles')->count(), 'noticias');

        $expectedStatuses = [
            'published' => 60,
            'archived' => 8,
            'scheduled' => 5,
            'review' => 7,
            'needs_changes' => 5,
            'draft' => 5,
        ];

        foreach ($expectedStatuses as $status => $expected) {
            $this->assertSame(
                $expected,
                DB::table('articles')->where('status', $status)->count(),
                "noticias {$status}",
            );
        }

        $this->assertSame(6, DB::table('categories')->count(), 'categorías');
        $this->assertSame(12, DB::table('tags')->where('is_active', true)->count(), 'etiquetas activas');
        $this->assertSame(15, DB::table('advertisements')->count(), 'publicidades');
        $this->assertSame(285, DB::table('advertisement_daily_metrics')->count(), 'métricas publicitarias');

        $nonPublicWithViews = DB::table('articles')
            ->whereNotIn('status', ['published', 'archived'])
            ->where('views', '>', 0)
            ->count();
        $this->assertSame(0, $nonPublicWithViews, 'noticias no públicas con vistas');

        $nonPublicComments = DB::table('comments')
            ->join('articles', 'articles.id', '=', 'comments.article_id')
            ->whereNotIn('articles.status', ['published', 'archived'])
            ->count();
        $this->assertSame(0, $nonPublicComments, 'comentarios en noticias no públicas');

        $viewMismatchQuery = DB::table('articles')
            ->leftJoin('article_views', 'article_views.article_id', '=', 'articles.id')
            ->selectRaw('articles.id, articles.views, COUNT(article_views.id) AS actual_views')
            ->groupBy('articles.id', 'articles.views')
            ->havingRaw('articles.views <> COUNT(article_views.id)');

        $viewMismatch = DB::query()
            ->fromSub($viewMismatchQuery, 'view_mismatches')
            ->count();
        $this->assertSame(0, $viewMismatch, 'contadores de vistas inconsistentes');

        $unverifiedParticipation = DB::table('comments')
            ->join('users', 'users.id', '=', 'comments.user_id')
            ->where('users.role', 'reader')
            ->whereNull('users.email_verified_at')
            ->count();
        $this->assertSame(0, $unverifiedParticipation, 'comentarios de cuentas no verificadas');

        $blockedWithoutResolution = DB::table('users')
            ->where('role', 'reader')
            ->where('is_active', false)
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('user_moderation_reviews')
                    ->whereColumn('user_moderation_reviews.user_id', 'users.id')
                    ->where('user_moderation_reviews.resolution', 'blocked');
            })
            ->count();
        $this->assertSame(0, $blockedWithoutResolution, 'cuentas bloqueadas sin resolución administrativa');
    }

    private function assertSame(int $expected, int $actual, string $label): void
    {
        if ($expected !== $actual) {
            throw new RuntimeException(
                "Integridad TRAMA: {$label}. Esperado {$expected}; obtenido {$actual}.",
            );
        }
    }
}
