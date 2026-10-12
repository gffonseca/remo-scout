<?php

namespace App\Http\Controllers;

use App\Models\AuditEvent;
use App\Models\Player;
use App\Models\RecruitmentNeed;
use App\Models\ScoutingReport;
use App\Services\RequirementCheck;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\CSV\Options as CsvOptions;
use OpenSpout\Writer\CSV\Writer as CsvWriter;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;

/** Exportações respeitam as mesmas permissões das telas e ficam registradas na auditoria. */
class ExportController extends Controller
{
    public function playerDossier(Request $request, Player $player)
    {
        Gate::authorize('view', $player);
        $user = $request->user();
        $player->load(['agent', 'rightsHolderClub', 'currentClub', 'contractObservations.club', 'roleAssessments.tacticalRole', 'roleAssessments.author', 'specialtyAssessments.specialty', 'videoReferences', 'prospectCases.need', 'prospectCases.stage', 'rosterSpells.club', 'externalIds']);
        $reports = ScoutingReport::query()->visibleTo($user)->where('player_id', $player->id)->where('status', 'submetido')
            ->with(['author', 'tacticalRole', 'competition'])->latest()->get();
        $market = $user->canViewFinancial() ? $player->marketObservations()->get() : null;
        AuditEvent::record('export_dossie', $player);

        return Pdf::loadView('pdf.player-dossier', compact('player', 'reports', 'market', 'user'))
            ->setPaper('a4')
            ->stream('dossie-'.Str::slug($player->name).'-'.now()->format('Ymd').'.pdf');
    }

    public function shortlistDossier(Request $request, RecruitmentNeed $need)
    {
        Gate::authorize('view', $need);
        $user = $request->user();
        $cases = $need->shortlist()->with(['player.currentClub', 'player.latestContract', 'player.roleAssessments.tacticalRole', 'stage', 'owner'])->get();
        $reportsByPlayer = ScoutingReport::query()->visibleTo($user)->whereIn('player_id', $cases->pluck('player_id'))
            ->where('status', 'submetido')->with(['author', 'tacticalRole'])->latest()->get()->groupBy('player_id');
        AuditEvent::record('export_shortlist_pdf', $need);

        return Pdf::loadView('pdf.shortlist', compact('need', 'cases', 'reportsByPlayer', 'user'))
            ->setPaper('a4', 'landscape')
            ->stream('shortlist-'.Str::slug($need->title).'-'.now()->format('Ymd').'.pdf');
    }

    public function shortlistSheet(Request $request, RecruitmentNeed $need, string $format)
    {
        Gate::authorize('view', $need);
        abort_unless(in_array($format, ['csv', 'xlsx'], true), 404);
        $cases = $need->shortlist()->with(['player.currentClub', 'player.latestContract', 'player.roleAssessments', 'stage', 'owner'])->get();
        AuditEvent::record('export_shortlist_'.$format, $need);

        $header = ['Ordem', 'Atleta', 'Nome completo', 'Posição', 'Idade', 'Clube', 'Etapa', 'Requisitos obrigatórios', 'Nível no papel desejado', 'Situação contratual', 'Fim de contrato (obs.)', 'Justificativa', 'Responsável', 'ID CBF', 'Exportado em', 'Uso interno — piloto'];
        $rows = $cases->map(fn ($c) => [
            $c->shortlist_rank, $c->player->name, $c->player->full_name, $c->player->primary_position?->value, $c->player->age,
            $c->player->currentClub?->name, $c->stage->name, RequirementCheck::summary($need, $c->player),
            $need->tactical_role_id ? ($c->player->roleAssessments->where('tactical_role_id', $need->tactical_role_id)->sortByDesc('updated_at')->first()?->level?->getLabel() ?? 'Não avaliado') : '',
            $c->player->latestContract?->status?->getLabel(), $c->player->latestContract?->contract_end?->format('d/m/Y'),
            $c->justification, $c->owner?->name, $c->player->cbf_id, now()->timezone(config('scout.display_timezone'))->format('d/m/Y H:i'), '',
        ]);

        $name = 'shortlist-'.Str::slug($need->title).'-'.now()->format('Ymd').'.'.$format;

        return response()->streamDownload(function () use ($format, $header, $rows) {
            if ($format === 'csv') {
                $o = new CsvOptions;
                $o->FIELD_DELIMITER = ';';
                $o->SHOULD_ADD_BOM = true;
                $w = new CsvWriter($o);
            } else {
                $w = new XlsxWriter;
            }
            $w->openToFile('php://output');
            $w->addRow(Row::fromValues($header));
            foreach ($rows as $r) {
                $w->addRow(Row::fromValues(array_map([static::class, 'safeCell'], $r)));
            }
            $w->close();
        }, $name, ['Content-Type' => $format === 'csv' ? 'text/csv; charset=UTF-8' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    /** Proteção contra injeção de fórmula em planilhas (=, +, -, @, tab, CR). */
    public static function safeCell(mixed $v): mixed
    {
        if (is_string($v) && $v !== '' && in_array($v[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'".$v;
        }

        return $v;
    }
}
