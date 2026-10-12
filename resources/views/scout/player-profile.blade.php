@php
    /** @var \App\Models\Player $p */
    use App\Support\PlayerInsight as PI;
    $user = auth()->user();
    $p = $getRecord();
    $p->loadMissing(['currentClub', 'rightsHolderClub', 'latestContract', 'agent', 'roleAssessments.tacticalRole', 'specialtyAssessments.specialty',
        'videoReferences', 'prospectCases.stage', 'prospectCases.need', 'rosterSpells.club',
        'submittedReports' => fn ($q) => $q->visibleTo($user)->with(['author', 'tacticalRole'])]);
    $cur = PI::currentLevel($p); $pot = PI::potentialLevel($p);
    $rec = PI::recommendation($p); $know = PI::knowledge($p);
    $codes = array_values(array_unique(array_filter(array_merge([$p->primary_position?->value], $p->positions ?? []))));
    $contract = $p->latestContract;
    $lvl = fn ($l) => $l ? '<span class="sc-level l-'.$l->value.'" title="'.e($l->getLabel()).'">'.($l->rank() ? $l->value : 'n/o').'</span>' : '<span class="sc-level l-none">n/o</span>';
    $roles = $p->roleAssessments->sortByDesc(fn ($a) => $a->level?->rank() ?? 0);
@endphp
<div style="display:flex;flex-direction:column;gap:1rem">
    {{-- Cabeçalho --}}
    <div class="sc-hero">
        <div class="sc-avatar" aria-hidden="true">
            <svg viewBox="0 0 64 64" width="78" height="78"><circle cx="32" cy="24" r="12" fill="#2c4466"/><path d="M8 64c2-16 12-24 24-24s22 8 24 24z" fill="#2c4466"/></svg>
        </div>
        <div>
            <div class="sc-name">{{ $p->name }}</div>
            <div class="sc-sub">{{ $p->primary_position?->getLabel() ?? 'Posição não informada' }}@if ($p->full_name && $p->full_name !== $p->name) · {{ $p->full_name }}@endif</div>
            <div class="sc-chips">
                <span class="sc-chip">{{ $p->nationality ?? 'Nacionalidade n/i' }}</span>
                <span class="sc-chip">{{ $p->age !== null ? $p->age.' anos ('.$p->birth_date->format('d/m/Y').')' : 'Idade não informada' }}</span>
                @if ($p->is_priority)<span class="sc-chip warn">★ Lista prioritária</span>@endif
                @if ($p->data_origin->value === 'sintetico')<span class="sc-chip warn">Dado sintético</span>@endif
            </div>
        </div>
        <div class="sc-box">
            <div class="sc-label">Clube</div>
            <div class="sc-value">{{ $p->currentClub?->name ?? 'não informado' }}</div>
            <div class="sc-muted">{{ $p->currentClub?->current_division ?? '' }}{{ $p->rightsHolderClub && $p->rightsHolderClub->id !== $p->current_club_id ? ' · direitos: '.$p->rightsHolderClub->name : '' }}</div>
        </div>
        <div class="sc-box">
            <div class="sc-label">Contrato · mercado</div>
            <div class="sc-value">{{ $contract?->status?->getLabel() ?? 'Sem observação' }}{{ $contract?->contract_end ? ' até '.$contract->contract_end->format('d/m/Y') : '' }}</div>
            <div class="sc-muted">{{ $p->market_status->getLabel() }}@if ($user->canViewFinancial()) · salário {{ $p->salaryRangeLabel() }}@endif</div>
        </div>
        <div class="sc-box">
            <div class="sc-label">Nível atual · potencial</div>
            <div class="sc-levels" style="margin-top:.25rem">{!! $lvl($cur) !!} {!! $lvl($pot) !!}</div>
            <div class="sc-muted">Escala por nível de competição (A–D)</div>
        </div>
    </div>

    <div class="sc-grid3">
        {{-- Posições e papéis --}}
        <div class="sc-panel">
            <h4>Posições</h4>
            <svg class="sc-pitch" viewBox="-4 -4 108 158" role="img" aria-label="Posições do atleta no campo: {{ implode(', ', $codes) ?: 'não informadas' }}">
                <rect x="0" y="0" width="100" height="150" rx="2" fill="#1f5a3a" stroke="#d9f2e3" stroke-opacity=".5"/>
                <line x1="0" y1="75" x2="100" y2="75" stroke="#d9f2e3" stroke-opacity=".5"/>
                <circle cx="50" cy="75" r="12" fill="none" stroke="#d9f2e3" stroke-opacity=".5"/>
                <rect x="22" y="0" width="56" height="22" fill="none" stroke="#d9f2e3" stroke-opacity=".5"/>
                <rect x="22" y="128" width="56" height="22" fill="none" stroke="#d9f2e3" stroke-opacity=".5"/>
                @foreach (PI::PITCH as $code => [$x, $y])
                    @php $isPrimary = $p->primary_position?->value === $code; $has = in_array($code, $codes, true); @endphp
                    <circle cx="{{ $x }}" cy="{{ $y }}" r="{{ $has ? 5 : 3 }}" fill="{{ $isPrimary ? '#34d399' : ($has ? '#79B9F2' : '#0f2b1c') }}" stroke="{{ $has ? '#fff' : 'none' }}" stroke-width="1">
                        <title>{{ $code }}{{ $isPrimary ? ' (principal)' : ($has ? ' (secundária)' : '') }}</title>
                    </circle>
                    @if ($has)<text x="{{ $x }}" y="{{ $y + 11 }}" font-size="6" text-anchor="middle" fill="#fff">{{ $code }}</text>@endif
                @endforeach
            </svg>
            <h4 style="margin-top:.8rem">Papéis avaliados</h4>
            @forelse ($roles as $a)
                <div class="sc-row"><span class="k">{{ $a->tacticalRole->name }} <span class="sc-muted">· {{ $a->kind->value === 'potencial' ? 'potencial' : 'observado' }}</span></span><span class="v">{!! $lvl($a->level) !!}</span></div>
            @empty
                <p class="sc-muted">Nenhum papel avaliado ainda — "não avaliado" ≠ "padrão".</p>
            @endforelse
        </div>

        {{-- Estatísticas e características --}}
        <div class="sc-panel">
            <h4>Estatísticas</h4>
            <p class="sc-muted" style="margin-bottom:.6rem">Sem dados de provedor para este atleta ainda. Minutos, jogos e estatísticas entram pelas súmulas CBF/FPF e pelas APIs quando conectadas — com fonte, período e amostra.</p>
            <h4>Características e especialidades</h4>
            @forelse ($p->specialtyAssessments as $s)
                <div class="sc-row"><span class="k">{{ $s->specialty->name }}</span>
                    <span class="v">@if (($s->sample_attempts ?? 0) > 0 && $s->sample_successes !== null)<span class="sc-muted">{{ $s->sample_successes }}/{{ $s->sample_attempts }}</span> @endif{!! $lvl($s->level) !!}</span></div>
            @empty
                <p class="sc-muted">Nenhuma especialidade registrada.</p>
            @endforelse
            <h4 style="margin-top:.8rem">Relatórios de scout</h4>
            @forelse (collect($p->submittedReports)->take(5) as $r)
                <div class="sc-row"><span class="k">{{ $r->author->name }} · {{ $r->tacticalRole?->name ?? 'papel n/i' }} <span class="sc-muted">{{ $r->submitted_at?->format('d/m/Y') }}</span></span>
                    <span class="v">{!! $lvl($r->level) !!} <x-filament::badge :color="$r->verdict->getColor()" size="sm">{{ $r->verdict->getLabel() }}</x-filament::badge></span></div>
            @empty
                <p class="sc-muted">Nenhum relatório submetido visível para você.</p>
            @endforelse
        </div>

        {{-- Info --}}
        <div class="sc-panel">
            <h4>Info</h4>
            <div class="sc-row"><span class="k">Altura</span><span class="v">{{ $p->height_cm ? number_format($p->height_cm / 100, 2, ',', '') . ' m' : 'n/i' }}</span></div>
            <div class="sc-row"><span class="k">Pé preferencial</span><span class="v">{{ $p->preferred_foot->getLabel() }}</span></div>
            <div class="sc-row"><span class="k">ID CBF</span><span class="v">{{ $p->cbf_id ?? 'n/i' }}</span></div>
            <div class="sc-row"><span class="k">Passaporte comunitário</span><span class="v">{{ $p->eu_passport === null ? 'n/i' : ($p->eu_passport ? 'Sim' : 'Não') }}</span></div>
            <div class="sc-row"><span class="k">Idiomas</span><span class="v">{{ $p->languages ? implode(', ', $p->languages) : 'n/i' }}</span></div>
            <div class="sc-row"><span class="k">Empresário</span><span class="v">{{ $p->agent?->name ?? ($p->agent_name ?: 'n/i') }}</span></div>
            @if ($p->agent && ($p->agent->phone || $p->agent->whatsapp))<div class="sc-row"><span class="k">Contato empresário</span><span class="v">@if ($p->agent->whatsappUrl())<a href="{{ $p->agent->whatsappUrl() }}" target="_blank" rel="noopener" style="color:#79B9F2">{{ $p->agent->whatsapp ?: $p->agent->phone }}</a>@else{{ $p->agent->phone }}@endif</span></div>@endif
            @if ($user->canEdit())<div class="sc-row"><span class="k">Contato do atleta</span><span class="v">{{ collect([$p->phone, $p->whatsapp])->filter()->unique()->implode(' · ') ?: 'n/i' }}</span></div>@endif
            <div class="sc-row"><span class="k">Recomendação</span><span class="v"><x-filament::badge :color="$rec['color']">{{ $rec['label'] }}</x-filament::badge></span></div>
            <div class="sc-row"><span class="k">Conhecimento</span><span class="v"><x-filament::badge :color="$know['color']">{{ $know['label'] }}</x-filament::badge></span></div>
        </div>
    </div>

    {{-- Cartões --}}
    <div class="sc-cards">
        <div class="sc-card"><div class="t">Situação contratual</div><div class="big">{{ $contract?->status?->getLabel() ?? 'Sem observação' }}</div><div class="sc-muted">{{ $contract ? $contract->confirmation->getLabel().' · '.$contract->observed_on->format('d/m/Y') : 'Registre na aba Contrato' }}</div></div>
        <div class="sc-card"><div class="t">Mercado</div><div class="big">{{ $p->market_status->getLabel() }}</div>@if ($user->canViewFinancial())<div class="sc-muted">Pedida: {{ $p->asking_price !== null ? $p->asking_price_currency.' '.number_format((float) $p->asking_price, 0, ',', '.') : 'não informada' }}</div>@endif</div>
        <div class="sc-card"><div class="t">Relatórios</div><div class="big">{{ collect($p->submittedReports)->count() }} submetido(s)</div><div class="sc-muted">Parecer: {{ $rec['label'] }}</div></div>
        <div class="sc-card"><div class="t">Vídeos</div><div class="big">{{ $p->videoReferences->count() }}</div><div class="sc-muted">{{ $p->videoReferences->where('polarity', 'negativo')->count() }} trecho(s) com ação negativa</div></div>
        <div class="sc-card"><div class="t">Prospecção</div><div class="big">{{ $p->prospectCases->count() ? $p->prospectCases->first()->stage->name : 'Fora do quadro' }}</div><div class="sc-muted">{{ $p->prospectCases->count() > 1 ? $p->prospectCases->count().' necessidades' : ($p->prospectCases->first()?->need?->title ?? '') }}</div></div>
        <div class="sc-card"><div class="t">Carreira</div><div class="big">{{ $p->rosterSpells->count() ? $p->rosterSpells->count().' clube(s)' : 'Histórico n/i' }}</div><div class="sc-muted">{{ $p->rosterSpells->first()?->club?->name }}</div></div>
    </div>
</div>
