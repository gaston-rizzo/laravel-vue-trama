<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('articles', 'content_blocks')) {
            return;
        }

        Schema::table('articles', function (Blueprint $table): void {
            $table->dropColumn('content_blocks');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('articles', 'content_blocks')) {
            return;
        }

        Schema::table('articles', function (Blueprint $table): void {
            $table->json('content_blocks')->nullable()->after('body');
        });
    }
};
