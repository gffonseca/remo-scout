<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Revisão do Bloco 2: participação do reserva é desconhecida (null) quando a fonte não registra substituições — não "não entrou". */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('alter table appearances alter column used drop not null, alter column used drop default');
    }

    public function down(): void
    {
        DB::statement('update appearances set used = false where used is null');
        DB::statement('alter table appearances alter column used set default false, alter column used set not null');
    }
};
