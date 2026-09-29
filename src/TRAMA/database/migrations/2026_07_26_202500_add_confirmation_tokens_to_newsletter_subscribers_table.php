<?php

use App\Support\TramaClock;
/* ============================================================================
 * MIGRATION: add_confirmation_tokens_to_newsletter_subscribers_table.php
 * ============================================================================
 *
 * Agrega confirmación por correo y baja sin login al briefing de TRAMA.
 *
 * Cada suscripción tiene un token para confirmar el alta y otro token distinto
 * para cancelar la baja desde un enlace enviado por mail, sin exponer el email.
 * ============================================================================ */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Agrega tokens y fechas al registro de suscripciones.
     */
    public function up(): void
    {
        Schema::table('newsletter_subscribers', function (Blueprint $table): void {
            if (! Schema::hasColumn('newsletter_subscribers', 'confirmation_token')) {
                $table->string('confirmation_token', 100)->nullable()->unique()->after('status');
            }

            if (! Schema::hasColumn('newsletter_subscribers', 'unsubscribe_token')) {
                $table->string('unsubscribe_token', 100)->nullable()->unique()->after('confirmation_token');
            }

            if (! Schema::hasColumn('newsletter_subscribers', 'confirmed_at')) {
                $table->timestamp('confirmed_at')->nullable()->after('subscribed_at');
            }

            if (! Schema::hasColumn('newsletter_subscribers', 'cancelled_at')) {
                $table->timestamp('cancelled_at')->nullable()->after('confirmed_at');
            }
        });

        // Las suscripciones ya existentes se consideran confirmadas y reciben
        // token de baja para que los próximos correos puedan incluir el enlace.
        DB::table('newsletter_subscribers')
            ->orderBy('id')
            ->cursor()
            ->each(function (object $subscriber): void {
                DB::table('newsletter_subscribers')
                    ->where('id', $subscriber->id)
                    ->update([
                        'unsubscribe_token' => $subscriber->unsubscribe_token ?? Str::random(64),
                        'confirmed_at' => $subscriber->confirmed_at ?? $subscriber->subscribed_at ?? TramaClock::initial(),
                    ]);
            });
    }

    /**
     * Retira los campos agregados para volver al esquema anterior.
     */
    public function down(): void
    {
        Schema::table('newsletter_subscribers', function (Blueprint $table): void {
            foreach (['confirmation_token', 'unsubscribe_token', 'confirmed_at', 'cancelled_at'] as $column) {
                if (Schema::hasColumn('newsletter_subscribers', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
