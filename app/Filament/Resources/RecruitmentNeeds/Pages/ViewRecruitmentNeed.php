<?php

namespace App\Filament\Resources\RecruitmentNeeds\Pages;

use App\Filament\Pages\QuadroProspeccao;
use App\Filament\Resources\Players\PlayerResource;
use App\Filament\Resources\RecruitmentNeeds\RecruitmentNeedResource;
use App\Models\RecruitmentNeed;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewRecruitmentNeed extends ViewRecord
{
    protected static string $resource = RecruitmentNeedResource::class;

    public function getTitle(): string
    {
        return $this->getRecord()->title;
    }

    /** Busca por mandato: a necessidade vira filtros na lista de atletas (revisáveis). */
    public static function searchUrl(RecruitmentNeed $n): string
    {
        $filters = ['position' => ['values' => [$n->position->value]]];
        if ($n->age_min || $n->age_max) {
            $filters['age'] = ['min' => $n->age_min, 'max' => $n->age_max, 'missing' => 'incluir'];
        }
        if ($n->preferred_foot) {
            $filters['preferred_foot'] = ['values' => [$n->preferred_foot->value]];
        }

        return PlayerResource::getUrl('index', ['filters' => $filters]);
    }

    protected function getHeaderActions(): array
    {
        $n = $this->getRecord();

        return [
            Action::make('search')->label('Pesquisar candidatos')->icon(Heroicon::OutlinedMagnifyingGlass)
                ->url(RecruitmentNeedResource::getUrl('search', ['record' => $n]))
                ->tooltip('Aplica todos os critérios desta necessidade (papel/fase, nível, especialidades, orçamento) à base; desconhecido = pendência.'),
            Action::make('list')->label('Abrir na lista de jogadores')->icon(Heroicon::OutlinedTableCells)->color('gray')
                ->url(static::searchUrl($n))
                ->tooltip('Filtros básicos (posição, idade, pé) na lista geral, para explorar além da pesquisa.'),
            Action::make('board')->label('Quadro de prospecção')->icon(Heroicon::OutlinedViewColumns)->color('gray')
                ->url(QuadroProspeccao::getUrl(['need' => $n->id])),
            ActionGroup::make([
                Action::make('pdf')->label('Dossiê da shortlist (PDF)')->icon(Heroicon::OutlinedDocumentArrowDown)
                    ->url(route('dossie.shortlist', $n))->openUrlInNewTab(),
                Action::make('xlsx')->label('Exportar shortlist (XLSX)')->icon(Heroicon::OutlinedTableCells)
                    ->url(route('export.shortlist', [$n, 'xlsx'])),
                Action::make('csv')->label('Exportar shortlist (CSV)')->icon(Heroicon::OutlinedDocumentText)
                    ->url(route('export.shortlist', [$n, 'csv'])),
            ])->label('Exportar')->icon(Heroicon::OutlinedArrowDownTray)->button()->color('gray'),
            EditAction::make(),
        ];
    }
}
