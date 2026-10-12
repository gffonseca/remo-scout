<?php

namespace App\Filament\Support;

use App\Enums\CatalogStatus;
use App\Services\CatalogTransfer;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class CatalogActions
{
    public static function exportJson(): Action
    {
        return Action::make('exportJson')->label('Exportar JSON')->icon(Heroicon::OutlinedArrowDownTray)->color('gray')
            ->action(fn () => response()->streamDownload(
                fn () => print (json_encode(CatalogTransfer::export(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
                'catalogo-remo-scout-'.now()->format('Ymd-His').'.json',
                ['Content-Type' => 'application/json'],
            ));
    }

    public static function importJson(): Action
    {
        return Action::make('importJson')->label('Importar JSON')->icon(Heroicon::OutlinedArrowUpTray)->color('gray')
            ->visible(fn () => auth()->user()->isCoordinator())
            ->modalDescription('Atualiza por código (id). Itens existentes ganham nova versão; nada é apagado.')
            ->schema([
                FileUpload::make('file')->label('Arquivo JSON')->disk('local')->directory('catalog-imports')
                    ->acceptedFileTypes(['application/json', 'text/plain'])->maxSize(2048)->required(),
            ])
            ->action(function (array $data) {
                $path = $data['file'];
                try {
                    $payload = json_decode(Storage::disk('local')->get($path), true, flags: JSON_THROW_ON_ERROR);
                    $s = CatalogTransfer::import($payload);
                    Notification::make()->success()->title('Catálogo importado')
                        ->body("Papéis: {$s['roles_created']} novos, {$s['roles_updated']} atualizados. Especialidades: {$s['specialties_created']} novas, {$s['specialties_updated']} atualizadas.")->send();
                } catch (\Throwable $e) {
                    Notification::make()->danger()->title('Falha na importação')->body(Str::limit($e->getMessage(), 300))->send();
                } finally {
                    Storage::disk('local')->delete($path);
                }
            });
    }

    public static function toggleArchive(): Action
    {
        return Action::make('toggleArchive')
            ->label(fn (Model $record) => $record->status === CatalogStatus::Arquivado ? 'Reativar' : 'Arquivar')
            ->icon(fn (Model $record) => $record->status === CatalogStatus::Arquivado ? Heroicon::OutlinedArrowUturnLeft : Heroicon::OutlinedArchiveBox)
            ->color('gray')->requiresConfirmation()
            ->modalDescription('Arquivar esconde o item de novas avaliações, preservando avaliações antigas e histórico.')
            ->visible(fn () => auth()->user()->isCoordinator())
            ->action(function (Model $record) {
                $record->status = $record->status === CatalogStatus::Arquivado ? CatalogStatus::Ativo : CatalogStatus::Arquivado;
                $record->save();
            });
    }

    public static function activate(): Action
    {
        return Action::make('activate')->label('Aprovar (ativo)')->icon(Heroicon::OutlinedCheckBadge)->color('success')
            ->visible(fn (Model $record) => auth()->user()->isCoordinator() && $record->status === CatalogStatus::Proposta)
            ->requiresConfirmation()
            ->action(function (Model $record) {
                $record->status = CatalogStatus::Ativo;
                $record->reviewed_by = auth()->id();
                $record->save();
            });
    }

    public static function cloneAction(string $resource): Action
    {
        return Action::make('clone')->label('Clonar')->icon(Heroicon::OutlinedDocumentDuplicate)->color('gray')
            ->visible(fn () => auth()->user()->isCoordinator())
            ->action(function (Model $record, $livewire) use ($resource) {
                $copy = $record->replicate(['version', 'reviewed_by', 'created_at', 'updated_at']);
                $copy->code = Str::slug($record->code.'-copia-'.Str::lower(Str::random(4)));
                $copy->name = $record->name.' (cópia)';
                $copy->status = CatalogStatus::Proposta;
                $copy->version = 1;
                $copy->save();
                Notification::make()->success()->title('Cópia criada — ajuste nome e critérios')->send();
                $livewire->redirect($resource::getUrl('edit', ['record' => $copy]));
            });
    }
}
