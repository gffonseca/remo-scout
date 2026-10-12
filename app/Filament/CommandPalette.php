<?php

namespace App\Filament;

use App\Filament\Resources\RecruitmentNeeds\RecruitmentNeedResource;
use App\Models\RecruitmentNeed;
use Filament\Facades\Filament;
use Filament\GlobalSearch\GlobalSearchResult;
use Filament\GlobalSearch\GlobalSearchResults;
use Filament\GlobalSearch\Providers\Contracts\GlobalSearchProvider;
use Filament\GlobalSearch\Providers\DefaultGlobalSearchProvider;
use Illuminate\Support\Str;

/**
 * Paleta de comandos (Ctrl+K / ⌘K): ações e telas primeiro, depois os registros da busca global.
 * Só lista o que o usuário pode abrir (mesmas regras de acesso das telas). Começar com ">" mostra só ações.
 */
class CommandPalette implements GlobalSearchProvider
{
    /** Sinônimos para achar a tela pelo que o analista digita. */
    private const KEYWORDS = [
        'Comparador' => 'comparar lado a lado fixar',
        'Watchlist' => 'monitorar acompanhar alertas radar',
        'QuadroProspeccao' => 'kanban funil pipeline quadro',
        'Calibracao' => 'divergencia analistas niveis',
        'PlanejadorElenco' => 'elenco carencias plantel titulares profundidade',
        'CentralDeMercado' => 'inicio painel mercado',
        'ImportarPlanilha' => 'importar csv excel planilha',
    ];

    private const MAX = 8;

    public function getResults(string $query): ?GlobalSearchResults
    {
        $actionsOnly = str_starts_with(ltrim($query), '>');
        $q = trim(ltrim(trim($query), '>'));
        $out = GlobalSearchResults::make();

        $commands = array_slice($this->commands($q), 0, self::MAX);
        if ($commands) {
            $out->category('Atalhos', $commands);
        }
        if ($actionsOnly || $q === '') {
            return $out;
        }
        foreach ((new DefaultGlobalSearchProvider)->getResults($q)?->getCategories() ?? [] as $name => $results) {
            $out->category($name, $results);
        }

        return $out;
    }

    /** @return list<GlobalSearchResult> */
    public function commands(string $q): array
    {
        $items = [];
        foreach (Filament::getResources() as $resource) {
            if (! $resource::canViewAny()) {
                continue;
            }
            $label = $resource::getNavigationLabel();
            $items[] = [$label, $resource::getUrl('index'), 'Abrir lista', class_basename($resource)];
            if ($resource::hasPage('create') && $resource::canCreate()) {
                $items[] = ['Novo(a) '.$resource::getModelLabel(), $resource::getUrl('create'), 'Criar registro', 'novo criar cadastrar adicionar '.class_basename($resource)];
            }
        }
        foreach (Filament::getPages() as $page) {
            if (! $page::canAccess()) {
                continue;
            }
            $base = class_basename($page);
            $items[] = [$page::getNavigationLabel(), $page::getUrl(), 'Abrir tela', $base.' '.(self::KEYWORDS[$base] ?? '')];
        }
        if (RecruitmentNeedResource::canViewAny()) {
            foreach (RecruitmentNeed::where('status', 'aberta')->orderBy('title')->get(['id', 'title', 'position']) as $n) {
                $items[] = ['Pesquisar candidatos: '.$n->title, RecruitmentNeedResource::getUrl('search', ['record' => $n]), 'Necessidade aberta · '.$n->position?->value, 'pesquisa buscar candidatos necessidade '.$n->position?->value];
            }
        }

        $tokens = array_filter(explode(' ', $this->norm($q)));
        $matched = [];
        foreach ($items as [$title, $url, $detail, $kw]) {
            $hay = $this->norm($title.' '.$kw);
            if ($tokens && collect($tokens)->contains(fn ($t) => ! str_contains($hay, $t))) {
                continue;
            }
            // título que começa com o termo vem antes
            $score = $tokens && str_starts_with($this->norm($title), $tokens[array_key_first($tokens)]) ? 0 : 1;
            $matched[] = [$score, $title, new GlobalSearchResult($title, $url, [$detail])];
        }
        usort($matched, fn ($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        return array_column($matched, 2);
    }

    private function norm(string $s): string
    {
        return Str::lower(Str::ascii($s));
    }
}
