<x-filament-panels::page>
    @php $rows = $this->rows(); @endphp
    <div class="sc-muted" style="font-size:.85rem;line-height:1.5">
        Fonte: football-data.co.uk (resultados e odds de fechamento; Brasil só Série A). Probabilidade implícita da média de mercado,
        sem a margem da casa. <strong>Pontos esperados</strong> = 3 × p(vitória) + p(empate), por jogo. Diferença positiva = clube rendendo
        acima do que o mercado esperava. É contexto do time, não avaliação de atleta.
        @if ($this->updatedAt()) Atualizado em {{ $this->updatedAt() }}. @endif
    </div>
    <div style="display:flex;gap:.5rem;align-items:center;margin:.5rem 0">
        <label for="season" class="sc-muted">Temporada</label>
        <select id="season" wire:model.live="season" class="fi-select-input" style="border:1px solid #ccc;border-radius:.4rem;padding:.2rem .5rem">
            @foreach ($this->seasons() as $s)<option value="{{ $s }}">{{ $s }}</option>@endforeach
        </select>
    </div>
    @if ($rows->isEmpty())
        <p class="sc-muted">Sem dados importados. Em Fontes de dados, use "Importar agora" na fonte football-data.co.uk.</p>
    @else
        <table class="sc-stats" style="width:100%">
            <thead><tr><th>#</th><th>Clube</th><th>Jogos</th><th title="Pontos reais por jogo">Pts/J</th><th title="Pontos esperados por jogo pelo mercado">Esperado/J</th><th title="Real − esperado, por jogo">Diferença</th><th title="Probabilidade média de vitória atribuída pelo mercado">p(vitória)</th></tr></thead>
            <tbody>
            @foreach ($rows as $i => $r)
                <tr>
                    <td>{{ $i + 1 }}</td>
                    <td>{!! \App\Support\Media::crestTag($r->club, 16) !!}{{ $r->club?->name ?? '—' }}</td>
                    <td>{{ $r->jogos }}@if ($r->jogos < 10)<span title="Amostra pequena" style="cursor:help">*</span>@endif</td>
                    <td>{{ number_format($r->pontos_por_jogo, 2, ',', '') }}</td>
                    <td>{{ number_format($r->pontos_esperados, 2, ',', '') }}</td>
                    <td style="color:{{ $r->diferenca >= 0 ? '#16a34a' : '#dc2626' }}">{{ ($r->diferenca >= 0 ? '+' : '').number_format($r->diferenca, 2, ',', '') }}</td>
                    <td>{{ number_format($r->prob_vitoria_media * 100, 0) }}%</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @endif
</x-filament-panels::page>
