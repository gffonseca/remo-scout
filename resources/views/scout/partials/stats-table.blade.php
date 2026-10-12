@php use App\Support\PlayerStatsView as SV; $fetched = $rows->map(fn ($r) => $r->fetched_at)->filter()->max(); @endphp
<div class="sc-muted" style="margin:.35rem 0 .2rem"><strong>{{ SV::providerLabel($prov) }}</strong>@if (SV::providerNote($prov)) · {{ SV::providerNote($prov) }}@endif
    · coletado em {{ $fetched ? $fetched->timezone(config('scout.display_timezone'))->format('d/m/Y') : 'data não registrada' }}</div>
<table class="sc-stats">
    <thead><tr><th>Competição</th><th>Clube</th><th>Temp.</th><th title="Jogos em que entrou">J</th><th title="Titular">Tit</th><th title="Minutos">Min</th><th title="Gols">G</th><th title="Assistências">A</th><th title="Gols por 90 min (só com minutos completos)">G/90</th><th title="Cartões amarelos/vermelhos">Cart.</th>@if ($prov === 'sofascore')<th title="Nota Sofascore (proprietária)">Nota</th>@endif</tr></thead>
    <tbody>
    @foreach ($rows as $r)
        @php $g90 = SV::goalsPer90($r); @endphp
        <tr>
            <td title="{{ $r->competition_name }}">{{ \Illuminate\Support\Str::limit($r->competition_name, 32) }}</td>
            <td>{{ $r->club_name ? \Illuminate\Support\Str::limit($r->club_name, 18) : '—' }}</td>
            <td>{{ $r->season }}</td>
            <td>{{ $r->appearances ?? '—' }}</td><td>{{ $r->starts ?? '—' }}</td>
            <td @if (SV::minutesTitle($r)) title="{{ SV::minutesTitle($r) }}" style="cursor:help" @endif>{{ SV::minutes($r) }}</td>
            <td @if (SV::goals($r) !== (string) $r->goals) title="Há jogos em que os gols listados não conferem com o placar: total mínimo." style="cursor:help" @endif>{{ SV::goals($r) }}</td>
            <td>{{ $r->assists ?? '—' }}</td>
            <td>@if ($g90 !== null){{ number_format($g90, 2, ',', '') }}@if (SV::smallSample($r))<span title="Amostra pequena (menos de {{ config('scout.min_minutes_ranking') }} min)" style="cursor:help">*</span>@endif @else — @endif</td>
            <td>{{ ($r->yellow_cards ?? '—').'/'.($r->red_cards ?? '—') }}</td>
            @if ($prov === 'sofascore')<td>{{ $r->rating !== null ? number_format((float) $r->rating, 2, ',', '') : '—' }}</td>@endif
        </tr>
    @endforeach
    </tbody>
</table>
