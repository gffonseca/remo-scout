<?php

namespace App\Filament\Pages;

use App\Models\Club;
use App\Models\OddsMatch;
use App\Sources\FootballData;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;
use UnitEnum;

/** Força dos clubes da Série A segundo o mercado de apostas (football-data.co.uk): contexto, não avaliação de atleta. */
class ForcaDosClubes extends Page
{
    protected string $view = 'filament.pages.forca-dos-clubes';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static string|UnitEnum|null $navigationGroup = 'Dados e importação';

    protected static ?string $navigationLabel = 'Força dos clubes (mercado)';

    protected static ?string $title = 'Força dos clubes segundo o mercado';

    protected static ?int $navigationSort = 5;

    protected static ?string $slug = 'forca-dos-clubes';

    #[Url]
    public ?string $season = null;

    public function mount(): void
    {
        $this->season ??= (string) OddsMatch::max('season') ?: null;
    }

    public function seasons(): array
    {
        return OddsMatch::query()->distinct()->orderByDesc('season')->pluck('season')->all();
    }

    public function rows(): \Illuminate\Support\Collection
    {
        if (! $this->season) {
            return collect();
        }
        $rows = FootballData::clubStrength($this->season);
        $clubs = Club::whereIn('id', $rows->pluck('club_id'))->get()->keyBy('id');

        return $rows->map(fn ($r) => tap($r, fn ($r) => $r->club = $clubs[$r->club_id] ?? null));
    }

    public function updatedAt(): ?string
    {
        $at = OddsMatch::max('fetched_at');

        return $at ? \Illuminate\Support\Carbon::parse($at)->timezone(config('scout.display_timezone'))->format('d/m/Y H:i') : null;
    }
}
