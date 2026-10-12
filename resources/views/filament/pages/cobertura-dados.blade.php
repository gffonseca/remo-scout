<x-filament-panels::page>
    @php $d = $this->data(); $pct = fn ($n) => $d['total'] ? round(100 * $n / $d['total']) : 0; @endphp
    <div class="sc-plan-grid two">
        @foreach (['estatistica' => ['Sem estatística', $d['stats'], \App\Services\DataCoverage::STATS_REASONS], 'foto' => ['Sem foto', $d['photo'], \App\Services\DataCoverage::PHOTO_REASONS]] as $tipo => [$title, $counts, $reasons])
            <section class="sc-panel">
                <h4>{{ $title }}: {{ array_sum($counts) }} de {{ $d['total'] }} atletas ({{ $pct(array_sum($counts)) }}%)</h4>
                @foreach ($reasons as $k => [$label, $help])
                    <button type="button" wire:click="$set('tipo', '{{ $tipo }}'); $set('motivo', '{{ $k }}')" class="sc-row" style="width:100%;text-align:left;{{ $this->tipo === $tipo && $this->motivo === $k ? 'outline:2px solid #79B9F2' : '' }}" title="{{ $help }}">
                        <span class="k">{{ $label }}</span><span class="v">{{ $counts[$k] ?? 0 }}</span>
                    </button>
                @endforeach
            </section>
        @endforeach
    </div>

    <section class="sc-panel">
        <div style="display:flex;gap:.75rem;flex-wrap:wrap;align-items:center;margin-bottom:.5rem">
            <h4 style="margin:0;border:0">{{ $this->tipo === 'foto' ? 'Sem foto' : 'Sem estatística' }}{{ $this->motivo ? ' · '.$d['reasons'][$this->motivo][0] : '' }} ({{ $d['listCount'] }})</h4>
            <label class="sc-muted"><input type="checkbox" wire:model.live="prioritarios"> só lista prioritária</label>
            @if ($this->motivo)<button type="button" wire:click="$set('motivo', null)" class="sc-muted" style="text-decoration:underline">todos os motivos</button>@endif
        </div>
        @if ($this->motivo)<p class="sc-muted" style="margin-bottom:.5rem">{{ $d['reasons'][$this->motivo][1] }}</p>@endif
        @if ($this->motivo === 'revisao')<p style="margin-bottom:.5rem"><a href="{{ \App\Filament\Resources\IdentityCandidates\IdentityCandidateResource::getUrl() }}" style="text-decoration:underline">Abrir revisões de identidade →</a></p>@endif
        <table class="sc-stats" style="width:100%">
            <thead><tr><th style="text-align:left">Atleta</th><th style="text-align:left">Clube</th><th style="text-align:left">Motivo</th></tr></thead>
            <tbody>
            @forelse ($d['list'] as $r)
                <tr wire:key="cov-{{ $r->id }}">
                    <td><a href="{{ \App\Filament\Resources\Players\PlayerResource::getUrl('view', ['record' => $r->id]) }}" style="text-decoration:underline">{{ $r->name }}</a>@if ($r->priority) <span title="Lista prioritária">★</span>@endif</td>
                    <td>{{ $r->club ?? '—' }}</td>
                    <td>{{ $d['reasons'][$r->{$d['field']}][0] ?? $r->{$d['field']} }}</td>
                </tr>
            @empty
                <tr><td colspan="3" class="sc-muted">Nenhum atleta neste filtro.</td></tr>
            @endforelse
            </tbody>
        </table>
        @if ($d['listCount'] > 300)<p class="sc-muted">Mostrando 300 de {{ $d['listCount'] }}. Use os filtros.</p>@endif
    </section>
    <section class="sc-panel">
        <h4>Clubes das fontes sem vínculo nas estatísticas ({{ $d['clubs']->count() }})</h4>
        <p class="sc-muted" style="margin-bottom:.5rem">Estas linhas aparecem como "Clube não identificado". O vínculo é pelo ID do clube na fonte, confirmado por coordenador; nome parecido não basta.</p>
        <table class="sc-stats" style="width:100%">
            <thead><tr><th style="text-align:left">Fonte</th><th style="text-align:left">Rótulo na fonte</th><th>ID</th><th>Atletas</th><th>Linhas</th><th style="text-align:left">Situação</th><th></th></tr></thead>
            <tbody>
            @forelse ($d['clubs'] as $c)
                <tr wire:key="club-{{ $c->provider }}-{{ $c->external_id }}">
                    <td>{{ \App\Support\PlayerStatsView::providerLabel($c->provider) }}</td><td>{{ $c->label ?? 'sem rótulo (use --labels no backfill)' }}</td>
                    <td class="sc-muted">{{ $c->external_id }}</td><td style="text-align:center">{{ $c->players }}</td><td style="text-align:center">{{ $c->rows }}</td>
                    <td>{{ $c->map_club ? 'Sugerido: '.$c->map_club.' ('.(\App\Models\ClubExternalId::METHODS[$c->map_method] ?? $c->map_method).')' : 'Sem vínculo' }}</td>
                    <td>{{ ($this->linkClubAction)(['provider' => $c->provider, 'external_id' => $c->external_id]) }}</td>
                </tr>
            @empty
                <tr><td colspan="7" class="sc-muted">Nenhuma pendência de clube.</td></tr>
            @endforelse
            </tbody>
        </table>
    </section>
    <x-filament-actions::modals />
</x-filament-panels::page>
