<?php

namespace App\Filament\Actions;

use App\Models\ProspectCase;
use App\Models\RecruitmentNeed;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;

class AddPlayersToNeedBulkAction
{
    public static function make(): BulkAction
    {
        return BulkAction::make('addToNeed')
            ->label('Adicionar à necessidade')
            ->icon(Heroicon::OutlinedPlusCircle)
            ->visible(fn () => auth()->user()->canEdit())
            ->schema([
                Select::make('need_id')->label('Necessidade')->required()
                    ->options(fn () => RecruitmentNeed::query()->where('status', 'aberta')->orderBy('title')->pluck('title', 'id')),
            ])
            ->action(function (Collection $records, array $data) {
                $need = RecruitmentNeed::findOrFail($data['need_id']);
                $new = 0;
                foreach ($records as $p) {
                    $new += ProspectCase::open($need, $p, auth()->id(), 'Adicionado em lote a partir da busca')->wasRecentlyCreated ? 1 : 0;
                }
                Notification::make()->success()->title("{$new} novo(s) candidato(s); ".($records->count() - $new).' já estavam na necessidade.')->send();
            })
            ->deselectRecordsAfterCompletion();
    }
}
