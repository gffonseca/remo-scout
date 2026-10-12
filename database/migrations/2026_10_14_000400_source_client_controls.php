<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F09 — controle de fonte: cota mensal (além da diária), circuito (falhas seguidas → pausa com retomada)
 * e expectativa de atualização para diagnosticar dado atrasado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('data_sources', function (Blueprint $t) {
            $t->unsignedInteger('monthly_quota')->nullable();
            $t->unsignedInteger('used_month')->default(0);
            $t->string('quota_month', 7)->nullable(); // AAAA-MM no fuso de exibição
            $t->unsignedInteger('consecutive_failures')->default(0);
            $t->timestampTz('circuit_open_until')->nullable();
            $t->unsignedInteger('expected_refresh_hours')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('data_sources', function (Blueprint $t) {
            $t->dropColumn(['monthly_quota', 'used_month', 'quota_month', 'consecutive_failures', 'circuit_open_until', 'expected_refresh_hours']);
        });
    }
};
