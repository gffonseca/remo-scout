<?php

namespace App\Services;

use App\Filament\Resources\Players\PlayerResource;
use App\Models\ClubSquadEntry;
use App\Models\ScoutingReport;
use App\Models\WatchlistAlert;
use App\Models\WatchlistItem;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Alertas da watchlist, só no sistema (sino), deduplicados por item + chave (o mesmo fato nunca alerta duas vezes).
 * - revisão vencida (uma vez por data marcada);
 * - troca de clube no cadastro (uma vez por clube novo) e elenco de outro clube segundo a Dados Futebol;
 * - relatório novo submetido por outra pessoa (visível ao responsável);
 * - contrato terminando em até 90 dias (uma vez por data de término).
 */
class WatchlistAlerts
{
    public const CONTRACT_DAYS = 90;

    /** @return int alertas novos */
    public static function run(): int
    {
        $n = 0;
        $today = now(config('scout.display_timezone'))->startOfDay();
        foreach (WatchlistItem::with(['player.currentClub', 'player.latestContract', 'owner'])->where('status', 'ativo')->get() as $item) {
            $p = $item->player;
            if (! $p || ! $item->owner?->is_active) {
                continue;
            }
            if ($item->next_review_on && $item->next_review_on->lt($today)) {
                $n += static::alert($item, 'revisao:'.$item->next_review_on->toDateString(), 'revisao',
                    "Revisão de {$p->name} vencida desde ".$item->next_review_on->format('d/m/Y').'.');
            }
            if ($item->club_baseline_at === null) {
                $item->update(['last_seen_club_id' => $p->current_club_id, 'club_baseline_at' => now()]);
            } elseif ($p->current_club_id !== $item->last_seen_club_id) { // nulo = "sem clube", um estado válido
                $n += static::alert($item, 'clube:'.($item->last_seen_club_id ?? 'sem').'>'.($p->current_club_id ?? 'sem').':'.$today->toDateString(), 'clube',
                    "{$p->name} mudou de clube no cadastro: agora ".($p->currentClub?->name ?? 'sem clube').'.');
                $item->update(['last_seen_club_id' => $p->current_club_id]);
            }
            $squad = ClubSquadEntry::with('club')->where('player_id', $p->id)->whereNull('left_at')
                ->where('club_id', '!=', $p->current_club_id ?? '00000000-0000-0000-0000-000000000000')->latest('last_seen_at')->first();
            if ($squad) {
                $n += static::alert($item, 'elenco:'.$squad->club_id, 'elenco',
                    "Dados Futebol lista {$p->name} no elenco do {$squad->club->name} (cadastro diz ".($p->currentClub?->name ?? 'sem clube').').');
            }
            $reports = ScoutingReport::visibleTo($item->owner)->where('player_id', $p->id)->where('status', 'submetido')
                ->where('author_id', '!=', $item->owner_id)->where('submitted_at', '>=', $item->created_at)->with('author')->get();
            foreach ($reports as $r) {
                $n += static::alert($item, 'relatorio:'.$r->id, 'relatorio', "Novo relatório de {$r->author?->name} sobre {$p->name}.");
            }
            $end = $p->latestContract?->contract_end;
            if ($end && $end->gte($today) && $end->lte($today->copy()->addDays(self::CONTRACT_DAYS))) {
                $n += static::alert($item, 'contrato:'.$end->toDateString(), 'contrato', "Contrato de {$p->name} termina em ".$end->format('d/m/Y').' (observação).');
            }
        }

        return $n;
    }

    private static function alert(WatchlistItem $item, string $key, string $kind, string $message): int
    {
        $key = mb_substr($key, 0, 160);
        if (WatchlistAlert::where('watchlist_item_id', $item->id)->where('dedupe_key', $key)->exists()) {
            return 0; // já alertado
        }
        try {
            DB::transaction(fn () => WatchlistAlert::create(['watchlist_item_id' => $item->id, 'dedupe_key' => $key, 'kind' => $kind, 'message' => $message]));
        } catch (UniqueConstraintViolationException) {
            return 0; // corrida entre duas execuções
        }
        Notification::make()->title('Watchlist: '.$item->player->name)->body($message)->icon('heroicon-o-eye')
            ->actions([Action::make('ver')->label('Abrir atleta')->url(PlayerResource::getUrl('view', ['record' => $item->player_id], panel: 'scout'))->markAsRead()])
            ->sendToDatabase($item->owner);

        return 1;
    }
}
