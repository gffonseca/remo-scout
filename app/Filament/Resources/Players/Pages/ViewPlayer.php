<?php

namespace App\Filament\Resources\Players\Pages;

use App\Filament\Pages\Comparador;
use App\Filament\Resources\Players\PlayerResource;
use App\Filament\Resources\ScoutingReports\ScoutingReportResource;
use App\Filament\Resources\Watchlist\WatchlistResource;
use App\Models\DataSource;
use App\Models\IdentityCandidate;
use App\Models\Player;
use App\Models\ProspectCase;
use App\Models\RecruitmentNeed;
use App\Models\WatchlistItem;
use App\Services\Collector\SofascoreTasks;
use App\Services\Comparator;
use App\Services\IdentityReview;
use App\Services\PlayerEnrichment;
use App\Sources\SourceException;
use App\Support\Bid;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

class ViewPlayer extends ViewRecord
{
    protected static string $resource = PlayerResource::class;

    /** Perfil em abas (estilo FM): visão geral + cada registro do atleta numa aba com contagem. */
    public function hasCombinedRelationManagerTabsWithContent(): bool
    {
        return true;
    }

    public function getContentTabLabel(): ?string
    {
        return 'Perfil';
    }

    public function getContentTabIcon(): string|\BackedEnum|Htmlable|null
    {
        return Heroicon::OutlinedUser;
    }

    public function getTitle(): string
    {
        return $this->getRecord()->name;
    }

    public function runSource(string $provider): void
    {
        /** @var Player $p */
        $p = $this->getRecord()->fresh(['currentClub', 'externalIds']);
        if ($provider === 'sofascore' && DataSource::where('key', 'sofascore')->value('transport') === 'coletor_local') {
            $t = SofascoreTasks::request($p, auth()->id());
            match ($t->status) {
                'concluida' => Notification::make()->success()->title($t->result)->send(),
                'falhou' => Notification::make()->danger()->title('Sofascore: '.$t->result)->send(),
                default => Notification::make()->info()->title('Pedido enviado ao coletor do Mac')
                    ->body('O Sofascore recusa servidor; o Mac Mini busca em até ~15 min (precisa estar ligado). Recarregue o perfil depois.')->send(),
            };

            return;
        }
        try {
            $link = $provider === 'transfermarkt' ? PlayerEnrichment::linkTransfermarkt($p) : PlayerEnrichment::linkSofascore($p);
            if (! in_array($link['status'], ['vinculado', 'ja_vinculado'], true)) {
                Notification::make()->warning()->title($link['message'])
                    ->body($link['status'] === 'revisar' ? 'Use "Escolher correspondência pendente" em Fontes externas.' : null)->send();

                return;
            }
            $res = $provider === 'transfermarkt' ? PlayerEnrichment::enrichTransfermarkt($p->fresh()) : PlayerEnrichment::enrichSofascore($p->fresh());
            Notification::make()->success()->title($res['message'])->body('Fonte não oficial — dados marcados com a origem; divergências vão para Revisões de dados.')->send();
            $this->redirect(PlayerResource::getUrl('view', ['record' => $p]));
        } catch (SourceException $e) {
            Notification::make()->danger()->title('Fonte indisponível')->body($e->getMessage())->send();
        }
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
                Action::make('tmSync')->label('Transfermarkt: vincular e atualizar')->icon(Heroicon::OutlinedArrowPath)
                    ->action(fn () => $this->runSource('transfermarkt')),
                Action::make('sfSync')->label('Sofascore: vincular e atualizar estatísticas')->icon(Heroicon::OutlinedArrowPath)
                    ->action(fn () => $this->runSource('sofascore')),
                Action::make('candidates')->label('Escolher correspondência pendente')->icon(Heroicon::OutlinedUserCircle)
                    ->visible(fn () => IdentityCandidate::where('player_id', $p->id)->where('status', 'pendente')->exists())
                    ->schema([
                        Radio::make('candidate')->label('Qual destes é o atleta?')->required()
                            ->options(fn () => IdentityCandidate::where('player_id', $p->id)->where('status', 'pendente')->orderByDesc('score')
                                ->get()->mapWithKeys(fn ($c) => [$c->id => '['.$c->provider.' · '.$c->kindLabel().'] '.$c->label.($c->evidence ? ' — '.$c->evidence : '')])),
                    ])
                    ->action(function (array $data) {
                        $c = IdentityCandidate::findOrFail($data['candidate']);
                        try {
                            Notification::make()->success()->title(IdentityReview::confirm($c, auth()->user()))->send();
                        } catch (\RuntimeException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();

                            return;
                        }
                        if (in_array($c->provider, ['transfermarkt', 'sofascore'], true) && $c->kind === 'escolher') {
                            $this->runSource($c->provider);
                        }
                    }),
            ])->label('Fontes externas')->icon(Heroicon::OutlinedCircleStack)->button()->color('gray')
                ->visible(fn () => auth()->user()->canEdit()),
            ActionGroup::make([
                Action::make('news')->label('Pesquisar notícias do atleta')->icon(Heroicon::OutlinedNewspaper)
                    ->url(fn () => 'https://www.google.com/search?tbm=nws&q='.urlencode('"'.$p->name.'"'.($p->currentClub ? ' '.$p->currentClub->name : '').' futebol'))
                    ->openUrlInNewTab()
                    ->tooltip('Abre a busca no navegador. Não há síntese automática validada; registre manualmente o que for relevante.'),
                Action::make('bid')->label('Abrir no BID da CBF')->icon(Heroicon::OutlinedDocumentCheck)
                    ->url(fn () => Bid::url($p))->openUrlInNewTab()
                    ->tooltip('O BID exige CAPTCHA: consulte no navegador e registre em Contrato › "Registrar consulta ao BID".'),
                Action::make('sofascoreSearch')->label('Buscar no Sofascore')->icon(Heroicon::OutlinedMagnifyingGlass)
                    ->url(fn () => $p->sofascore_url ?: 'https://www.sofascore.com/pt/search?q='.urlencode($p->name))->openUrlInNewTab(),
                Action::make('tmSearch')->label('Buscar no Transfermarkt')->icon(Heroicon::OutlinedMagnifyingGlass)
                    ->url(fn () => $p->transfermarkt_url ?: 'https://www.transfermarkt.com.br/schnellsuche/ergebnis/schnellsuche?query='.urlencode($p->name))->openUrlInNewTab(),
                Action::make('ogolSearch')->label('Buscar no ogol')->icon(Heroicon::OutlinedMagnifyingGlass)
                    ->url(fn () => $p->ogol_url ?: 'https://www.ogol.com.br/pesquisa?search_txt='.urlencode($p->name))->openUrlInNewTab(),
            ])->label('Pesquisar fora')->icon(Heroicon::OutlinedGlobeAlt)->button()->color('gray'),
            Action::make('watch')->icon(Heroicon::OutlinedEye)->color('gray')
                ->label(fn () => WatchlistItem::where('player_id', $p->id)->where('status', 'ativo')->exists() ? 'Na watchlist' : 'Monitorar')
                ->disabled(fn () => WatchlistItem::where('player_id', $p->id)->where('status', 'ativo')->exists())
                ->visible(fn () => auth()->user()->canEdit())
                ->schema(fn () => WatchlistResource::formFields(false))
                ->action(function (array $data) use ($p) {
                    WatchlistItem::create($data + ['player_id' => $p->id, 'created_by' => auth()->id(), 'status' => 'ativo', 'last_seen_club_id' => $p->current_club_id]);
                    Notification::make()->success()->title('Atleta na watchlist')->send();
                }),
            Action::make('pin')->icon(Heroicon::OutlinedScale)->color('gray')
                ->label(fn () => Comparator::isPinned(auth()->user(), $p) ? 'Retirar do comparador' : 'Fixar no comparador')
                ->action(function () use ($p) {
                    $r = Comparator::toggle(auth()->user(), $p);
                    Notification::make()->title($r['message'])->{$r['ok'] ? 'success' : 'warning'}()
                        ->actions([Action::make('open')->label('Abrir comparador')->url(Comparador::getUrl())])->send();
                }),
            EditAction::make()->label('Editar'),
        ];
    }
}
