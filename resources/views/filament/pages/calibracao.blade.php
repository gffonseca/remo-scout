<x-filament-panels::page>
    @php $rows = $this->rows(); @endphp
    <p class="sc-muted">Mesmo atleta, papel e fase avaliados (observado) por analistas diferentes em até {{ \App\Services\Calibration::WINDOW_DAYS }} dias, com níveis diferentes.
        Vale a última avaliação de cada analista. Use na reunião: rever o vídeo juntos e ajustar o critério A–D do papel, se for o caso.</p>
    <table class="sc-stats" style="width:100%">
        <thead><tr><th>Atleta</th><th>Papel · fase</th><th>Diferença</th><th>Avaliações</th></tr></thead>
        <tbody>
        @forelse ($rows as $r)
            <tr>
                <td><a href="{{ \App\Filament\Resources\Players\PlayerResource::getUrl('view', ['record' => $r['player']]) }}" style="font-weight:700">{{ $r['player']->name }}</a></td>
                <td>{{ $r['role'] }} · {{ $r['phase'] }}</td>
                <td><x-filament::badge :color="$r['gap'] >= 2 ? 'danger' : 'warning'">{{ $r['gap'] }} nível(is)</x-filament::badge></td>
                <td style="font-size:.85rem">
                    @foreach ($r['items'] as $a)
                        <div><strong>{{ $a->level->value }}</strong> — {{ $a->author?->name ?? '—' }} em {{ $a->observed_on?->format('d/m/Y') }}
                            <span class="sc-muted">{{ \Illuminate\Support\Str::limit($a->evidence, 90) }}</span></div>
                    @endforeach
                </td>
            </tr>
        @empty
            <tr><td colspan="4" class="sc-muted">Sem divergências no período. (Ou ainda não há duas avaliações do mesmo atleta, papel e fase por analistas diferentes.)</td></tr>
        @endforelse
        </tbody>
    </table>
</x-filament-panels::page>
