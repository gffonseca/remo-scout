<?php

namespace App\Filament\Pages;

use App\Filament\Resources\ImportBatches\ImportBatchResource;
use App\Models\ImportMappingProfile;
use App\Services\Import\PlayerImporter;
use App\Services\Import\SpreadsheetReader;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Livewire\WithFileUploads;
use UnitEnum;

/**
 * Importador em 3 passos: upload → mapeamento de colunas (com perfis salvos) → prévia → confirmação.
 * Nenhum dado é gravado antes da confirmação. Lote fica reversível no histórico.
 */
class ImportarPlanilha extends Page
{
    use WithFileUploads;

    protected string $view = 'filament.pages.importar-planilha';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUpTray;

    protected static string|UnitEnum|null $navigationGroup = 'Dados e importação';

    protected static ?string $navigationLabel = 'Importar planilha';

    protected static ?string $title = 'Importar planilha de atletas';

    protected static ?int $navigationSort = 1;

    public $upload = null;

    public int $step = 1;

    public ?string $filename = null;

    public array $headers = [];

    public array $mapping = [];

    public ?string $profileId = null;

    public string $newProfileName = '';

    public ?string $cacheKey = null;

    public array $summary = [];

    public array $previewRows = [];

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->canEdit();
    }

    public function getFields(): array
    {
        $fields = PlayerImporter::FIELDS;
        if (! auth()->user()->canViewFinancial()) {
            unset($fields['market_value'], $fields['salary_min'], $fields['salary_max'], $fields['asking_price']);
        }

        return array_map(fn ($f) => $f[0], $fields);
    }

    public function getProfiles(): array
    {
        return ImportMappingProfile::query()->orderBy('name')->pluck('name', 'id')->all();
    }

    public function updatedUpload(): void
    {
        $this->validate(['upload' => 'required|file|max:10240|mimes:csv,txt,xlsx']);
        try {
            $data = SpreadsheetReader::read($this->upload->getRealPath(), $this->upload->getClientOriginalName(), config('scout.import_max_rows'));
        } catch (\Throwable $e) {
            $this->upload = null;
            Notification::make()->danger()->title('Não foi possível ler o arquivo')->body(Str::limit($e->getMessage(), 250))->send();

            return;
        }
        $this->filename = $this->upload->getClientOriginalName();
        $this->headers = $data['headers'];
        $this->cacheKey = 'import:'.auth()->id().':'.Str::uuid();
        Cache::put($this->cacheKey, $data['rows'], now()->addHours(2));
        $this->mapping = PlayerImporter::guessMapping($this->headers);
        $this->step = 2;
    }

    public function applyProfile(): void
    {
        $p = $this->profileId ? ImportMappingProfile::find($this->profileId) : null;
        if (! $p) {
            return;
        }
        foreach ($p->mapping as $field => $col) {
            $this->mapping[$field] = in_array($col, $this->headers, true) ? $col : null;
        }
        Notification::make()->success()->title('Perfil aplicado: '.$p->name)->send();
    }

    public function saveProfile(): void
    {
        $name = trim($this->newProfileName);
        if ($name === '') {
            Notification::make()->warning()->title('Dê um nome ao perfil')->send();

            return;
        }
        ImportMappingProfile::updateOrCreate(['name' => $name, 'entity' => 'players'], ['mapping' => array_filter($this->mapping), 'created_by' => auth()->id()]);
        $this->newProfileName = '';
        Notification::make()->success()->title('Perfil de mapeamento salvo')->send();
    }

    public function preview(): void
    {
        if (empty($this->mapping['name'])) {
            Notification::make()->danger()->title('Mapeie a coluna do nome de jogo')->send();

            return;
        }
        $rows = Cache::get($this->cacheKey);
        if ($rows === null) {
            Notification::make()->danger()->title('Sessão de importação expirou — envie o arquivo novamente')->send();
            $this->resetImport();

            return;
        }
        $plan = PlayerImporter::plan($rows, $this->mapping, auth()->user()->canViewFinancial());
        Cache::put($this->cacheKey.':plan', $plan, now()->addHours(2));
        $this->summary = collect($plan)->countBy('action')->all() + ['total' => count($plan)];
        $this->previewRows = collect($plan)->take(200)->map(fn ($p) => [
            'row' => $p['row'], 'action' => $p['action'], 'message' => $p['message'],
            'name' => $p['data']['name'] ?? '', 'position' => $p['data']['primary_position'] ?? '', 'birth' => $p['data']['birth_date'] ?? '',
            'club' => $p['data']['__club'] ?? '',
        ])->all();
        $this->step = 3;
    }

    public function commit(): void
    {
        $plan = Cache::get($this->cacheKey.':plan');
        if ($plan === null) {
            Notification::make()->danger()->title('Prévia expirou — gere novamente')->send();

            return;
        }
        $batch = PlayerImporter::commit($plan, $this->filename, $this->mapping, auth()->id());
        Cache::forget($this->cacheKey);
        Cache::forget($this->cacheKey.':plan');
        Notification::make()->success()->title('Importação concluída')
            ->body("{$batch->rows_created} criados, {$batch->rows_updated} atualizados, {$batch->rows_skipped} ignorados, {$batch->rows_failed} com erro.")->send();
        $this->redirect(ImportBatchResource::getUrl('index'));
    }

    public function resetImport(): void
    {
        $this->reset(['upload', 'step', 'filename', 'headers', 'mapping', 'cacheKey', 'summary', 'previewRows']);
        $this->step = 1;
    }

    public function downloadTemplate()
    {
        $cols = ['Nome', 'Nome completo', 'Nascimento', 'Nacionalidade', 'ID CBF', 'Posição', 'Outras posições', 'Pé', 'Altura', 'Clube', 'Fim de contrato', 'Situação', 'Empresário', 'Sofascore', 'Wyscout', 'Transfermarkt', 'Observações'];
        $example = ['EXEMPLO SINTÉTICO — apagar', '', '15/03/2001', 'Brasil', '', 'LE', 'ALE, ME', 'Esquerdo', '1,78', 'Clube Exemplo FC', '12/2026', 'Vigente', '', '', '', '', 'Linha de exemplo; não é atleta real'];

        return response()->streamDownload(function () use ($cols, $example) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $cols, ';');
            fputcsv($out, $example, ';');
            fclose($out);
        }, 'modelo-importacao-remo-scout.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
