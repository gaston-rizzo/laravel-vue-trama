<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Agrega o ajusta la información administrativa
     * asociada al bloqueo de una cuenta.
     */
    public function up(): void
    {
        /*
         * blocked_reason puede existir porque la estructura fue creada
         * anteriormente aunque esta migración no haya quedado registrada.
         */
        if (Schema::hasColumn('users', 'blocked_reason')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->string('blocked_reason', 40)
                    ->nullable()
                    ->change();
            });
        } else {
            Schema::table('users', function (Blueprint $table): void {
                $table->string('blocked_reason', 40)
                    ->nullable()
                    ->after('disabled_at');
            });
        }

        /*
         * La observación interna queda limitada a 200 caracteres.
         * Si ya existe como TEXT, se convierte a VARCHAR(200).
         */
        if (Schema::hasColumn('users', 'blocked_note')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->string('blocked_note', 200)
                    ->nullable()
                    ->change();
            });
        } else {
            Schema::table('users', function (Blueprint $table): void {
                $table->string('blocked_note', 200)
                    ->nullable()
                    ->after('blocked_reason');
            });
        }
    }

    /**
     * Elimina los campos administrativos asociados al bloqueo.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn([
                'blocked_reason',
                'blocked_note',
            ]);
        });
    }
};