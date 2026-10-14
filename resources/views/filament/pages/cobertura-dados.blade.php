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
    @if (auth()->user()->isAdmin())
    <section class="sc-panel">
        <h4>Correções em lote (prévia — nada foi gravado)</h4>
        @php $cp = $d['clubPreview']; @endphp
        <div class="sc-row"><span class="k">Clube nas estatísticas: {{ $cp['vincular'] }} linha(s) ligam ao clube por vínculo confiável · {{ $cp['a_confirmar'] }} por semelhança (não aplicadas) · {{ $cp['sem_vinculo'] }} sem vínculo · {{ $cp['sem_rotulo'] }} sem rótulo da fonte</span>
            <span class="v">{{ ($this->fixAction)(['correcao' => 'clubes']) }}</span></div>
        <div class="sc-row"><span class="k">Fotos: {{ $d['photoPreview'] }} atleta(s) recebem a foto da API-Football (vínculo confiável, sem foto)</span><span class="v">{{ ($this->fixAction)(['correcao' => 'fotos']) }}</span></div>
        <div class="sc-row"><span class="k">Fotos: {{ $d['photoToCheck'] }} URL(s) externas ainda não verificadas</span><span class="v">{{ ($this->fixAction)(['correcao' => 'checar']) }}</span></div>
        <div class="sc-row"><span class="k">Enriquecimento: {{ $d['toEnrich'] }} de {{ $d['total'] }} atleta(s) do scout sem consulta ao Transfermarkt nos últimos 14 dias</span><span class="v">{{ ($this->fixAction)(['correcao' => 'enriquecer']) }}</span></div>
        <div class="sc-row"><span class="k">Com nascimento e candidatos do Transfermarkt na fila: {{ $d['toRetryBirth'] }} atleta(s) do scout</span><span class="v">{{ ($this->fixAction)(['correcao' => 'enriquecer_nascimento']) }}</span></div>
        @php $cc = $d['cbfConfirm']; $ccRows = collect($cc['rows'] ?? []); @endphp
        <div class="sc-row"><span class="k">Vínculos CBF na fila com "mesmo clube de inscrição e nome compatível": {{ $d['cbfConfirmable'] }} @if ($cc) · última {{ $cc['applied'] ? 'aplicação' : 'prévia' }} {{ \Illuminate\Support\Carbon::parse($cc['at'])->timezone($tz)->format('d/m H:i') }}: {{ $ccRows->where('decision', 'confirmar')->count() }} confirmados · {{ $ccRows->where('decision', 'fila')->count() }} ficaram na fila @endif</span>
            <span class="v">{{ ($this->fixAction)(['correcao' => 'confirmar_cbf']) }} @if ($ccRows->where('decision', 'fila')->isNotEmpty()){{ ($this->fixAction)(['correcao' => 'confirmar_cbf_divergentes']) }}@endif</span></div>
        @if ($ccRows->where('decision', 'fila')->isNotEmpty())
            <details style="margin:.3rem 0 .6rem"><summary class="sc-muted" style="cursor:pointer">Ver os que ficaram na fila ({{ $ccRows->where('decision', 'fila')->count() }})</summary>
                @foreach ($ccRows->where('decision', 'fila') as $i => $r)
                    <div class="sc-row" wire:key="cc-{{ $i }}"><span class="k"><strong>{{ $r['player'] }}</strong> · {{ $r['cbf'] }} <span class="sc-muted">— {{ $r['reason'] }}</span></span></div>
                @endforeach
            </details>
        @endif
        @php $mc = $d['mergeConfirm']; $mcRows = collect($mc['rows'] ?? []); @endphp
        <div class="sc-row"><span class="k">Provisórios na fila com "Mesmo clube e nome compatível": {{ $d['mergeConfirmable'] }} @if ($mc) · última {{ $mc['applied'] ? 'aplicação' : 'prévia' }} {{ \Illuminate\Support\Carbon::parse($mc['at'])->timezone($tz)->format('d/m H:i') }}: {{ $mcRows->where('decision', 'confirmar')->count() }} fundidos · {{ $mcRows->where('decision', 'fila')->count() }} ficaram na fila @endif</span>
            <span class="v">{{ ($this->fixAction)(['correcao' => 'confirmar_mesclas']) }} @if ($mcRows->where('decision', 'fila')->isNotEmpty()){{ ($this->fixAction)(['correcao' => 'confirmar_mesclas_divergentes']) }}@endif</span></div>
        @if ($mcRows->where('decision', 'fila')->isNotEmpty())
            <details style="margin:.3rem 0 .6rem"><summary class="sc-muted" style="cursor:pointer">Ver os que ficaram na fila ({{ $mcRows->where('decision', 'fila')->count() }})</summary>
                @foreach ($mcRows->where('decision', 'fila') as $i => $r)
                    <div class="sc-row" wire:key="mc-{{ $i }}"><span class="k"><strong>{{ $r['label'] }}</strong> <span class="sc-muted">— {{ $r['reason'] }}</span></span></div>
                @endforeach
            </details>
        @endif
        @php $dd = $d['dedup']; $ddRows = collect($dd['rows'] ?? []); @endphp
        <div class="sc-row"><span class="k">Cadastros duplicados do mesmo atleta @if ($dd) · última {{ $dd['applied'] ? 'fusão' : 'prévia' }} {{ \Illuminate\Support\Carbon::parse($dd['at'])->timezone($tz)->format('d/m H:i') }}: {{ $ddRows->count() }} par(es) @endif</span>
            <span class="v">{{ ($this->fixAction)(['correcao' => 'duplicados_previa']) }} @if ($dd && ! $dd['applied'] && $ddRows->isNotEmpty()){{ ($this->fixAction)(['correcao' => 'duplicados']) }}@endif</span></div>
        @if ($ddRows->isNotEmpty())
            <details style="margin:.3rem 0 .6rem"><summary class="sc-muted" style="cursor:pointer">Ver os pares ({{ $ddRows->count() }})</summary>
                @foreach ($ddRows as $i => $r)
                    <div class="sc-row" wire:key="dd-{{ $i }}"><span class="k"><strong>{{ $r['drop'] }}</strong> → {{ $r['keep'] }} <span class="sc-muted">— {{ $r['rule'] }}</span></span></div>
                @endforeach
            </details>
        @endif
        @php $cv = $d['convergence']; $cvRows = collect($cv['rows'] ?? []); @endphp
        <div class="sc-row"><span class="k">Identidades sem nascimento: ligação quando CBF e Transfermarkt concordam (nascimento + clube)@if ($cv) · última {{ $cv['applied'] ? 'aplicação' : 'prévia' }} {{ \Illuminate\Support\Carbon::parse($cv['at'])->timezone($tz)->format('d/m H:i') }}: {{ $cvRows->where('decision', 'ligar')->count() }} ligar · {{ $cvRows->where('decision', 'fila')->count() }} seguem na fila · {{ $cvRows->where('decision', 'erro')->count() }} erro(s)@endif</span>
            <span class="v">{{ ($this->fixAction)(['correcao' => 'convergir_previa']) }} @if ($cv && ! $cv['applied'] && $cvRows->where('decision', 'ligar')->isNotEmpty()){{ ($this->fixAction)(['correcao' => 'convergir']) }}@endif</span></div>
        @if ($cvRows->isNotEmpty())
            <details style="margin:.3rem 0 .6rem"><summary class="sc-muted" style="cursor:pointer">Ver a {{ $cv['applied'] ? 'aplicação' : 'prévia' }} atleta a atleta ({{ $cvRows->count() }})</summary>
                @foreach ($cvRows->sortBy(fn ($r) => ['ligar' => 0, 'duplicado' => 1, 'erro' => 2, 'fila' => 3][$r['decision']] ?? 4) as $r)
                    <div class="sc-row" wire:key="cv-{{ $r['player_id'] }}"><span class="k"><strong>{{ $r['player'] }}</strong> · {{ $r['club'] ?? '—' }}@if ($r['tm_label']) · TM: {{ $r['tm_label'] }}@endif <span class="sc-muted">— {{ $r['reason'] }}</span></span>
                        <span class="v">{{ ['ligar' => 'Ligar', 'duplicado' => 'Duplicado', 'fila' => 'Fila', 'erro' => 'Erro'][$r['decision']] ?? $r['decision'] }}</span></div>
                @endforeach
            </details>
        @endif
        @if ($d['batches']->isNotEmpty())
            <h4 style="margin-top:.9rem">Lotes de correção</h4>
            @foreach ($d['batches'] as $b)
                <div class="sc-row" wire:key="batch-{{ $b->batch }}"><span class="k">{{ \Illuminate\Support\Carbon::parse($b->at)->timezone($tz)->format('d/m H:i') }} · {{ $b->kind }} · {{ $b->rows }} linha(s){{ $b->reverted ? ' · '.$b->reverted.' desfeita(s)' : '' }} <span class="sc-muted">{{ $b->batch }}</span></span>
                    <span class="v">@if ($b->reverted < $b->rows)<button type="button" wire:click="revertBatch('{{ $b->batch }}')" wire:confirm="Desfazer o lote {{ $b->batch }}? Linhas editadas depois ficam como estão." style="text-decoration:underline">Desfazer</button>@endif</span></div>
            @endforeach
        @endif
    </section>
    @endif

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
