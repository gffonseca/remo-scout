<x-filament-panels::page>
    @php $d = $this->data(); $ps = $d['players']; $lvl = fn ($l) => $l ? '<span class="sc-level l-'.$l->value.'">'.($l->rank() ? $l->value : 'n/o').'</span>' : '<span class="sc-level l-none">n/o</span>'; @endphp
    @if ($ps->count() < 2)
        <div class="sc-panel" style="padding:1rem">
            <p>Fixe de 2 a {{ \App\Services\Comparator::MAX }} atletas para comparar ({{ $ps->count() }} fixado{{ $ps->count() === 1 ? '' : 's' }}).</p>
            <p class="sc-muted">No perfil do atleta: "Fixar no comparador". Na lista de jogadores: ícone de balança na linha. A seleção fica salva para você.</p>
        </div>
    @endif
    @if ($ps->isNotEmpty())
    <div style="display:flex;gap:.75rem;flex-wrap:wrap;align-items:center">
        <label class="sc-muted" for="base">Estatística (mesma fonte e temporada)</label>
        <select id="base" wire:model.live="base" style="border:1px solid #9ca3af55;border-radius:.4rem;padding:.25rem .5rem;background:transparent">
            @forelse ($d['bases'] as $k => $label)<option value="{{ $k }}" @selected($k === $d['base'])>{{ $label }}</option>@empty<option value="">sem estatística</option>@endforelse
        </select>
        <label class="sc-muted" for="need">Necessidade de referência</label>
        <select id="need" wire:model.live="need" style="border:1px solid #9ca3af55;border-radius:.4rem;padding:.25rem .5rem;background:transparent">
            <option value="">nenhuma</option>
            @foreach ($d['needs'] as $id => $t)<option value="{{ $id }}">{{ $t }}</option>@endforeach
        </select>
    </div>
    <div style="overflow-x:auto">
    <table class="sc-stats" style="width:100%;min-width:{{ 220 + 200 * $ps->count() }}px">
        <thead><tr><th style="width:220px"></th>
            @foreach ($ps as $p)
                <th style="text-align:left;vertical-align:top">
                    <a href="{{ \App\Filament\Resources\Players\PlayerResource::getUrl('view', ['record' => $p]) }}" style="font-weight:700">{{ $p->name }}</a>
                    <button type="button" wire:click="unpin('{{ $p->id }}')" title="Retirar do comparador" style="margin-left:.3rem;opacity:.6">✕</button>
                    <div class="sc-muted" style="font-weight:400">{!! \App\Support\Media::crestTag($p->currentClub, 14) !!}{{ $p->currentClub?->name ?? 'clube n/i' }}</div>
                </th>
            @endforeach
        </tr></thead>
        <tbody>
            <tr><td class="sc-muted">Idade · pé</td>@foreach ($ps as $p)<td>{{ $p->age !== null ? $p->age.' anos' : 'n/i' }} · {{ $p->preferred_foot?->getLabel() ?? 'pé n/i' }}</td>@endforeach</tr>
            <tr><td class="sc-muted">Posições</td>@foreach ($ps as $p)<td>{{ collect([$p->primary_position?->value])->merge($p->positions ?? [])->filter()->unique()->implode(', ') ?: 'n/i' }}</td>@endforeach</tr>
            @php $L = $ps->mapWithKeys(fn ($p) => [$p->id => \App\Services\Comparator::levels($p)]); @endphp
            <tr><td class="sc-muted">Nível atual (observado, vigente)</td>@foreach ($ps as $p)<td>{!! $lvl($L[$p->id]['atual']) !!}</td>@endforeach</tr>
            <tr><td class="sc-muted">Potencial</td>@foreach ($ps as $p)<td>{!! $lvl($L[$p->id]['potencial']) !!}</td>@endforeach</tr>
            <tr><td class="sc-muted">Parecer · conhecimento</td>@foreach ($ps as $p)<td>{{ $L[$p->id]['parecer']['label'] }} · {{ $L[$p->id]['conhecimento']['label'] }}</td>@endforeach</tr>
            @if ($d['grid'])
                <tr><td colspan="{{ 1 + $ps->count() }}" style="font-weight:700;padding-top:.6rem">Nível observado por papel e fase (data da observação)</td></tr>
                @foreach ($d['grid'] as $label => $cells)
                    <tr><td class="sc-muted">{{ $label }}</td>
                        @foreach ($ps as $p)
                            @php $c = $cells[$p->id] ?? null; @endphp
                            <td>@if ($c){!! $lvl($c['level']) !!} <span class="sc-muted">{{ $c['date'] }}{{ $c['expired'] ? ' · vencida' : '' }}</span>@else<span class="sc-muted">não avaliado</span>@endif</td>
                        @endforeach
                    </tr>
                @endforeach
            @else
                <tr><td colspan="{{ 1 + $ps->count() }}" class="sc-muted">Nenhum papel avaliado (observado) nestes atletas — comparação de nível indisponível.</td></tr>
            @endif
            <tr><td class="sc-muted">Especialidades observadas</td>@foreach ($ps as $p)<td style="font-size:.8rem">{{ $p->specialtyAssessments->filter(fn ($s) => $s->level?->rank())->map(fn ($s) => $s->specialty->name.' '.$s->level->value)->implode(', ') ?: '—' }}</td>@endforeach</tr>
            <tr><td colspan="{{ 1 + $ps->count() }}" style="font-weight:700;padding-top:.6rem">Estatística — {{ $d['bases'][$d['base']] ?? 'sem base comum' }}
                @if ($d['stats']['context_differs'])<x-filament::badge color="warning">competições diferentes: compare com cautela</x-filament::badge>@endif</td></tr>
            @php $S = $d['stats']['rows']; @endphp
            <tr><td class="sc-muted">Competição</td>@foreach ($ps as $p)<td>{{ ($S[$p->id] ?? null)?->competition_name ?? 'sem dado nesta base' }}</td>@endforeach</tr>
            <tr><td class="sc-muted">Clube (na estatística)</td>@foreach ($ps as $p)@php $st = $S[$p->id] ?? null; @endphp<td>@if (! $st) — @elseif ($st->clubLabel()){{ $st->clubLabel() }}@elseif ($st->hasClubReference())<span class="sc-muted" title="{{ $st->clubSupportDetail() }}">Clube não identificado</span>@else — @endif</td>@endforeach</tr>
            <tr><td class="sc-muted">Jogos · minutos</td>@foreach ($ps as $p)@php $s = $S[$p->id] ?? null; @endphp<td>@if ($s){{ \App\Support\PlayerStatsView::appearances($s) }} · {{ \App\Support\PlayerStatsView::minutes($s) }}@if (\App\Support\PlayerStatsView::smallSample($s)) <x-filament::badge color="warning">amostra pequena</x-filament::badge>@endif @else — @endif</td>@endforeach</tr>
            <tr><td class="sc-muted">Gols · G/90</td>@foreach ($ps as $p)@php $s = $S[$p->id] ?? null; $g = $s ? \App\Support\PlayerStatsView::goalsPer90($s) : null; @endphp<td>@if ($s){{ \App\Support\PlayerStatsView::goals($s) }} · {{ $g !== null ? number_format($g, 2, ',', '') : '—' }}@else — @endif</td>@endforeach</tr>
            <tr><td class="sc-muted">Assistências · nota</td>@foreach ($ps as $p)@php $s = $S[$p->id] ?? null; @endphp<td>@if ($s){{ $s->assists ?? '—' }} · {{ $s->rating !== null ? number_format((float) $s->rating, 2, ',', '') : '—' }}@else — @endif</td>@endforeach</tr>
            <tr><td colspan="{{ 1 + $ps->count() }}" style="font-weight:700;padding-top:.6rem">Contrato e mercado</td></tr>
            <tr><td class="sc-muted">Contrato</td>@foreach ($ps as $p)<td>{{ $p->latestContract?->status?->getLabel() ?? 'sem observação' }}{{ $p->latestContract?->contract_end ? ' até '.$p->latestContract->contract_end->format('d/m/Y') : '' }}</td>@endforeach</tr>
            @if ($d['financial'])
                <tr><td class="sc-muted">Faixa salarial</td>@foreach ($ps as $p)<td>{{ $p->salaryRangeLabel() }}</td>@endforeach</tr>
                @if ($d['needModel'])
                    <tr><td class="sc-muted">× teto de "{{ $d['needModel']->title }}"</td>@foreach ($ps as $p)@php $b = \App\Services\Comparator::budget($d['needModel'], $p); @endphp<td>@if ($b)<x-filament::badge :color="\App\Support\BudgetFit::color($b['state'])">{{ \App\Support\BudgetFit::LABELS[$b['state']] }}</x-filament::badge>@else <span class="sc-muted">necessidade sem teto salarial</span>@endif</td>@endforeach</tr>
                @endif
            @endif
            @if ($d['needModel'])
                <tr><td class="sc-muted">Requisitos de "{{ $d['needModel']->title }}"</td>@foreach ($ps as $p)@php $sum = \App\Services\RequirementCheck::summary($d['needModel'], $p); @endphp<td><x-filament::badge :color="\App\Services\RequirementCheck::color($sum)">{{ \Illuminate\Support\Str::limit($sum, 60) }}</x-filament::badge></td>@endforeach</tr>
            @endif
        </tbody>
    </table>
    </div>
    @endif
</x-filament-panels::page>
