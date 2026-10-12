<div class="hidden md:flex items-center gap-3 text-xs text-gray-400" style="margin-inline-start:1rem;padding-inline-start:1rem;border-inline-start:1px solid rgba(241,245,250,.15)">
    <span class="font-semibold text-gray-200">Clube do Remo · Futebol profissional</span>
    <span>Temporada {{ config('scout.season_label') }}</span>
    @if (config('scout.demo_mode'))
        <span class="scout-pill" style="color:#E9B65B">MODO DEMONSTRAÇÃO — dados sintéticos</span>
    @endif
</div>
