<x-filament-panels::page>
    @php
        $d = $this->data();
        $edit = $this->canEditPlan();
        $all = collect($d['phases'])->flatten(1);
        $sel = $all->first(fn ($r) => $r['slot']->id === $this->slotId);
        $lvl = fn ($x) => $x ? '<span class="sc-level sm l-'.$x['level']->value.'" title="'.e($x['phase'].' · '.$x['date']).'">'.$x['level']->value.'</span>' : '';
        $needUrl = fn ($n) => \App\Filament\Resources\RecruitmentNeeds\RecruitmentNeedResource::getUrl('search', ['record' => $n]);
    @endphp

    {{-- Resumo das carências --}}
    <div class="sc-plan-summary" role="status">
        @foreach (\App\Services\SquadPlanner::GAP_LABELS as $k => $label)
            <span class="sc-chip {{ $d['gaps'][$k] ? 'warn' : '' }}">{{ $label }}: <b>{{ $d['gaps'][$k] }}</b></span>
        @endforeach
        <span class="sc-chip">Necessidades abertas: <b>{{ $d['needs']->count() }}</b></span>
    </div>

    <div class="sc-plan-grid">
        @foreach (\App\Models\SquadPlan::PHASES as $phase => $phaseLabel)
            <section class="sc-panel" aria-label="Estrutura {{ $phaseLabel }}">
                <h4>{{ $phaseLabel }} · {{ $this->getRecord()->shapeFor($phase) }}</h4>
                <div class="sc-plan-pitch">
                    <svg viewBox="0 0 100 150" preserveAspectRatio="none" aria-hidden="true">
                        <rect x="0" y="0" width="100" height="150" fill="#1f5a3a"/>
                        <g stroke="#d9f2e3" stroke-opacity=".4" fill="none"><line x1="0" y1="75" x2="100" y2="75"/><circle cx="50" cy="75" r="12"/><rect x="22" y="0" width="56" height="22"/><rect x="22" y="128" width="56" height="22"/></g>
                    </svg>
                    @foreach ($d['phases'][$phase] as $r)
                        @php $s = $r['slot']; $st = $r['starter']; $state = in_array('sem_titular', $r['gaps']) ? 'gap' : ($r['gaps'] ? 'warn' : 'ok'); @endphp
                        <button type="button" wire:click="selectSlot('{{ $s->id }}')" wire:key="slot-{{ $s->id }}"
                                class="sc-slot {{ $state }} {{ $this->slotId === $s->id ? 'sel' : '' }}" style="left:{{ $s->x }}%;top:{{ round($s->y / 1.5, 2) }}%"
                                title="{{ $s->code }} · {{ $s->position->getLabel() }}{{ $r['gaps'] ? ' — '.collect($r['gaps'])->map(fn ($g) => \App\Services\SquadPlanner::GAP_LABELS[$g])->implode('; ') : '' }}">
                            <span class="code">{{ $s->code }}@if ($r['needs']->isNotEmpty()) <span title="Necessidade aberta nesta posição">●</span>@endif</span>
                            <span class="nm">{{ $st ? \Illuminate\Support\Str::limit($st['player']->name, 16) : '— vago —' }}</span>
                            <span class="meta">{!! $st ? $lvl($st['level']) : '' !!}@if ($st && ! $st['in_squad'])<span class="tag">alvo</span>@endif @if ($r['picks']->count() > 1)+{{ $r['picks']->count() - 1 }}@endif</span>
                        </button>
                    @endforeach
                </div>
            </section>
        @endforeach

        {{-- Posto selecionado --}}
        <section class="sc-panel" aria-live="polite">
            @if (! $sel)
                <h4>Posto</h4>
                <p class="sc-muted">Clique num posto do campo para ver titular, alternativas, nível no papel e necessidades da posição.</p>
                <p class="sc-muted" style="margin-top:.5rem">Cores: <span style="color:#fb7185">vermelho</span> = sem titular · <span style="color:#E9B65B">amarelo</span> = outra carência · <span style="color:#34d399">verde</span> = coberto. "alvo" = fora do elenco de referência.</p>
            @else
                @php $s = $sel['slot']; @endphp
                <h4>{{ $s->code }} · {{ $s->position->getLabel() }} · {{ \App\Models\SquadPlan::PHASES[$s->phase] }}</h4>
                <div class="sc-row"><span class="k">Papel do posto</span><span class="v">{{ $s->tacticalRole?->name ?? 'não definido' }} {{ ($this->setRoleAction)(['slot' => $s->id]) }}</span></div>
                @foreach ($sel['gaps'] as $g)<div class="sc-chip warn" style="margin:.25rem .25rem 0 0">{{ \App\Services\SquadPlanner::GAP_LABELS[$g] }}</div>@endforeach
                <div style="margin-top:.6rem">
                    @forelse ($sel['picks'] as $p)
                        <div class="sc-row" wire:key="pick-{{ $p['pick']->id }}">
                            <span class="k" style="opacity:1">
                                <b>{{ $p['pick']->depth === 1 ? 'Titular' : 'Alt. '.($p['pick']->depth - 1) }}</b> ·
                                <a href="{{ \App\Filament\Resources\Players\PlayerResource::getUrl('view', ['record' => $p['player']]) }}" style="text-decoration:underline">{{ $p['player']->name }}</a>
                                <span class="sc-muted">{{ $p['player']->currentClub?->name ?? 'clube n/i' }}{{ $p['in_squad'] ? '' : ' · alvo' }}</span>
                            </span>
                            <span class="v" style="display:flex;gap:.35rem;align-items:center">
                                @if ($s->tactical_role_id){!! $p['level'] ? $lvl($p['level']) : '<span class="sc-muted" title="Sem avaliação observada vigente neste papel e fase">n/a</span>' !!}@endif
                                @if ($edit)
                                    @if ($p['pick']->depth !== 1)<button type="button" wire:click="promotePick('{{ $p['pick']->id }}')" title="Tornar titular" aria-label="Tornar {{ $p['player']->name }} titular">▲</button>@endif
                                    <button type="button" wire:click="removePick('{{ $p['pick']->id }}')" title="Tirar do posto" aria-label="Tirar {{ $p['player']->name }} do posto">✕</button>
                                @endif
                            </span>
                        </div>
                    @empty
                        <p class="sc-muted">Ninguém neste posto.</p>
                    @endforelse
                </div>
                <div style="margin-top:.6rem">{{ ($this->addPlayerAction)(['slot' => $s->id]) }}</div>
                @if ($sel['needs']->isNotEmpty())
                    <h4 style="margin-top:.9rem">Necessidades abertas · {{ $s->position->value }}</h4>
                    @foreach ($sel['needs'] as $n)
                        <div class="sc-row"><span class="k">{{ $n->title }} <span class="sc-muted">{{ $n->priority?->getLabel() }}</span></span><span class="v"><a href="{{ $needUrl($n) }}" style="text-decoration:underline">pesquisar</a></span></div>
                    @endforeach
                @endif
            @endif
        </section>
    </div>

    <div class="sc-plan-grid two">
        <section class="sc-panel">
            <h4>Profundidade por posição (as duas estruturas, sem repetir atleta)</h4>
            <table class="sc-stats" style="width:100%">
                <thead><tr><th style="text-align:left">Posição</th><th>Postos</th><th>Opções</th><th>Do elenco</th><th>Alvos</th></tr></thead>
                <tbody>
                @foreach ($d['depth'] as $row)
                    <tr><td>{{ $row['position'] }} <span class="sc-muted">{{ $row['label'] }}</span></td><td style="text-align:center">{{ $row['slots'] }}</td>
                        <td style="text-align:center;{{ $row['options'] < 2 ? 'color:#fb7185;font-weight:700' : '' }}">{{ $row['options'] }}</td>
                        <td style="text-align:center">{{ $row['in_squad'] }}</td><td style="text-align:center">{{ $row['options'] - $row['in_squad'] }}</td></tr>
                @endforeach
                </tbody>
            </table>
        </section>
        <section class="sc-panel">
            <h4>Necessidades abertas fora das estruturas</h4>
            @forelse ($d['uncoveredNeeds'] as $n)
                <div class="sc-row"><span class="k">{{ $n->position->value }} · {{ $n->title }}</span><span class="v"><a href="{{ $needUrl($n) }}" style="text-decoration:underline">pesquisar</a></span></div>
            @empty
                <p class="sc-muted">Toda necessidade aberta tem posto nas estruturas deste plano.</p>
            @endforelse
            <p class="sc-muted" style="margin-top:.6rem">Carência aqui é fato do plano (posto vago, sem reserva, titular fora do elenco, sem avaliação no papel/fase). "Sem avaliação" não é nível baixo. O plano não decide contratação.</p>
        </section>
    </div>
    <x-filament-actions::modals />
</x-filament-panels::page>
