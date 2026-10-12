<?php

namespace App\Filament\Pages;

use App\Models\Player;
use App\Models\RecruitmentNeed;
use App\Services\Comparator;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\Url;
use UnitEnum;

/** Comparador fixo: até 5 atletas, mesma base de estatística, nível observado por papel e fase. */
class Comparador extends Page
{
    protected string $view = 'filament.pages.comparador';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static string|UnitEnum|null $navigationGroup = 'Jogadores';

    protected static ?string $navigationLabel = 'Comparador';

    protected static ?string $title = 'Comparador';

    protected static ?int $navigationSort = 2;

    protected static ?string $slug = 'comparador';

    #[Url]
    public ?string $base = null;

    #[Url]
    public ?string $need = null;

    public static function getNavigationBadge(): ?string
    {
        $n = auth()->check() ? DB::table('compare_pins')->where('user_id', auth()->id())->count() : 0;

        return $n ? (string) $n : null;
    }

    public function data(): array
    {
        $players = Comparator::players(auth()->user());
        $bases = Comparator::statBases($players);
        $base = $this->base && isset($bases[$this->base]) ? $this->base : array_key_first($bases);

        return [
            'players' => $players, 'grid' => Comparator::roleGrid($players), 'bases' => $bases, 'base' => $base,
            'stats' => Comparator::statRows($players, $base),
            'needs' => RecruitmentNeed::whereIn('status', ['aberta', 'em_andamento'])->orderBy('title')->pluck('title', 'id')->all(),
            'needModel' => $this->need && Str::isUuid($this->need) ? RecruitmentNeed::find($this->need) : null,
            'financial' => auth()->user()->canViewFinancial(),
        ];
    }

    public function unpin(string $playerId): void
    {
        $r = Comparator::toggle(auth()->user(), Player::findOrFail($playerId));
        Notification::make()->title($r['message'])->success()->send();
    }
}
