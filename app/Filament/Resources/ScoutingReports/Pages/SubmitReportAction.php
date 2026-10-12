<?php

namespace App\Filament\Resources\ScoutingReports\Pages;

use App\Enums\ReportStatus;
use App\Filament\Resources\ScoutingReports\ScoutingReportResource;
use App\Models\ScoutingReport;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

class SubmitReportAction
{
    public static function make(): Action
    {
        return Action::make('submit')
            ->label('Submeter relatório')
            ->icon(Heroicon::OutlinedPaperAirplane)
            ->color('success')
            ->requiresConfirmation()
            ->modalDescription('Depois de submetido, o relatório fica visível aos demais e só o coordenador pode corrigi-lo.')
            ->visible(fn (ScoutingReport $record) => $record->status === ReportStatus::Rascunho && $record->author_id === auth()->id())
            ->action(function (ScoutingReport $record, $livewire) {
                $record->submit();
                Notification::make()->success()->title('Relatório submetido')->send();
                $livewire->redirect(ScoutingReportResource::getUrl('view', ['record' => $record]));
            });
    }
}
