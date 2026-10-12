<?php

namespace App\Services;

use App\Enums\RequirementStatus;
use App\Models\Player;
use App\Models\ProspectCase;
use App\Models\RecruitmentNeed;
use Illuminate\Support\Collection;

/**
 * Pesquisa da necessidade (F11): os mesmos critérios e estados da necessidade (RequirementCheck), aplicados à base.
 * Universo: atletas com a posição (principal ou secundária) e atletas sem posição informada (pendentes).
 * Classe: atende (tudo confirmado) · pendente (nada reprovado, algo desconhecido) · nao_atende.
 * Cadastros provisórios de partidas ficam fora (não são atletas do scout).
 */
class NeedSearch
{
    public const CLASSES = ['atende' => 'Atende', 'pendente' => 'Com pendências', 'nao_atende' => 'Não atende'];

    /** @return Collection<int, array{player:Player, items:list<array>, class:string, pending:list<string>, failed:list<string>, in_pipeline:bool}> */
    public static function run(RecruitmentNeed $need): Collection
    {
        $pos = $need->position->value;
        $inPipeline = ProspectCase::where('recruitment_need_id', $need->id)->pluck('player_id')->flip();

        $players = Player::query()
            ->whereNot(fn ($w) => $w->provisional())
            ->where(fn ($q) => $q->where('primary_position', $pos)->orWhereJsonContains('positions', $pos)
                ->orWhere(fn ($u) => $u->whereNull('primary_position')->where(fn ($x) => $x->whereNull('positions')->orWhereJsonLength('positions', 0))))
            ->with(['currentClub', 'roleAssessments.author', 'specialtyAssessments'])
            ->get();
        $ctx = RequirementCheck::context($need, $players); // dados da necessidade uma vez só (sem consulta por atleta)

        return $players
            ->map(function (Player $p) use ($need, $inPipeline, $ctx) {
                $items = RequirementCheck::evaluate($need, $p, null, $ctx);
                $failed = collect($items)->where('status', RequirementStatus::NaoAtende->value)->pluck('item')->all();
                $pending = collect($items)->where('status', RequirementStatus::Pendente->value)->pluck('item')->all();

                return ['player' => $p, 'items' => $items, 'failed' => $failed, 'pending' => $pending,
                    'class' => $failed ? 'nao_atende' : ($pending ? 'pendente' : 'atende'), 'in_pipeline' => $inPipeline->has($p->id)];
            })
            ->sortBy([fn ($a, $b) => array_search($a['class'], array_keys(self::CLASSES)) <=> array_search($b['class'], array_keys(self::CLASSES)),
                fn ($a, $b) => count($a['pending']) <=> count($b['pending']), fn ($a, $b) => strcmp($a['player']->name, $b['player']->name)])
            ->values();
    }

    /** Filtro explícito: classe(s) e, opcionalmente, só quem tem pendência num critério. */
    public static function filter(Collection $rows, string $show, ?string $pendingIn = null): Collection
    {
        $classes = match ($show) {
            'atende' => ['atende'],
            'pendente' => ['pendente'],
            'nao_atende' => ['nao_atende'],
            'todos' => array_keys(self::CLASSES),
            default => ['atende', 'pendente'], // viáveis
        };

        return $rows->filter(fn ($r) => in_array($r['class'], $classes, true) && (! $pendingIn || in_array($pendingIn, $r['pending'], true)))->values();
    }

    /** Linhas para exportação: cada critério com estado e motivo (explica por que o atleta entrou). */
    public static function exportRows(RecruitmentNeed $need, Collection $rows, bool $financial): array
    {
        $out = [];
        foreach ($rows as $r) {
            $p = $r['player'];
            $criteria = collect($r['items'])
                ->reject(fn ($i) => ! $financial && $i['kind'] === 'budget')
                ->map(fn ($i) => RequirementStatus::from($i['status'])->getLabel().': '.$i['item'].' ('.$i['detail'].')')->implode(' | ');
            $out[] = [
                'Atleta' => $p->name, 'Clube' => $p->currentClub?->name, 'Idade' => $p->age, 'Pé' => $p->preferred_foot?->getLabel(),
                'Classificação' => self::CLASSES[$r['class']], 'Pendências' => implode(', ', $r['pending']), 'Reprovado em' => implode(', ', $r['failed']),
                'Já no quadro' => $r['in_pipeline'] ? 'sim' : 'não', 'Critérios (estado e motivo)' => $criteria,
                'Necessidade' => $need->title, 'Gerado em' => now(config('scout.display_timezone'))->format('d/m/Y H:i'),
            ];
        }

        return $out;
    }
}
