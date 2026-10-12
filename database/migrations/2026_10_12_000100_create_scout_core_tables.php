<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * REMO SCOUT — núcleo da Fatia 1.
 * Convenções: UUID em tabelas de domínio; jsonb para listas; decimal para dinheiro;
 * toda observação relevante guarda origem (source_*), data do fato e autor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 20)->default('leitor')->after('email');
            $table->boolean('is_active')->default(true)->after('role');
            $table->boolean('can_view_financial')->default(false)->after('is_active');
            $table->text('app_authentication_secret')->nullable();
            $table->text('app_authentication_recovery_codes')->nullable();
            $table->timestamp('last_login_at')->nullable();
        });

        Schema::create('import_batches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('original_filename');
            $table->string('entity', 40)->default('players');
            $table->uuid('mapping_profile_id')->nullable();
            $table->jsonb('mapping')->nullable();
            $table->string('status', 20)->default('preview'); // preview, concluido, parcial, falhou, revertido
            $table->unsignedInteger('rows_total')->default(0);
            $table->unsignedInteger('rows_created')->default(0);
            $table->unsignedInteger('rows_updated')->default(0);
            $table->unsignedInteger('rows_skipped')->default(0);
            $table->unsignedInteger('rows_failed')->default(0);
            $table->jsonb('errors')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('reverted_at')->nullable();
            $table->foreignId('reverted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('import_mapping_profiles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('entity', 40)->default('players');
            $table->jsonb('mapping'); // {"campo_sistema": "Coluna da planilha"}
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('clubs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('short_name')->nullable();
            $table->string('country', 60)->default('Brasil');
            $table->string('state', 2)->nullable();
            $table->string('cbf_code')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['name', 'country']);
        });

        Schema::create('competitions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('country', 60)->default('Brasil');
            $table->string('scope', 20)->default('nacional'); // nacional, estadual, continental, regional
            $table->string('tier_label')->nullable(); // ex.: "Série B", "1ª divisão"
            $table->string('code_cbf')->nullable();
            $table->boolean('is_priority')->default(false);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['name', 'country']);
        });

        Schema::create('players', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name'); // nome de jogo
            $table->string('full_name')->nullable();
            $table->jsonb('aliases')->nullable();
            $table->date('birth_date')->nullable();
            $table->string('nationality', 60)->nullable();
            $table->string('second_nationality', 60)->nullable();
            $table->string('cbf_id', 32)->nullable()->unique(); // chave mestra quando existir
            $table->string('preferred_foot', 20)->default('nao_informado');
            $table->unsignedSmallInteger('height_cm')->nullable();
            $table->string('primary_position', 8)->nullable();
            $table->jsonb('positions')->nullable(); // códigos de posição
            $table->foreignUuid('current_club_id')->nullable()->constrained('clubs')->nullOnDelete();
            $table->string('agent_name')->nullable();
            $table->string('sofascore_url')->nullable();
            $table->string('wyscout_url')->nullable();
            $table->string('transfermarkt_url')->nullable();
            $table->string('ogol_url')->nullable();
            $table->string('other_url')->nullable();
            $table->string('data_origin', 20)->default('manual'); // manual, importacao, provedor, sintetico
            $table->foreignUuid('import_batch_id')->nullable()->constrained('import_batches')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index('name');
            $table->index('birth_date');
        });

        Schema::create('player_external_ids', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('player_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 40);
            $table->string('external_id', 120);
            $table->string('url')->nullable();
            $table->boolean('verified')->default(false);
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['provider', 'external_id']);
        });

        Schema::create('roster_spells', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('player_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('club_id')->constrained()->cascadeOnDelete();
            $table->date('started_on')->nullable();
            $table->date('ended_on')->nullable();
            $table->string('spell_type', 20)->default('definitivo'); // definitivo, emprestimo, base
            $table->string('source_url')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('contract_observations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('player_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('club_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 20)->default('desconhecido'); // vigente, emprestado, livre, desconhecido
            $table->date('contract_end')->nullable();
            $table->boolean('loan_possible')->nullable();
            $table->string('confirmation', 20)->default('nao_verificado'); // confirmado, estimado, rumor, nao_verificado
            $table->string('source_label')->nullable();
            $table->string('source_url')->nullable();
            $table->date('observed_on');
            $table->text('notes')->nullable();
            $table->foreignUuid('import_batch_id')->nullable()->constrained('import_batches')->nullOnDelete();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('market_observations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('player_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 30); // valor_mercado, pedida_transferencia, salario, luvas, comissao, outro
            $table->decimal('amount', 15, 2)->nullable();
            $table->decimal('amount_max', 15, 2)->nullable();
            $table->string('currency', 3)->default('BRL');
            $table->string('periodicity', 20)->nullable(); // unico, mensal, anual
            $table->string('confirmation', 20)->default('nao_verificado');
            $table->string('source_label')->nullable();
            $table->string('source_url')->nullable();
            $table->date('observed_on');
            $table->text('notes')->nullable();
            $table->foreignUuid('import_batch_id')->nullable()->constrained('import_batches')->nullOnDelete();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Catálogo: papel tático de JOGADOR (não confundir com perfil de acesso de usuário).
        Schema::create('tactical_roles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 40)->unique();
            $table->string('position_group', 60);
            $table->string('name');
            $table->jsonb('aliases')->nullable();
            $table->text('description')->nullable();
            $table->jsonb('compatible_positions')->nullable();
            $table->jsonb('phases')->nullable(); // com_posse, sem_posse, transicao_ofensiva, transicao_defensiva, bola_parada
            $table->text('behaviors')->nullable();
            $table->text('mandatory_requirements')->nullable();
            $table->text('desirable_requirements')->nullable();
            $table->jsonb('criteria')->nullable(); // critérios descritivos para o relatório de scout
            $table->text('evaluation_suggestions')->nullable();
            $table->text('source_url')->nullable();
            $table->string('status', 20)->default('proposta'); // proposta, ativo, arquivado
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('specialties', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 40)->unique();
            $table->string('name');
            $table->jsonb('aliases')->nullable();
            $table->text('description')->nullable();
            $table->jsonb('compatible_positions')->nullable(); // vazio = qualquer posição
            $table->text('evaluation_suggestions')->nullable();
            $table->string('status', 20)->default('proposta');
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('catalog_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuidMorphs('versionable');
            $table->unsignedInteger('version');
            $table->jsonb('snapshot');
            $table->text('change_note')->nullable();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['versionable_type', 'versionable_id', 'version']);
        });

        Schema::create('recruitment_needs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('title');
            $table->string('position', 8);
            $table->foreignUuid('tactical_role_id')->nullable()->constrained()->nullOnDelete();
            $table->string('preferred_foot', 20)->nullable();
            $table->unsignedSmallInteger('age_min')->nullable();
            $table->unsignedSmallInteger('age_max')->nullable();
            $table->string('target_level', 20)->nullable(); // nível mínimo desejado A/B/C/D
            $table->jsonb('mandatory_requirements')->nullable(); // lista de textos
            $table->jsonb('desirable_requirements')->nullable();
            $table->string('horizon', 30)->default('proxima_janela');
            $table->string('window_label')->nullable();
            $table->string('priority', 10)->default('media');
            $table->string('status', 20)->default('aberta');
            $table->decimal('budget_transfer_max', 15, 2)->nullable(); // null = não informado
            $table->decimal('budget_salary_max', 15, 2)->nullable();
            $table->string('budget_currency', 3)->default('BRL');
            $table->text('context')->nullable();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('recruitment_need_specialty', function (Blueprint $table) {
            $table->foreignUuid('recruitment_need_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('specialty_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_mandatory')->default(false);
            $table->primary(['recruitment_need_id', 'specialty_id']);
        });

        Schema::create('prospect_stages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('key', 40)->unique();
            $table->string('name');
            $table->unsignedSmallInteger('sort');
            $table->boolean('is_shortlist')->default(false);
            $table->boolean('is_terminal')->default(false);
            $table->string('color', 20)->default('gray');
            $table->timestamps();
        });

        Schema::create('prospect_cases', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('recruitment_need_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('player_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('prospect_stage_id')->constrained();
            $table->string('priority', 10)->default('media');
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('due_on')->nullable();
            $table->string('next_action')->nullable();
            $table->unsignedSmallInteger('shortlist_rank')->nullable();
            $table->text('justification')->nullable();
            $table->text('archived_reason')->nullable();
            $table->timestamps();
            $table->unique(['recruitment_need_id', 'player_id']);
        });

        Schema::create('stage_history', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('prospect_case_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('from_stage_id')->nullable()->constrained('prospect_stages')->nullOnDelete();
            $table->foreignUuid('to_stage_id')->constrained('prospect_stages');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('reason')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('scouting_reports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('player_id')->constrained()->cascadeOnDelete();
            $table->foreignId('author_id')->constrained('users');
            $table->foreignUuid('recruitment_need_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('tactical_role_id')->nullable()->constrained()->nullOnDelete();
            $table->string('level', 20)->default('nao_observado'); // A, B, C, D, nao_observado
            $table->string('assessment_type', 20)->default('observado'); // observado, potencial
            $table->jsonb('criteria_levels')->nullable(); // {"critério": "A|B|C|D|nao_observado"}
            $table->string('source_type', 20)->default('video'); // video, presencial, dados, misto
            $table->date('match_date')->nullable();
            $table->string('match_label')->nullable(); // ex.: "Remo x Paysandu"
            $table->foreignUuid('competition_id')->nullable()->constrained()->nullOnDelete();
            $table->string('formation', 20)->nullable();
            $table->unsignedSmallInteger('minutes_observed')->nullable();
            $table->text('context')->nullable();
            $table->text('positives')->nullable();
            $table->text('negatives')->nullable();
            $table->text('open_questions')->nullable();
            $table->string('confidence', 10)->default('media');
            $table->string('verdict', 20)->default('inconclusivo'); // avancar, monitorar, descartar, inconclusivo
            $table->string('status', 20)->default('rascunho'); // rascunho, submetido
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();
            $table->index(['player_id', 'status']);
        });

        Schema::create('video_references', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('player_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('scouting_report_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('url');
            $table->string('platform', 30)->nullable(); // wyscout, youtube, outro
            $table->string('match_label')->nullable();
            $table->string('starts_at', 12)->nullable(); // mm:ss ou hh:mm:ss
            $table->string('ends_at', 12)->nullable();
            $table->string('tag')->nullable();
            $table->string('polarity', 10)->default('neutro'); // positivo, negativo, neutro
            $table->text('note')->nullable();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('player_role_assessments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('player_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('tactical_role_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('role_version')->default(1);
            $table->string('kind', 20)->default('observado'); // observado, potencial
            $table->string('level', 20)->default('nao_observado');
            $table->string('confidence', 10)->default('media');
            $table->string('phase', 30)->nullable();
            $table->string('period_label')->nullable(); // ex.: "Série C 2026"
            $table->text('evidence')->nullable();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('player_specialty_assessments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('player_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('specialty_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('specialty_version')->default(1);
            $table->string('level', 20)->default('nao_observado');
            $table->unsignedSmallInteger('sample_attempts')->nullable(); // denominador (ex.: pênaltis enfrentados)
            $table->unsignedSmallInteger('sample_successes')->nullable();
            $table->string('period_label')->nullable();
            $table->string('confidence', 10)->default('media');
            $table->text('evidence')->nullable();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('audit_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 40);
            $table->string('auditable_type')->nullable();
            $table->string('auditable_id', 64)->nullable();
            $table->jsonb('changes')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['auditable_type', 'auditable_id']);
        });
    }

    public function down(): void
    {
        foreach ([
            'audit_events', 'player_specialty_assessments', 'player_role_assessments', 'video_references',
            'scouting_reports', 'stage_history', 'prospect_cases', 'prospect_stages', 'recruitment_need_specialty',
            'recruitment_needs', 'catalog_versions', 'specialties', 'tactical_roles', 'market_observations',
            'contract_observations', 'roster_spells', 'player_external_ids', 'players', 'competitions', 'clubs',
            'import_mapping_profiles', 'import_batches',
        ] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['role', 'is_active', 'can_view_financial', 'app_authentication_secret', 'app_authentication_recovery_codes', 'last_login_at']);
        });
    }
};
