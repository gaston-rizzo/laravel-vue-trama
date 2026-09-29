<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('articles', function (Blueprint $table) {
            $table
                ->longText('search_text')
                ->nullable()
                ->after('body');
        });

        /*
         * Laravel identifica tanto MySQL como MariaDB con el driver "mysql".
         * SQLite se usa en las pruebas y no admite índices FULLTEXT.
         */
        if (DB::connection()->getDriverName() === 'mysql') {
            Schema::table('articles', function (Blueprint $table) {
                $table->fullText(
                    'search_text',
                    'articles_search_text_fulltext'
                );
            });
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            Schema::table('articles', function (Blueprint $table) {
                $table->dropFullText(
                    'articles_search_text_fulltext'
                );
            });
        }

        Schema::table('articles', function (Blueprint $table) {
            $table->dropColumn('search_text');
        });
    }
};