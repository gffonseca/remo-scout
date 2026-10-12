<!doctype html>
<html lang="pt-BR"><head><meta charset="utf-8"><title>Dossiê — {{ $player->name }}</title>@include('pdf._style')</head>
<body>
@php($tz = config('scout.display_timezone'))
<header class="top"><strong>REMO SCOUT — piloto</strong> · Departamento de Análise de Mercado · <strong>USO INTERNO — PILOTO</strong></header>
<footer class="bot">Gerado em {{ now()->timezone($tz)->format('d/m/Y H:i') }} por {{ $user->name }}. Documento interno de apoio à decisão: não é avaliação médica, jurídica ou financeira. Dados manuais/observados indicam fonte e data; "não informado" ≠ zero.</footer>

<h1>{{ $player->name }}</h1>
<div class="muted">{{ $player->full_name }}</div>
<table class="grid" style="margin-top:6px">
    <tr><td><b>Posição:</b> {{ $player->primary_position?->getLabel() ?? 'não informada' }} @foreach ($player->positions ?? [] as $p)<span class="tag">{{ $p }}</span>@endforeach</td>
        <td><b>Idade:</b> {{ $player->age !== null ? $player->age.' anos ('.$player->birth_date->format('d/m/Y').')' : 'não informada' }}</td>
        <td><b>Pé:</b> {{ $player->preferred_foot?->getLabel() }}</td></tr>
    <tr><td><b>Clube atual:</b> {{ $player->currentClub?->name ?? 'não informado' }}</td>
        <td><b>Altura:</b> {{ $player->height_cm ? $player->height_cm.' cm' : 'não informada' }}</td>
        <td><b>ID CBF:</b> {{ $player->cbf_id ?? 'não informado' }}</td></tr>
    <tr><td><b>Nacionalidade:</b> {{ $player->nationality ?? 'não informada' }}{{ $player->eu_passport ? ' · passaporte comunitário' : '' }}</td>
        <td><b>Empresário:</b> {{ $player->agent?->label() ?? ($player->agent_name ?: 'não informado') }}@if ($player->agent && ($player->agent->phone || $player->agent->email))<br><span class="muted">{{ collect([$player->agent->phone, $player->agent->email])->filter()->implode(' · ') }}</span>@endif</td>
        <td><b>Origem do cadastro:</b> {{ $player->data_origin->getLabel() }}</td></tr>
    <tr><td><b>Situação de mercado:</b> {{ $player->market_status->getLabel() }}</td>
        <td><b>Detentor dos direitos:</b> {{ $player->rightsHolderClub?->name ?? 'não informado' }}{{ $player->economic_rights_note ? ' · '.$player->economic_rights_note : '' }}</td>
        <td>@if ($market !== null)<b>Faixa salarial:</b> {{ $player->salaryRangeLabel() }}@if ($player->asking_price !== null)<br><b>Pedida:</b> {{ $player->asking_price_currency }} {{ number_format((float) $player->asking_price, 0, ',', '.') }}@endif @else <span class="muted">Valores financeiros omitidos</span>@endif</td></tr>
</table>

<h2>Papéis táticos</h2>
@if ($player->roleAssessments->isEmpty())<p class="muted">Não avaliado (sem classificação registrada).</p>@else
<table><tr><th>Papel</th><th>Nível</th><th>Tipo</th><th>Confiança</th><th>Período</th><th>Evidência</th><th>Autor/data</th></tr>
@foreach ($player->roleAssessments as $a)<tr><td>{{ $a->tacticalRole->name }}<br><span class="muted">{{ $a->tacticalRole->position_group }} · v{{ $a->role_version }}</span></td><td>{{ $a->level->getLabel() }}</td><td>{{ $a->kind->getLabel() }}</td><td>{{ $a->confidence->getLabel() }}</td><td>{{ $a->period_label ?? '—' }}</td><td>{{ $a->evidence }}</td><td>{{ $a->author?->name }}<br>{{ $a->updated_at->format('d/m/Y') }}</td></tr>@endforeach
</table>@endif

<h2>Especialidades</h2>
@if ($player->specialtyAssessments->isEmpty())<p class="muted">Nenhuma registrada.</p>@else
<table><tr><th>Especialidade</th><th>Nível</th><th>Amostra</th><th>Período</th><th>Evidência</th></tr>
@foreach ($player->specialtyAssessments as $s)<tr><td>{{ $s->specialty->name }}</td><td>{{ $s->level->getLabel() }}</td>
<td>@if (($s->sample_attempts ?? 0) > 0 && $s->sample_successes !== null){{ $s->sample_successes }}/{{ $s->sample_attempts }} ({{ number_format($s->success_rate * 100, 0, ',', '.') }}%)@if ($s->sample_attempts < 5) <span class="warn">amostra pequena</span>@endif @else sem amostra @endif</td>
<td>{{ $s->period_label ?? '—' }}</td><td>{{ $s->evidence }}</td></tr>@endforeach</table>@endif

<h2>Relatórios de scout submetidos ({{ $reports->count() }})</h2>
@forelse ($reports as $r)
<div class="box">
    <b>{{ $r->tacticalRole?->name ?? 'Papel não informado' }}</b> — Nível <b>{{ $r->level->getLabel() }}</b> · Parecer: <b>{{ $r->verdict->getLabel() }}</b> · Confiança {{ $r->confidence->getLabel() }}
    <div class="muted">{{ $r->author->name }} · {{ $r->submitted_at?->timezone($tz)->format('d/m/Y') }} · Fonte: {{ $r->source_type->getLabel() }} · {{ $r->match_label ?? 'jogo não informado' }} {{ $r->match_date?->format('d/m/Y') }} {{ $r->competition?->name }} {{ $r->formation }}</div>
    @if ($r->criteria_levels)<div style="margin-top:3px">@foreach ($r->criteria_levels as $c)<span class="tag">{{ $c['criterion'] }}: {{ \App\Enums\Level::tryFrom($c['level'])?->getLabel() ?? $c['level'] }}</span>@endforeach</div>@endif
    @if ($r->context)<p><b>Contexto:</b> {{ $r->context }}</p>@endif
    <p><b>Favoráveis:</b> {{ $r->positives }}</p><p><b>Desfavoráveis:</b> {{ $r->negatives }}</p>
    @if ($r->open_questions)<p><b>Pendências:</b> {{ $r->open_questions }}</p>@endif
</div>
@empty <p class="muted">Nenhum relatório submetido visível para você.</p> @endforelse
@if ($reports->pluck('level')->filter(fn ($l) => $l->rank())->unique()->count() > 1)<p class="warn"><b>Divergência entre avaliadores:</b> níveis diferentes registrados — ver relatórios acima.</p>@endif

<h2>Vídeos referenciados</h2>
@if ($player->videoReferences->isEmpty())<p class="muted">Nenhum.</p>@else
<table><tr><th>Jogo</th><th>Trecho</th><th>Ação</th><th>Leitura</th><th>Link</th></tr>
@foreach ($player->videoReferences as $v)<tr><td>{{ $v->match_label ?? '—' }}</td><td>{{ $v->starts_at }}{{ $v->ends_at ? '–'.$v->ends_at : '' }}</td><td>{{ $v->tag }}</td><td>{{ $v->polarity }}</td><td style="font-size:7pt">{{ $v->url }}</td></tr>@endforeach</table>@endif

<h2>Contrato e mercado (observações)</h2>
@if ($player->contractObservations->isEmpty())<p class="muted">Sem observação contratual.</p>@else
<table><tr><th>Observado em</th><th>Situação</th><th>Clube</th><th>Fim</th><th>Confirmação</th><th>Fonte</th></tr>
@foreach ($player->contractObservations as $c)<tr><td>{{ $c->observed_on->format('d/m/Y') }}</td><td>{{ $c->status->getLabel() }}</td><td>{{ $c->club?->name ?? '—' }}</td><td>{{ $c->contract_end?->format('d/m/Y') ?? 'não informado' }}</td><td>{{ $c->confirmation->getLabel() }}</td><td>{{ $c->source_label ?? '—' }}</td></tr>@endforeach</table>@endif
@if ($market === null)<p class="muted">Valores financeiros omitidos (sem permissão financeira).</p>
@elseif ($market->isEmpty())<p class="muted">Sem valores informados — viabilidade financeira: não informada.</p>
@else<table><tr><th>Data</th><th>Componente</th><th class="num">Valor</th><th>Periodicidade</th><th>Confirmação</th><th>Fonte</th></tr>
@foreach ($market as $m)<tr><td>{{ $m->observed_on->format('d/m/Y') }}</td><td>{{ $m->kind->getLabel() }}</td><td class="num">{{ $m->amount !== null ? $m->currency.' '.number_format((float) $m->amount, 2, ',', '.') : 'não informado' }}{{ $m->amount_max ? ' – '.number_format((float) $m->amount_max, 2, ',', '.') : '' }}</td><td>{{ $m->periodicity ?? '—' }}</td><td>{{ $m->confirmation->getLabel() }}</td><td>{{ $m->source_label ?? '—' }}</td></tr>@endforeach</table>
<p class="muted">Valor de mercado é referência externa: não é preço negociado nem salário.</p>@endif

<h2>Prospecção</h2>
@forelse ($player->prospectCases as $c)<div>{{ $c->need->title }} — <b>{{ $c->stage->name }}</b>{{ $c->next_action ? ' · próxima ação: '.$c->next_action : '' }}</div>
@empty <p class="muted">Fora do quadro de prospecção.</p> @endforelse

<h2>Pendências de diligência</h2>
<ul>
    @if (! $player->birth_date)<li>Confirmar data de nascimento.</li>@endif
    @if (! $player->cbf_id)<li>Confirmar ID CBF / registro (BID).</li>@endif
    @if ($player->contractObservations->isEmpty())<li>Levantar situação contratual.</li>@endif
    @if ($player->roleAssessments->isEmpty())<li>Classificar papel tático com evidência.</li>@endif
    @if ($reports->isEmpty())<li>Relatório de scout submetido.</li>@endif
    <li>Diligências médica, jurídica e financeira ficam com as áreas responsáveis (fora desta base).</li>
</ul>
</body></html>
