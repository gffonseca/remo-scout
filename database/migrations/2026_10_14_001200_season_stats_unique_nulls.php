<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Revisão do Bloco 2: o unique de player_season_stats tratava club_name NULL como distinto e permitia linhas repetidas
 * (ex.: após mescla). Remove duplicatas (mantém a coleta mais recente) e recria o índice com NULLS NOT DISTINCT (PG 15+).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            delete from player_season_stats p using (
                select id, row_number() over (partition by player_id, provider, competition_key, season, club_name
                                              order by fetched_at desc nulls last, updated_at desc, id desc) as rn
                from player_season_stats
            ) d where p.id = d.id and d.rn > 1
            SQL);
        DB::statement('alter table player_season_stats drop constraint if exists player_season_stats_player_id_provider_competition_key_season_c');
        DB::statement('drop index if exists player_season_stats_player_id_provider_competition_key_season_c');
        foreach (DB::select("select conname from pg_constraint where conrelid = 'player_season_stats'::regclass and contype = 'u'") as $c) {
            DB::statement('alter table player_season_stats drop constraint "'.$c->conname.'"');
        }
        DB::statement('alter table player_season_stats add constraint player_season_stats_identity_unique unique nulls not distinct (player_id, provider, competition_key, season, club_name)');
    }

    public function down(): void
    {
        DB::statement('alter table player_season_stats drop constraint if exists player_season_stats_identity_unique');
        DB::statement('alter table player_season_stats add constraint player_season_stats_identity_unique unique (player_id, provider, competition_key, season, club_name)');
    }
};
