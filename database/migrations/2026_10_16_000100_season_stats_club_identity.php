<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Clube da estatística: clube canônico (club_id), ID do clube na fonte (club_external_id) e nome como a fonte o rotula
 * (club_source_name) ficam separados. ID externo nunca é nome.
 *
 * Só reestrutura, sem perda: o código do Transfermarkt gravado no lugar do nome passa para club_external_id; o nome que
 * já existia vira club_source_name. Vincular ao clube canônico é o backfill `scout:stats-clubs` (prévia + reversão).
 * Exceção: totais montados a partir de partidas (appearances) já usavam o nome do clube canônico; ganham club_id
 * quando esse nome identifica um único clube.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('player_season_stats', function (Blueprint $t) {
            $t->foreignUuid('club_id')->nullable()->after('club_name')->constrained('clubs')->nullOnDelete();
            $t->string('club_external_id', 60)->nullable()->after('club_id');
            $t->string('club_source_name')->nullable()->after('club_external_id');
            $t->string('club_key', 200)->default('')->after('club_source_name'); // identidade da linha (clube distinto na mesma temporada)
        });

        DB::statement(<<<'SQL'
            update player_season_stats set club_external_id = club_name, club_name = null
            where provider = 'transfermarkt' and club_name ~ '^[0-9]+$'
            SQL);
        DB::statement('update player_season_stats set club_source_name = club_name where club_name is not null');
        // totais de partidas: club_name era clubs.name no momento da consolidação
        DB::statement(<<<'SQL'
            update player_season_stats s set club_id = c.id
            from clubs c
            where s.provider in (select distinct provider from appearances) and s.club_name = c.name
              and (select count(*) from clubs c2 where c2.name = c.name) = 1
            SQL);
        // CBF: o total publicado é do clube de inscrição; ID do clube na CBF vem do registro do atleta (mesma chave que a ingestão usa)
        DB::statement(<<<'SQL'
            update player_season_stats s set club_external_id = ca.club_cbf_id, club_id = coalesce(s.club_id, ca.club_id)
            from players p join cbf_athletes ca on ca.cbf_id = p.cbf_id
            where s.provider = 'cbf' and s.player_id = p.id and ca.club_cbf_id is not null
              and s.club_name is not distinct from ca.club_name
            SQL);
        DB::statement(<<<'SQL'
            update player_season_stats set club_key = case
                when club_external_id is not null then 'ext:' || club_external_id
                when club_id is not null then 'club:' || club_id::text
                when club_name is not null then 'name:' || club_name
                else '' end
            SQL);

        $dups = DB::scalar('select count(*) from (select 1 from player_season_stats group by player_id, provider, competition_key, season, club_key having count(*) > 1) d');
        if ($dups > 0) {
            // não apaga nada sozinho: aborta (a transação desfaz) para revisão manual
            throw new RuntimeException("{$dups} grupo(s) de estatística ficariam duplicados na nova chave de clube; revisar antes de migrar.");
        }
        DB::statement('alter table player_season_stats drop constraint if exists player_season_stats_identity_unique');
        DB::statement('alter table player_season_stats add constraint player_season_stats_identity_unique unique (player_id, provider, competition_key, season, club_key)');
        Schema::table('player_season_stats', fn (Blueprint $t) => $t->index(['provider', 'club_external_id']));
    }

    /**
     * Reversão com perda possível: linhas gravadas pelo código novo que diferem só pelo clube (duas passagens com o mesmo rótulo)
     * não cabem na chave antiga; nesse caso o down() aborta e a volta é pelo backup do pré-deploy.
     */
    public function down(): void
    {
        DB::statement('alter table player_season_stats drop constraint if exists player_season_stats_identity_unique');
        DB::statement('update player_season_stats set club_name = coalesce(club_name, club_source_name, club_external_id) where club_name is null');
        Schema::table('player_season_stats', function (Blueprint $t) {
            $t->dropIndex(['provider', 'club_external_id']);
            $t->dropConstrainedForeignId('club_id');
            $t->dropColumn(['club_external_id', 'club_source_name', 'club_key']);
        });
        DB::statement('alter table player_season_stats add constraint player_season_stats_identity_unique unique nulls not distinct (player_id, provider, competition_key, season, club_name)');
    }
};
