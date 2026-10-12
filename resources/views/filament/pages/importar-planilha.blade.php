<x-filament-panels::page>
    <div class="flex flex-wrap items-center gap-2 text-sm">
        @foreach ([1 => 'Arquivo', 2 => 'Mapeamento de colunas', 3 => 'Prévia e confirmação'] as $n => $label)
            <span @class(['px-3 py-1 rounded-full border', 'border-primary-400 text-primary-300 font-semibold' => $step === $n, 'border-gray-600 text-gray-400' => $step !== $n])>{{ $n }}. {{ $label }}</span>
        @endforeach
    </div>

    @if ($step === 1)
        <x-filament::section heading="1. Envie a planilha" description="CSV (separador ; ou ,) ou XLSX. Primeira linha = cabeçalho; só a primeira aba é lida. Nada é gravado antes da confirmação.">
            <div class="space-y-4">
                <input type="file" wire:model="upload" accept=".csv,.xlsx,.txt" class="block w-full text-sm file:me-3 file:rounded-lg file:border-0 file:bg-primary-500 file:px-4 file:py-2 file:font-semibold file:text-gray-950" />
                <div wire:loading wire:target="upload" class="text-sm text-gray-400">Lendo arquivo…</div>
                @error('upload') <p class="text-sm text-danger-400">{{ $message }}</p> @enderror
                <x-filament::button color="gray" icon="heroicon-o-arrow-down-tray" wire:click="downloadTemplate">Baixar modelo (CSV)</x-filament::button>
            </div>
        </x-filament::section>
    @endif

    @if ($step === 2)
        <x-filament::section :heading="'2. Mapeie as colunas de ' . $filename" description="Sugestão automática pelo nome da coluna. Revise; campos sem coluna ficam em branco (não avaliados).">
            <div class="mb-4 flex flex-wrap items-end gap-3">
                <div class="min-w-64">
                    <label class="text-xs text-gray-400">Perfil salvo</label>
                    <x-filament::input.wrapper>
                        <x-filament::input.select wire:model="profileId">
                            <option value="">— escolher —</option>
                            @foreach ($this->getProfiles() as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                </div>
                <x-filament::button color="gray" wire:click="applyProfile">Aplicar perfil</x-filament::button>
                <div class="min-w-64">
                    <label class="text-xs text-gray-400">Salvar este mapeamento como</label>
                    <x-filament::input.wrapper><x-filament::input type="text" wire:model="newProfileName" placeholder="ex.: Planilha do Rafael" /></x-filament::input.wrapper>
                </div>
                <x-filament::button color="gray" wire:click="saveProfile">Salvar perfil</x-filament::button>
            </div>
            <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                @foreach ($this->getFields() as $field => $label)
                    <div>
                        <label class="text-xs text-gray-400">{{ $label }}</label>
                        <x-filament::input.wrapper>
                            <x-filament::input.select wire:model="mapping.{{ $field }}">
                                <option value="">— não importar —</option>
                                @foreach ($headers as $h)<option value="{{ $h }}">{{ $h }}</option>@endforeach
                            </x-filament::input.select>
                        </x-filament::input.wrapper>
                    </div>
                @endforeach
            </div>
            <div class="mt-6 flex gap-2">
                <x-filament::button wire:click="preview" icon="heroicon-o-eye">Gerar prévia</x-filament::button>
                <x-filament::button color="gray" wire:click="resetImport">Recomeçar</x-filament::button>
            </div>
        </x-filament::section>
    @endif

    @if ($step === 3)
        <x-filament::section heading="3. Prévia" description="Confira antes de gravar. Homônimos sem data de nascimento não são unidos nem criados: ficam para revisão manual.">
            <div class="mb-4 flex flex-wrap gap-2 text-sm">
                <x-filament::badge color="gray">Linhas: {{ $summary['total'] ?? 0 }}</x-filament::badge>
                <x-filament::badge color="success">Criar: {{ $summary['criar'] ?? 0 }}</x-filament::badge>
                <x-filament::badge color="info">Atualizar (só vazios): {{ $summary['atualizar'] ?? 0 }}</x-filament::badge>
                <x-filament::badge color="warning">Ignorar (revisar): {{ $summary['ignorar'] ?? 0 }}</x-filament::badge>
                <x-filament::badge color="danger">Erro: {{ $summary['erro'] ?? 0 }}</x-filament::badge>
            </div>
            <div class="overflow-x-auto rounded-lg border border-gray-700">
                <table class="w-full text-sm">
                    <thead class="bg-gray-900 text-left text-xs uppercase text-gray-400">
                        <tr><th class="p-2">Linha</th><th class="p-2">Ação</th><th class="p-2">Atleta</th><th class="p-2">Pos.</th><th class="p-2">Nasc.</th><th class="p-2">Clube</th><th class="p-2">Observação</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($previewRows as $r)
                            <tr class="border-t border-gray-800" style="height:40px">
                                <td class="p-2 tabular-nums">{{ $r['row'] }}</td>
                                <td class="p-2"><x-filament::badge :color="['criar' => 'success', 'atualizar' => 'info', 'ignorar' => 'warning', 'erro' => 'danger'][$r['action']]">{{ $r['action'] }}</x-filament::badge></td>
                                <td class="p-2 font-semibold">{{ $r['name'] }}</td>
                                <td class="p-2">{{ $r['position'] }}</td>
                                <td class="p-2 tabular-nums">{{ $r['birth'] ? \Carbon\Carbon::parse($r['birth'])->format('d/m/Y') : '—' }}</td>
                                <td class="p-2">{{ $r['club'] }}</td>
                                <td class="p-2 text-gray-400">{{ $r['message'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if (($summary['total'] ?? 0) > 200)<p class="mt-2 text-xs text-gray-400">Mostrando as primeiras 200 linhas; todas serão processadas.</p>@endif
            <div class="mt-6 flex gap-2">
                <x-filament::button wire:click="commit" icon="heroicon-o-check" wire:loading.attr="disabled" wire:confirm="Gravar {{ ($summary['criar'] ?? 0) + ($summary['atualizar'] ?? 0) }} atleta(s)?">Confirmar importação</x-filament::button>
                <x-filament::button color="gray" wire:click="$set('step', 2)">Voltar ao mapeamento</x-filament::button>
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
