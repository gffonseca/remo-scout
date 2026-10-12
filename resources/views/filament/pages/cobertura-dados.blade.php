<x-filament-panels::page>
    @php $d = $this->data(); $pct = fn ($n) => $d['total'] ? round(100 * $n / $d['total']) : 0; $tz = config('scout.display_timezone'); @endphp
    <p class="sc-muted">Denominador: {{ $d['total'] }} atletas do scout (cadastros provisórios de partidas e registros CBF não contam). Estado calculado agora; "disponível" = estatística desta temporada ou da anterior com jogos/minutos, coletada há menos de 30 dias.</p>
    <div class="sc-plan-grid two">
        <section class="sc-panel">
            <h4>Estatística por atleta</h4>
            @foreach (\App\Services\DataCoverage::STATS_STATES as $k => [$label, $help])
                <button type="button" wire:click="$set('tipo', 'estatistica'); $set('motivo', '{{ $k }}')" class="sc-row" style="width:100%;text-align:left;{{ $this->tipo === 'estatistica' && $this->motivo === $k ? 'outline:2px solid #79B9F2' : '' }}" title="{{ $help }}">
                    <span class="k">{{ $label }}</span><span class="v">{{ $d['states'][$k] ?? 0 }} <span class="sc-muted">({{ $pct($d['states'][$k] ?? 0) }}%)</span></span>
                </button>
            @endforeach
        </section>
        <section class="sc-panel">
            <h4>Foto: {{ $d['with_photo'] }} de {{ $d['total'] }} com foto ({{ $pct($d['with_photo']) }}%)</h4>
            @foreach (\App\Services\DataCoverage::PHOTO_REASONS as $k => [$label, $help])
                <button type="button" wire:click="$set('tipo', 'foto'); $set('motivo', '{{ $k }}')" class="sc-row" style="width:100%;text-align:left;{{ $this->tipo === 'foto' && $this->motivo === $k ? 'outline:2px solid #79B9F2' : '' }}" title="{{ $help }}">
                    <span class="k">{{ $label }}</span><span class="v">{{ $d['photo'][$k] ?? 0 }}</span>
                </button>
            @endforeach
            <p class="sc-muted" style="margin-top:.5rem">Foto com URL preenchida não garante imagem disponível: a verificação das URLs é feita pelo comando de fotos (prévia).</p>
            <h4 style="margin-top:.9rem">Fontes · última execução</h4>
            @foreach ($d['runs'] as $r)
                <div class="sc-row"><span class="k">{{ $r->name }}@if ($r->blocked) <span style="color:#fb7185">· pausada/bloqueada</span>@endif @if ($r->running) <span style="color:#E9B65B">· em processamento</span>@endif</span>
                    <span class="v" style="font-weight:400">{{ $r->last_status ?? 'nunca' }}{{ $r->last_at ? ' '.$r->last_at->timezone($tz)->format('d/m H:i') : '' }} · ok {{ $r->ok_at?->timezone($tz)->format('d/m H:i') ?? '—' }}</span></div>
            @endforeach
        </section>
    </div>

    <section class="sc-panel">
        <div style="display:flex;gap:.75rem;flex-wrap:wrap;align-items:center;margin-bottom:.5rem">
            <h4 style="margin:0;border:0">{{ $this->tipo === 'foto' ? 'Sem foto' : 'Estatística ausente, parcial ou desatualizada' }}{{ $this->motivo ? ' · '.$d['reasons'][$this->motivo][0] : '' }} ({{ $d['listCount'] }})</h4>
            <label class="sc-muted"><input type="checkbox" wire:model.live="prioritarios"> só lista prioritária</label>
            @if ($this->motivo)<button type="button" wire:click="$set('motivo', null)" class="sc-muted" style="text-decoration:underline">todos os motivos</button>@endif
        </div>
        @if ($this->motivo)<p class="sc-muted" style="margin-bottom:.5rem">{{ $d['reasons'][$this->motivo][1] }}</p>@endif
        @if ($this->motivo === 'identidade_pendente')<p style="margin-bottom:.5rem"><a href="{{ \App\Filament\Resources\IdentityCandidates\IdentityCandidateResource::getUrl() }}" style="text-decoration:underline">Abrir revisões de identidade →</a></p>@endif
        <table class="sc-stats" style="width:100%">
            <thead><tr><th style="text-align:left">Atleta</th><th style="text-align:left">Clube</th><th style="text-align:left">Estado / motivo</th><th style="text-align:left">Última coleta</th></tr></thead>
            <tbody>
            @forelse ($d['list'] as $r)
                <tr wire:key="cov-{{ $r->id }}">
                    <td><a href="{{ \App\Filament\Resources\Players\PlayerResource::getUrl('view', ['record' => $r->id]) }}" style="text-decoration:underline">{{ $r->name }}</a>@if ($r->priority) <span title="Lista prioritária">★</span>@endif</td>
                    <td>{{ $r->club ?? '—' }}</td>
                    <td>{{ $d['reasons'][$r->{$d['field']}][0] ?? $r->{$d['field']} }}</td>
                    <td class="sc-muted">{{ $r->last_fetch ? \Illuminate\Support\Carbon::parse($r->last_fetch)->timezone($tz)->format('d/m/Y') : '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="4" class="sc-muted">Nenhum atleta neste filtro.</td></tr>
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
