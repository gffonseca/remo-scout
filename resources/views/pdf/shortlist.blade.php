<!doctype html>
<html lang="pt-BR"><head><meta charset="utf-8"><title>Shortlist — {{ $need->title }}</title>@include('pdf._style')</head>
<body>
@php($tz = config('scout.display_timezone'))
<header class="top"><strong>CIFUT Remo</strong> · Shortlist · <strong>USO INTERNO — PILOTO</strong></header>
<footer class="bot">Gerado em {{ now()->timezone($tz)->format('d/m/Y H:i') }} por {{ $user->name }}. Ordem definida pelo departamento; não é ranking automático nem garantia de desempenho.</footer>
<h1>{{ $need->title }}</h1>
<div class="muted">{{ $need->position->getLabel() }} · {{ $need->tacticalRole?->name ?? 'papel não definido' }} · Prioridade {{ $need->priority->getLabel() }} · {{ $need->horizon->getLabel() }} {{ $need->window_label }}</div>
@if ($need->mandatory_requirements)<p><b>Obrigatórios:</b> {{ implode(' · ', $need->mandatory_requirements) }}</p>@endif
@if ($user->canViewFinancial())<p><b>Condições financeiras:</b> {{ $need->budgetLabel() }}</p>@endif
<table>
<tr><th>#</th><th>Atleta</th><th>Pos./idade</th><th>Clube</th><th>Etapa</th><th>Requisitos</th><th>Nível no papel</th><th>Contrato</th><th>Relatórios (nível/parecer)</th><th>Justificativa</th></tr>
@forelse ($cases as $c)
@php($p = $c->player)
<tr>
    <td>{{ $c->shortlist_rank }}</td><td><b>{{ $p->name }}</b></td>
    <td>{{ $p->primary_position?->value }} · {{ $p->age ?? '?' }}</td><td>{{ $p->currentClub?->name ?? '—' }}</td><td>{{ $c->stage->name }}</td>
    <td>{{ \App\Services\RequirementCheck::summary($need, $p) }}</td>
    <td>{{ $need->tactical_role_id ? ($p->roleAssessments->where('tactical_role_id', $need->tactical_role_id)->sortByDesc('updated_at')->first()?->level?->getLabel() ?? 'Não avaliado') : '—' }}</td>
    <td>{{ $p->latestContract?->status?->getLabel() ?? 'sem obs.' }}{{ $p->latestContract?->contract_end ? ' até '.$p->latestContract->contract_end->format('m/Y') : '' }}</td>
    <td>@foreach ($reportsByPlayer->get($p->id, collect()) as $r)<div>{{ $r->author->name }}: {{ $r->level->value === 'nao_observado' ? 'n/o' : $r->level->value }} / {{ $r->verdict->getLabel() }}</div>@endforeach</td>
    <td>{{ $c->justification ?? '—' }}</td>
</tr>
@empty <tr><td colspan="10" class="muted">Shortlist vazia.</td></tr> @endforelse
</table>
</body></html>
