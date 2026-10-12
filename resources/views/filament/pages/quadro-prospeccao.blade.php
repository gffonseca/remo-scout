<x-filament-panels::page>
    <div class="flex flex-wrap items-end gap-3">
        <div class="min-w-72">
            <label class="text-xs text-gray-400">Necessidade</label>
            <x-filament::input.wrapper>
                <x-filament::input.select wire:model.live="need">
                    <option value="">Todas as necessidades abertas</option>
                    @foreach ($this->getNeeds() as $id => $title)<option value="{{ $id }}">{{ $title }}</option>@endforeach
                </x-filament::input.select>
            </x-filament::input.wrapper>
        </div>
        <div class="min-w-56">
            <label class="text-xs text-gray-400">Responsável</label>
            <x-filament::input.wrapper>
                <x-filament::input.select wire:model.live="owner">
                    <option value="">Todos</option>
                    @foreach ($this->getOwners() as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                </x-filament::input.select>
            </x-filament::input.wrapper>
        </div>
        <label class="flex items-center gap-2 text-sm text-gray-300"><input type="checkbox" wire:model.live="showArchived" class="rounded"> Mostrar arquivados</label>
    </div>

    <div class="scout-kanban" role="list" aria-label="Etapas de prospecção">
        @foreach ($this->getColumns() as $col)
            <section class="scout-kanban-col" role="listitem" aria-label="{{ $col['stage']->name }}">
                <header class="mb-2 flex items-center justify-between px-1">
                    <h3 class="text-sm font-semibold">{{ $col['stage']->name }}</h3>
                    <x-filament::badge :color="$col['stage']->color">{{ $col['cases']->count() }}</x-filament::badge>
                </header>
                @forelse ($col['cases'] as $case)
                    <article class="scout-card" wire:key="case-{{ $case->id }}">
                        <div class="flex items-start justify-between gap-2">
                            <a href="{{ \App\Filament\Resources\Players\PlayerResource::getUrl('view', ['record' => $case->player_id]) }}" class="flex items-center gap-2 font-semibold hover:underline">
                                <img src="{{ $case->player->photoUrl() ?? asset('images/player-silhouette.svg') }}" alt="" referrerpolicy="no-referrer" loading="lazy" style="width:28px;height:28px;border-radius:50%;object-fit:cover;object-position:top" onerror="this.onerror=null;this.src='{{ asset('images/player-silhouette.svg') }}'">
                                {{ $case->player->name }}</a>
                            <x-filament::badge size="sm" :color="$case->priority->getColor()">{{ $case->priority->getLabel() }}</x-filament::badge>
                        </div>
                        <div class="text-xs text-gray-400">
                            {!! \App\Support\Media::crestTag($case->player->currentClub, 14) !!}{{ collect([$case->player->primary_position?->value, $case->player->age ? $case->player->age.' anos' : null, $case->player->currentClub?->name])->filter()->implode(' · ') }}
                        </div>
                        @unless ($this->need)<div class="mt-1 text-xs text-primary-300">{{ $case->need->title }}</div>@endunless
                        @if ($case->next_action)
                            <div class="mt-1 text-xs">→ {{ $case->next_action }}
                                @if ($case->due_on)<span @class(['tabular-nums', 'text-danger-400 font-semibold' => $case->due_on->isPast()])>({{ $case->due_on->format('d/m') }})</span>@endif
                            </div>
                        @endif
                        <div class="mt-1 flex items-center justify-between text-xs text-gray-500">
                            <span>{{ $case->owner?->name ?? 'sem responsável' }}</span>
                            {{ ($this->moveAction)(['case' => $case->id]) }}
                        </div>
                    </article>
                @empty
                    <p class="px-1 text-xs text-gray-500">Vazio</p>
                @endforelse
            </section>
        @endforeach
    </div>
    <x-filament-actions::modals />
</x-filament-panels::page>
