<?php

namespace App\Filament\Resources\Fixtures\Pages;

use App\Filament\Resources\Fixtures\FixtureResource;
use App\Sources\SourceClient;
use App\Sources\SourceException;
use App\Sources\SumulaImporter;
use App\Sources\SumulaParser;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Storage;

class ListFixtures extends ListRecords
{
    protected static string $resource = FixtureResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('sumula')->label('Importar súmula (PDF)')->icon(Heroicon::OutlinedDocumentArrowUp)
                ->visible(fn () => auth()->user()->canEdit())
                ->modalDescription('Súmula On-line da FPF ou da CBF. Lê escalações com ID CBF, substituições, gols e cartões e calcula minutos. Reimportar não duplica.')
                ->schema([
                    Select::make('provider')->label('Origem')->options(['sumula_fpf' => 'Federação Paraense (FPF)', 'sumula_cbf' => 'CBF'])->default('sumula_fpf')->required(),
                    TextInput::make('url')->label('URL do PDF (opcional)')->url()->placeholder('https://www.fpfpara.com.br/files/sumulas/Paysandu%20X%20Remo.pdf'),
                    FileUpload::make('file')->label('ou envie o PDF')->disk('local')->directory('sumulas')->acceptedFileTypes(['application/pdf'])->maxSize(8192),
                ])
                ->action(function (array $data) {
                    try {
                        $tmp = null;
                        if (! empty($data['file'])) {
                            $tmp = Storage::disk('local')->path($data['file']);
                        } elseif (! empty($data['url'])) {
                            $tmp = tempnam(sys_get_temp_dir(), 'sumula');
                            file_put_contents($tmp, SourceClient::for($data['provider'])->getRaw($data['url']));
                        } else {
                            throw new SourceException('Informe a URL ou envie o PDF.');
                        }
                        $r = SumulaImporter::import(SumulaParser::parse(SumulaParser::textFromPdf($tmp)), $data['provider'], $data['url'] ?? null);
                        Notification::make()->success()->title('Súmula importada: '.$r['fixture']->label())
                            ->body("{$r['appearances']} participações · {$r['created']} atletas novos · {$r['linked']} vinculados por ID CBF")->send();
                    } catch (\Throwable $e) {
                        Notification::make()->danger()->title('Falha ao importar súmula')->body(mb_substr($e->getMessage(), 0, 300))->send();
                    } finally {
                        if (! empty($data['file'])) {
                            Storage::disk('local')->delete($data['file']);
                        }
                    }
                }),
        ];
    }
}
