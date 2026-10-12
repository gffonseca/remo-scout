<?php

namespace App\Filament\Resources\ImportBatches;

use App\Models\ImportBatch;
use App\Services\Import\PlayerImporter;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class ImportBatchResource extends Resource
{
    protected static ?string $model = ImportBatch::class;

    protected static ?string $slug = 'importacoes';

    protected static bool $hasTitleCaseModelLabel = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQueueList;

    protected static string|UnitEnum|null $navigationGroup = 'Dados e importação';

    protected static ?string $modelLabel = 'lote de importação';

    protected static ?string $pluralModelLabel = 'Histórico de importações';

    protected static ?int $navigationSort = 2;

    public static function infolist(Schema $schema): Schema
    {
        return $schema->columns(4)->components([
            TextEntry::make('original_filename')->label('Arquivo'),
            TextEntry::make('user.name')->label('Por'),
            TextEntry::make('status')->label('Status')->badge(),
            TextEntry::make('completed_at')->label('Concluído')->dateTime('d/m/Y H:i')->placeholder('—'),
            TextEntry::make('rows_total')->label('Linhas'),
            TextEntry::make('rows_created')->label('Criados'),
            TextEntry::make('rows_updated')->label('Atualizados'),
            TextEntry::make('rows_skipped')->label('Ignorados/falhas')->state(fn (ImportBatch $b) => $b->rows_skipped + $b->rows_failed),
            TextEntry::make('reverted_at')->label('Revertido em')->dateTime('d/m/Y H:i')->placeholder('—'),
            TextEntry::make('revert_summary')->label('Resultado da reversão')->columnSpan(3)->placeholder('—')
                ->state(fn (ImportBatch $b) => $b->revert_report ? "{$b->revert_report['restored']} campo(s) restaurado(s), {$b->revert_report['removed']} atleta(s) e ".($b->revert_report['removed_other'] ?? 0).' clube(s)/empresário(s) removido(s), '.count($b->revert_report['conflicts']).' conflito(s)' : null),
            RepeatableEntry::make('revert_report.conflicts')->label('Conflitos da reversão (mantidos)')->columnSpanFull()->columns(4)
                ->visible(fn (ImportBatch $b) => filled($b->revert_report['conflicts'] ?? null))->schema([
                    TextEntry::make('entity')->label('Tipo'),
                    TextEntry::make('label')->label('Registro'),
                    TextEntry::make('field')->label('Campo')->placeholder('—'),
                    TextEntry::make('reason')->label('Motivo'),
                ]),
            RepeatableEntry::make('errors')->label('Ocorrências')->columnSpanFull()->columns(3)->schema([
                TextEntry::make('row')->label('Linha'),
                TextEntry::make('name')->label('Atleta')->placeholder('—'),
                TextEntry::make('message')->label('Mensagem'),
            ])->placeholder('Sem ocorrências'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')->label('Data')->dateTime('d/m/Y H:i'),
                TextColumn::make('original_filename')->label('Arquivo')->limit(40),
                TextColumn::make('user.name')->label('Por'),
                TextColumn::make('status')->label('Status')->badge()
                    ->color(fn ($state) => ['concluido' => 'success', 'parcial' => 'warning', 'falhou' => 'danger', 'revertido' => 'gray'][$state] ?? 'gray'),
                TextColumn::make('rows_total')->label('Linhas')->alignEnd(),
                TextColumn::make('rows_created')->label('Criados')->alignEnd(),
                TextColumn::make('rows_updated')->label('Atualiz.')->alignEnd(),
                TextColumn::make('rows_failed')->label('Falhas')->alignEnd(),
            ])
            ->recordActions([
                ViewAction::make(),
                Action::make('revert')->label('Reverter')->icon(Heroicon::OutlinedArrowUturnLeft)->color('danger')
                    ->authorize('revert')->requiresConfirmation()->visible(fn (ImportBatch $record) => ! $record->reverted_at)
                    ->modalDescription(fn (ImportBatch $record) => $record->has_change_log
                        ? 'Desfaz o lote uma única vez: restaura campos preenchidos em atletas existentes, remove o que o lote criou e as observações dele. O que tiver sido editado ou usado depois é mantido e listado como conflito.'
                        : 'Lote anterior à trilha de alterações: remove atletas criados que ainda não receberam trabalho e as observações do lote. Campos preenchidos em atletas existentes NÃO podem ser restaurados.')
                    ->action(function (ImportBatch $record) {
                        try {
                            $r = PlayerImporter::revert($record, auth()->id());
                        } catch (\RuntimeException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();

                            return;
                        }
                        $n = Notification::make()->title("Revertido: {$r['restored']} campo(s) restaurado(s), {$r['removed']} atleta(s) e {$r['removed_other']} clube(s)/empresário(s) removido(s).");
                        $r['conflicts'] === []
                            ? $n->success()
                            : $n->warning()->body(count($r['conflicts']).' conflito(s) mantido(s): '.collect($r['conflicts'])->take(5)
                                ->map(fn ($c) => $c['label'].($c['field'] ? " ({$c['field']})" : '').' — '.$c['reason'])->implode('; ')
                                .(count($r['conflicts']) > 5 ? '…' : '').' Lista completa no relatório do lote.')->persistent();
                        $n->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListImportBatches::route('/')];
    }
}
