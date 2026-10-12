<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Foto: resultado da verificação da URL externa e responsável pelo envio manual. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('players', function (Blueprint $t) {
            $t->string('photo_status', 20)->nullable(); // ok | quebrada | bloqueada | generica | nao_imagem | host_nao_permitido
            $t->timestampTz('photo_checked_at')->nullable();
            $t->foreignId('photo_uploaded_by')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('players', function (Blueprint $t) {
            $t->dropConstrainedForeignId('photo_uploaded_by');
            $t->dropColumn(['photo_status', 'photo_checked_at']);
        });
    }
};
