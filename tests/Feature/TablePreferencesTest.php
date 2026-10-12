<?php

namespace Tests\Feature;

use App\Filament\Resources\Players\Pages\ListPlayers;
use App\Models\Player;
use Livewire\Livewire;

/** Layout — colunas/filtros/ordenação salvos por usuário e restaurados em nova sessão. */
class TablePreferencesTest extends ScoutTestCase
{
    public function test_filter_survives_new_session_and_keys_match_filament(): void
    {
        $u = $this->user('analista');
        $this->actingAs($u);
        Player::create(['name' => 'Prioritário', 'is_priority' => true]);
        $c = Livewire::test(ListPlayers::class)->filterTable('provisional', false)->sortTable('name', 'desc');
        $instance = $c->instance();
        $this->assertSame($instance->getTableFiltersSessionKey(), (fn () => $this->tablePreferenceKeys()[0])->call($instance));
        $this->assertSame($instance->getTableSortSessionKey(), (fn () => $this->tablePreferenceKeys()[1])->call($instance));
        $this->assertSame($instance->getTableColumnsSessionKey(), (fn () => $this->tablePreferenceKeys()[2])->call($instance));

        $saved = $u->fresh()->ui_preferences['tables']['ListPlayers'] ?? [];
        $this->assertSame('name:desc', $saved['1'] ?? null);

        session()->flush(); // outro navegador / nova sessão
        $this->actingAs($u->fresh());
        $again = Livewire::test(ListPlayers::class);
        $this->assertSame('name:desc', $again->get('tableSort'));
    }

    public function test_reset_clears_saved_preferences(): void
    {
        $u = $this->user('analista');
        $this->actingAs($u);
        Livewire::test(ListPlayers::class)->sortTable('name', 'desc')->callAction('resetTablePrefs');
        $this->assertArrayNotHasKey('ListPlayers', $u->fresh()->ui_preferences['tables'] ?? []);
    }
}
