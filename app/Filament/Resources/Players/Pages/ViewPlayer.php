<?php

namespace App\Filament\Resources\Players\Pages;

use App\Filament\Resources\Players\PlayerResource;
use App\Filament\Resources\ScoutingReports\ScoutingReportResource;
use App\Models\Player;
use App\Models\ProspectCase;
use App\Models\RecruitmentNeed;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewPlayer extends ViewRecord
{
    protected static string $resource = PlayerResource::class;

    public function getTitle(): string
    {
        return $this->getRecord()->name;
    }

    protected function getHeaderActions(): array
    {
        /** @var Player $p */
        $p = $this->getRecord();

        return [
            Action::make('report')->label('Novo relatório de scout')->icon(Heroicon::OutlinedClipboardDocumentCheck)
                ->url(ScoutingReportResource::getUrl('create', ['player' => $p->id]))
                ->visible(fn () => auth()->user()->canEdit()),
            Action::make('addToNeed')->label('Adicionar a necessidade')->icon(Heroicon::OutlinedPlusCircle)->color('gray')
                ->visible(fn () => auth()->user()->canEdit())
                ->schema([
                    Select::make('need_id')->label('Necessidade')->required()
                        ->options(RecruitmentNeed::query()->where('status', 'aberta')->orderBy('title')->pluck('title', 'id')),
                    Textarea::make('reason')->label('Por que entra no radar?')->rows(2),
                ])
                ->action(function (array $data) use ($p) {
                    $case = ProspectCase::open(RecruitmentNeed::findOrFail($data['need_id']), $p, auth()->id(), $data['reason'] ?? null);
                    Notification::make()->success()->title($case->wasRecentlyCreated ? 'Adicionado ao quadro de prospecção' : 'Atleta já estava nesta necessidade')->send();
                }),
            Action::make('dossie')->label('Dossiê PDF')->icon(Heroicon::OutlinedDocumentArrowDown)->color('gray')
                ->url(route('dossie.player', $p))->openUrlInNewTab(),
            ActionGroup::make([
                Action::make('news')->label('Pesquisar notícias do atleta')->icon(Heroicon::OutlinedNewspaper)
                    ->url(fn () => 'https://www.google.com/search?tbm=nws&q='.urlencode('"'.$p->name.'"'.($p->currentClub ? ' '.$p->currentClub->name : '').' futebol'))
                    ->openUrlInNewTab()
                    ->tooltip('Abre a busca no navegador. Não há síntese automática validada; registre manualmente o que for relevante.'),
                Action::make('sofascoreSearch')->label('Buscar no Sofascore')->icon(Heroicon::OutlinedMagnifyingGlass)
                    ->url(fn () => $p->sofascore_url ?: 'https://www.sofascore.com/pt/search?q='.urlencode($p->name))->openUrlInNewTab(),
                Action::make('tmSearch')->label('Buscar no Transfermarkt')->icon(Heroicon::OutlinedMagnifyingGlass)
                    ->url(fn () => $p->transfermarkt_url ?: 'https://www.transfermarkt.com.br/schnellsuche/ergebnis/schnellsuche?query='.urlencode($p->name))->openUrlInNewTab(),
                Action::make('ogolSearch')->label('Buscar no ogol')->icon(Heroicon::OutlinedMagnifyingGlass)
                    ->url(fn () => $p->ogol_url ?: 'https://www.ogol.com.br/pesquisa?search_txt='.urlencode($p->name))->openUrlInNewTab(),
            ])->label('Pesquisar fora')->icon(Heroicon::OutlinedGlobeAlt)->button()->color('gray'),
            EditAction::make()->label('Editar'),
        ];
    }
}
