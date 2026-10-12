<x-filament-panels::page>
    @php $d = $this->data(); $can = auth()->user()->canEdit(); @endphp
    <div class="sc-panel" style="padding:.75rem 1rem">
        <div class="sc-muted" style="font-weight:700;margin-bottom:.3rem">Critérios da necessidade (os mesmos da tela da necessidade)</div>
        <div style="display:flex;flex-wrap:wrap;gap:.35rem">
            @foreach ($d['criteria'] as $c)<span class="sc-chip">{{ $c }}</span>@endforeach
        </div>
        <div class="sc-muted" style="margin-top:.5rem;font-size:.85rem">
            Universo: atletas do scout com a posição (principal ou secundária) e sem posição informada. Desconhecido nunca vira "atende": entra como pendência.
            {{ $d['counts']['total'] }} avaliados · {{ $d['counts']['atende'] ?? 0 }} atendem · {{ $d['counts']['pendente'] ?? 0 }} com pendências · {{ $d['counts']['nao_atende'] ?? 0 }} não atendem.
        </div>
    </div>

    <div style="display:flex;gap:.75rem;flex-wrap:wrap;align-items:center">
        <label class="sc-muted" for="show">Mostrar</label>
        <select id="show" wire:model.live="show" style="border:1px solid #9ca3af55;border-radius:.4rem;padding:.25rem .5rem;background:transparent">
            <option value="viaveis">Atende + com pendências</option>
            <option value="atende">Só atende</option>
            <option value="pendente">Só com pendências</option>
            <option value="nao_atende">Só não atende</option>
            <option value="todos">Todos</option>
        </select>
        <label class="sc-muted" for="pendingIn">Pendência em</label>
        <select id="pendingIn" wire:model.live="pendingIn" style="border:1px solid #9ca3af55;border-radius:.4rem;padding:.25rem .5rem;background:transparent">
            <option value="">qualquer</option>
            @foreach ($d['pendingOptions'] as $crit => $n)<option value="{{ $crit }}">{{ $crit }} ({{ $n }})</option>@endforeach
        </select>
        <span class="sc-muted">{{ $d['rows']->count() }} atleta(s)</span>
    </div>

    <table class="sc-stats" style="width:100%">
        <thead><tr><th>Atleta</th><th>Clube</th><th>Idade</th><th>Classificação</th><th>Critérios</th><th></th></tr></thead>
        <tbody>
        @forelse ($d['rows'] as $r)
            @php $p = $r['player']; @endphp
            <tr wire:key="ns-{{ $p->id }}">
                <td><a href="{{ $this->playerUrl($p) }}" style="font-weight:700">{{ $p->name }}</a>@if ($p->is_priority) ★@endif</td>
                <td>{!! \App\Support\Media::crestTag($p->currentClub, 14) !!}{{ $p->currentClub?->name ?? '—' }}</td>
                <td>{{ $p->age ?? '—' }}</td>
                <td><x-filament::badge :color="['atende' => 'success', 'pendente' => 'warning', 'nao_atende' => 'danger'][$r['class']]">{{ \App\Services\NeedSearch::CLASSES[$r['class']] }}</x-filament::badge></td>
                <td style="font-size:.8rem;line-height:1.35">
                    @foreach ($r['items'] as $i)
                        <span title="{{ $i['detail'] }}" style="cursor:help;white-space:nowrap">{{ \App\Enums\RequirementStatus::from($i['status'])->symbol() }} {{ $i['item'] }}</span>@if (! $loop->last) · @endif
                    @endforeach
                </td>
                <td style="white-space:nowrap">
                    @if ($r['in_pipeline'])<span class="sc-muted">no quadro</span>
                    @elseif ($can && $r['class'] !== 'nao_atende')
                        <x-filament::button size="xs" color="gray" wire:click="addToBoard('{{ $p->id }}')">Adicionar ao quadro</x-filament::button>
                    @endif
                </td>
            </tr>
        @empty
            <tr><td colspan="6" class="sc-muted">Nenhum atleta neste filtro.</td></tr>
        @endforelse
        </tbody>
    </table>
</x-filament-panels::page>
