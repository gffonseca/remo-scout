<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Fatia 2 — fontes de dados, execuções, partidas, participações e estatísticas por temporada. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_sources', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('key', 40)->unique();
            $table->string('name');
            $table->string('kind', 30); // api_comercial, nao_oficial, documento_publico, oficial_credenciado
            $table->boolean('enabled')->default(false);
            $table->boolean('requires_credential')->default(false);
            $table->string('credential_env')->nullable();
            $table->string('base_url')->nullable();
            $table->unsignedInteger('min_interval_ms')->default(1000);
            $table->unsignedInteger('daily_quota')->nullable();
            $table->unsignedInteger('used_today')->default(0);
            $table->date('quota_date')->nullable();
            $table->string('health', 20)->default('nao_testado'); // ok, degradado, bloqueado, sem_credencial, nao_testado
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('last_error_at')->nullable();
            $table->text('last_error')->nullable();
            $table->text('terms_note')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('sync_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('data_source_id')->constrained()->cascadeOnDelete();
            $table->string('job', 60);
            $table->jsonb('scope')->nullable();
            $table->string('status', 20)->default('solicitado'); // solicitado, em_execucao, concluido, parcial, falhou
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('requests_made')->default(0);
            $table->unsignedInteger('received')->default(0);
            $table->unsignedInteger('created')->default(0);
            $table->unsignedInteger('updated')->default(0);
            $table->unsignedInteger('discarded')->default(0);
            $table->unsignedInteger('errors')->default(0);
            $table->jsonb('messages')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        Schema::create('club_external_ids', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('club_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 40);
            $table->string('external_id', 120);
            $table->string('external_name')->nullable();
            $table->timestamps();
            $table->unique(['provider', 'external_id']);
        });

        Schema::create('fixtures', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('provider', 40);
            $table->string('external_id', 120);
            $table->foreignUuid('competition_id')->nullable()->constrained()->nullOnDelete();
            $table->string('competition_name')->nullable();
            $table->string('season', 20)->nullable();
            $table->string('round_label')->nullable();
            $table->date('played_on')->nullable();
            $table->foreignUuid('home_club_id')->nullable()->constrained('clubs')->nullOnDelete();
            $table->foreignUuid('away_club_id')->nullable()->constrained('clubs')->nullOnDelete();
            $table->unsignedSmallInteger('home_score')->nullable();
            $table->unsignedSmallInteger('away_score')->nullable();
            $table->string('status', 20)->nullable();
            $table->string('home_formation', 20)->nullable();
            $table->string('away_formation', 20)->nullable();
            $table->unsignedSmallInteger('stoppage_1t')->nullable();
            $table->unsignedSmallInteger('stoppage_2t')->nullable();
            $table->string('source_url')->nullable();
            $table->string('payload_hash', 64)->nullable();
            $table->timestamp('fetched_at')->nullable();
            $table->timestamps();
            $table->unique(['provider', 'external_id']);
            $table->index(['competition_id', 'season']);
        });

        Schema::create('appearances', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('fixture_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('player_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('club_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider', 40);
            $table->boolean('started')->default(false);
            $table->boolean('used')->default(false); // entrou em campo
            $table->unsignedSmallInteger('minute_in')->nullable();
            $table->unsignedSmallInteger('minute_out')->nullable();
            $table->unsignedSmallInteger('minutes')->nullable(); // regulamentares (0..90); null = não determinado
            $table->string('position_label', 40)->nullable();
            $table->string('shirt', 5)->nullable();
            $table->unsignedSmallInteger('goals')->default(0);
            $table->unsignedSmallInteger('penalty_goals')->default(0);
            $table->unsignedSmallInteger('own_goals')->default(0);
            $table->unsignedSmallInteger('yellow_cards')->default(0);
            $table->unsignedSmallInteger('red_cards')->default(0);
            $table->string('source_line')->nullable(); // linha da súmula de onde saiu (auditoria)
            $table->timestamps();
            $table->unique(['fixture_id', 'player_id']);
            $table->index('player_id');
        });

        Schema::create('player_season_stats', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('player_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 40);
            $table->string('competition_key', 120);
            $table->string('competition_name');
            $table->string('season', 20);
            $table->string('club_name')->nullable();
            $table->unsignedSmallInteger('appearances')->nullable();
            $table->unsignedSmallInteger('starts')->nullable();
            $table->unsignedInteger('minutes')->nullable();
            $table->unsignedSmallInteger('goals')->nullable();
            $table->unsignedSmallInteger('assists')->nullable();
            $table->unsignedSmallInteger('yellow_cards')->nullable();
            $table->unsignedSmallInteger('red_cards')->nullable();
            $table->unsignedSmallInteger('clean_sheets')->nullable();
            $table->unsignedSmallInteger('goals_conceded')->nullable();
            $table->decimal('rating', 4, 2)->nullable(); // nota proprietária do provedor (não usar como filtro principal)
            $table->jsonb('extra')->nullable(); // métricas específicas do provedor, com nomes originais
            $table->string('source_url')->nullable();
            $table->timestamp('fetched_at')->nullable();
            $table->timestamps();
            $table->unique(['player_id', 'provider', 'competition_key', 'season', 'club_name']);
        });

        Schema::create('enrichment_reviews', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('player_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 40);
            $table->string('field', 60);
            $table->text('current_value')->nullable();
            $table->text('proposed_value')->nullable();
            $table->string('status', 20)->default('pendente'); // pendente, aceito, rejeitado
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'player_id']);
        });

        Schema::create('identity_candidates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('player_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 40);
            $table->string('external_id', 120);
            $table->string('label');
            $table->jsonb('details')->nullable();
            $table->unsignedSmallInteger('score')->default(0);
            $table->string('status', 20)->default('pendente'); // pendente, vinculado, descartado
            $table->timestamps();
            $table->unique(['player_id', 'provider', 'external_id']);
        });

        Schema::table('players', function (Blueprint $table) {
            $table->jsonb('field_sources')->nullable(); // {"birth_date": {"source": "transfermarkt", "at": "..."}}
            $table->string('photo_url')->nullable();
            $table->timestamp('enriched_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('players', fn (Blueprint $t) => $t->dropColumn(['field_sources', 'photo_url', 'enriched_at']));
        foreach (['identity_candidates', 'enrichment_reviews', 'player_season_stats', 'appearances', 'fixtures', 'club_external_ids', 'sync_runs', 'data_sources'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
