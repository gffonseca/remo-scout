@php use App\Support\PlayerStatsView as SV; $fetched = $rows->map(fn ($r) => $r->fetched_at)->filter()->max(); @endphp
<div class="sc-muted" style="margin:.35rem 0 .2rem"><strong>{{ SV::providerLabel($prov) }}</strong>@if (SV::providerNote($prov)) · {{ SV::providerNote($prov) }}@endif
    · coletado em {{ $fetched ? $fetched->timezone(config('scout.display_timezone'))->format('d/m/Y') : 'data não registrada' }}</div>
<table class="sc-stats">
    <thead><tr><th>Competição</th><th>Clube</th><th>Temp.</th><th title="Jogos em que entrou">J</th><th title="Titular">Tit</th><th title="Minutos">Min</th><th title="Gols">G</th><th title="Assistências">A</th><th title="Gols por 90 min (só com minutos completos)">G/90</th><th title="Cartões amarelos/vermelhos">Cart.</th>@if ($prov === 'sofascore')<th title="Nota Sofascore (proprietária)">Nota</th>@endif
        @if ($prov === 'api_football')<th title="Nota API-Football (proprietária), média ponderada por minutos">Nota</th><th title="Finalizações (no alvo)">Fin</th><th title="Passes decisivos">PD</th><th title="Desarmes / interceptações">Des/Int</th><th title="Duelos ganhos / disputados">Duelos</th>@endif</tr></thead>
    <tbody>
    @foreach ($rows as $r)
        @php $g90 = SV::goalsPer90($r); @endphp
        <tr>
            <td title="{{ $r->competition_name }}">{{ \Illuminate\Support\Str::limit($r->competition_name, 32) }}</td>
            <td>{{ $r->club_name ? \Illuminate\Support\Str::limit($r->club_name, 18) : '—' }}</td>
            <td>{{ $r->season }}</td>
            <td @if (SV::appearances($r) !== (string) $r->appearances && $r->appearances !== null) title="Em {{ $r->extra['participacao_indeterminada'] }} jogo(s) a fonte não registrou substituições: participação do reserva desconhecida." style="cursor:help" @endif>{{ SV::appearances($r) }}</td><td>{{ $r->starts ?? '—' }}</td>
            <td @if (SV::minutesTitle($r)) title="{{ SV::minutesTitle($r) }}" style="cursor:help" @endif>{{ SV::minutes($r) }}</td>
            <td @if (SV::goals($r) !== (string) $r->goals) title="Há jogos em que os gols listados não conferem com o placar: total mínimo." style="cursor:help" @endif>{{ SV::goals($r) }}</td>
            <td>{{ $r->assists ?? '—' }}</td>
            <td>@if ($g90 !== null){{ number_format($g90, 2, ',', '') }}@if (SV::smallSample($r))<span title="Amostra pequena (menos de {{ config('scout.min_minutes_ranking') }} min)" style="cursor:help">*</span>@endif @else — @endif</td>
            <td>{{ ($r->yellow_cards ?? '—').'/'.($r->red_cards ?? '—') }}</td>
            @if ($prov === 'sofascore')<td>{{ $r->rating !== null ? number_format((float) $r->rating, 2, ',', '') : '—' }}</td>@endif
            @if ($prov === 'api_football')
                @php $e = $r->extra['estatistica'] ?? []; $cov = SV::detailCoverage($r);
                    $v = fn ($k) => array_key_exists($k, $e) ? ($cov ? '≥'.$e[$k] : $e[$k]) : null; @endphp
                <td @if ($cov) title="Estatística individual em {{ $cov }}" style="cursor:help" @endif>{{ $r->rating !== null ? number_format((float) $r->rating, 2, ',', '') : '—' }}@if ($cov)<span class="sc-muted"> ({{ $cov }})</span>@endif</td>
                <td>{{ $v('finalizacoes') ?? '—' }}@if ($v('finalizacoes_no_alvo') !== null) ({{ $v('finalizacoes_no_alvo') }})@endif</td>
                <td>{{ $v('passes_decisivos') ?? '—' }}</td>
                <td>{{ ($v('desarmes') ?? '—').'/'.($v('interceptacoes') ?? '—') }}</td>
                <td>{{ $v('duelos') !== null ? ($v('duelos_ganhos') ?? '?').'/'.$v('duelos') : '—' }}</td>
            @endif
        </tr>
    @endforeach
    </tbody>
</table>
