<?php

namespace App\Services\Import;

use App\Enums\ContractStatus;
use App\Enums\Foot;
use App\Enums\Position;
use App\Models\Club;
use App\Models\ContractObservation;
use App\Models\ImportBatch;
use App\Models\MarketObservation;
use App\Models\Player;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Importação de atletas a partir de planilha com mapeamento de colunas configurável.
 * Regras: nunca une só por nome; ID CBF casa direto; nome+nascimento casa e só preenche campos vazios;
 * nome sem nascimento que coincide com cadastro existente vira "possível homônimo" e é ignorado para revisão.
 */
class PlayerImporter
{
    /** campo do sistema => [rótulo, apelidos para auto-mapeamento] */
    public const FIELDS = [
        'name' => ['Nome de jogo (obrigatório)', ['nome', 'atleta', 'jogador', 'nome de jogo', 'apelido', 'name', 'player']],
        'full_name' => ['Nome completo', ['nome completo', 'full name', 'nome civil']],
        'birth_date' => ['Data de nascimento', ['nascimento', 'data de nascimento', 'dt nasc', 'data nasc', 'birth', 'birth date', 'dob']],
        'age' => ['Idade (usada só se não houver nascimento — não gera data)', ['idade', 'age']],
        'nationality' => ['Nacionalidade', ['nacionalidade', 'nationality', 'país', 'pais']],
        'cbf_id' => ['ID CBF', ['id cbf', 'cbf', 'registro cbf', 'cod cbf']],
        'primary_position' => ['Posição principal', ['posição', 'posicao', 'posição principal', 'pos', 'position']],
        'positions' => ['Outras posições (separadas por vírgula)', ['outras posições', 'outras posicoes', 'posições secundárias', 'posicoes']],
        'preferred_foot' => ['Pé preferencial', ['pé', 'pe', 'perna', 'pé dominante', 'foot']],
        'height_cm' => ['Altura (cm ou m)', ['altura', 'height']],
        'current_club' => ['Clube atual', ['clube', 'clube atual', 'time', 'equipe', 'club', 'team']],
        'agent_name' => ['Representante/empresário', ['empresário', 'empresario', 'agente', 'representante', 'agent']],
        'phone' => ['Telefone do atleta', ['telefone', 'telefone atleta', 'celular', 'fone', 'phone']],
        'whatsapp' => ['WhatsApp do atleta', ['whatsapp', 'whats', 'zap', 'whatsapp atleta']],
        'email' => ['E-mail do atleta', ['email', 'e-mail', 'email atleta']],
        'agent_phone' => ['Telefone/WhatsApp do empresário', ['telefone empresário', 'telefone empresario', 'contato empresário', 'contato empresario', 'whatsapp empresário', 'tel empresário', 'contato']],
        'agent_email' => ['E-mail do empresário', ['email empresário', 'email empresario', 'e-mail empresário']],
        'salary_min' => ['Salário (mínimo da faixa, mensal)', ['salário', 'salario', 'salário mínimo', 'salario min', 'faixa salarial', 'salário de', 'salario de']],
        'salary_max' => ['Salário (máximo da faixa, mensal)', ['salário máximo', 'salario max', 'salário até', 'salario ate']],
        'asking_price' => ['Pedida de transferência', ['pedida', 'valor pedido', 'multa', 'preço', 'preco']],
        'market_status' => ['Situação de mercado', ['situação de mercado', 'mercado', 'disponibilidade']],
        'is_priority' => ['Lista prioritária (sim/não)', ['prioridade', 'prioritário', 'prioritario', 'lista prioritária']],
        'contract_end' => ['Fim de contrato', ['fim de contrato', 'contrato até', 'contrato ate', 'vencimento', 'contract end', 'fim contrato']],
        'contract_status' => ['Situação contratual', ['situação', 'situacao', 'status contrato', 'situação contratual']],
        'market_value' => ['Valor de mercado (referência)', ['valor de mercado', 'valor', 'market value']],
        'sofascore_url' => ['Link Sofascore', ['sofascore']],
        'wyscout_url' => ['Link Wyscout', ['wyscout']],
        'transfermarkt_url' => ['Link Transfermarkt', ['transfermarkt', 'tm']],
        'ogol_url' => ['Link ogol/zerozero', ['ogol', 'zerozero']],
        'notes' => ['Observações', ['observações', 'observacoes', 'obs', 'notas', 'comentários', 'comentarios', 'notes']],
    ];

    /** @param list<string> $headers @return array<string, string|null> */
    public static function guessMapping(array $headers): array
    {
        $norm = fn ($s) => Str::of($s)->lower()->ascii()->replaceMatches('/[^a-z0-9 ]/', ' ')->squish()->toString();
        $map = [];
        foreach (self::FIELDS as $field => [, $aliases]) {
            $map[$field] = null;
            foreach ($headers as $h) {
                $nh = $norm($h);
                foreach ($aliases as $a) {
                    if ($nh === $norm($a)) {
                        $map[$field] = $h;
                        break 2;
                    }
                }
            }
        }

        return $map;
    }

    /**
     * Normaliza e valida as linhas. Retorna cada linha com ação prevista: criar | atualizar | ignorar | erro.
     * @return list<array{row:int, action:string, message:string, data:array, match_id:?string}>
     */
    public static function plan(array $rows, array $mapping, bool $allowFinancial): array
    {
        $plan = [];
        $seenCbf = [];
        foreach ($rows as $i => $raw) {
            $line = $i + 2; // +1 cabeçalho, +1 base 1
            $get = fn (string $f) => isset($mapping[$f]) && $mapping[$f] !== null && $mapping[$f] !== '' ? ($raw[$mapping[$f]] ?? null) : null;
            $msgs = [];

            $name = trim((string) $get('name'));
            if ($name === '') {
                $plan[] = ['row' => $line, 'action' => 'erro', 'message' => 'Sem nome de jogo', 'data' => [], 'match_id' => null];

                continue;
            }
            $data = ['name' => Str::limit($name, 120, '')];
            if ($v = trim((string) $get('full_name'))) {
                $data['full_name'] = $v;
            }

            $birth = static::parseDate($get('birth_date'));
            if ($get('birth_date') !== null && $get('birth_date') !== '' && ! $birth) {
                $msgs[] = 'data de nascimento inválida ignorada';
            }
            if ($birth) {
                if ($birth->isFuture() || $birth->age < 15 || $birth->age > 50) {
                    $msgs[] = 'nascimento fora do intervalo plausível ignorado';
                } else {
                    $data['birth_date'] = $birth->toDateString();
                }
            }
            if (! isset($data['birth_date']) && is_numeric($get('age'))) {
                $msgs[] = 'idade informada sem nascimento (não gera data — completar depois)';
                $data['__age_hint'] = (int) $get('age');
            }

            if ($v = trim((string) $get('nationality'))) {
                $data['nationality'] = Str::limit($v, 60, '');
            }
            $cbf = preg_replace('/\D/', '', (string) $get('cbf_id'));
            if ($cbf !== '') {
                if (isset($seenCbf[$cbf])) {
                    $plan[] = ['row' => $line, 'action' => 'erro', 'message' => "ID CBF repetido na planilha (linha {$seenCbf[$cbf]})", 'data' => $data, 'match_id' => null];

                    continue;
                }
                $seenCbf[$cbf] = $line;
                $data['cbf_id'] = $cbf;
            }

            if (($raw_pos = $get('primary_position')) !== null && trim((string) $raw_pos) !== '') {
                $p = Position::guess((string) $raw_pos);
                $p ? $data['primary_position'] = $p->value : $msgs[] = "posição \"{$raw_pos}\" não reconhecida";
            }
            if (($raw = $get('positions')) !== null && trim((string) $raw) !== '') {
                $codes = collect(preg_split('/[,;\/]+/', (string) $raw))->map(fn ($s) => Position::guess($s)?->value)->filter()->unique()->values()->all();
                if ($codes) {
                    $data['positions'] = $codes;
                }
            }
            if (($f = static::parseFoot($get('preferred_foot'))) !== null) {
                $data['preferred_foot'] = $f;
            }
            if (($h = static::parseHeight($get('height_cm'))) !== null) {
                $data['height_cm'] = $h;
            }
            if ($v = trim((string) $get('current_club'))) {
                $data['__club'] = $v;
            }
            if ($v = trim((string) $get('agent_name'))) {
                $data['__agent'] = ['name' => Str::limit($v, 150, ''), 'phone' => trim((string) $get('agent_phone')) ?: null, 'email' => filter_var(trim((string) $get('agent_email')), FILTER_VALIDATE_EMAIL) ?: null];
            }
            if ($allowFinancial) {
                $smin = static::parseMoney($get('salary_min'));
                $smax = static::parseMoney($get('salary_max'));
                if ($smin !== null || $smax !== null) {
                    if ($smin !== null && $smax !== null && $smax < $smin) {
                        [$smin, $smax] = [$smax, $smin];
                        $msgs[] = 'faixa salarial invertida corrigida';
                    }
                    $data['salary_min'] = $smin;
                    $data['salary_max'] = $smax;
                    $data['salary_observed_on'] = now()->toDateString();
                }
                if (($ap = static::parseMoney($get('asking_price'))) !== null) {
                    $data['asking_price'] = $ap;
                }
            }
            $ms = Str::of((string) $get('market_status'))->lower()->ascii()->toString();
            if ($ms !== '') {
                $data['market_status'] = match (true) {
                    str_contains($ms, 'indispon') => 'indisponivel', str_contains($ms, 'negoci') => 'negociavel',
                    str_contains($ms, 'dispon') || str_contains($ms, 'livre') => 'disponivel', default => 'desconhecido',
                };
            }
            $pr = Str::of((string) $get('is_priority'))->lower()->ascii()->trim()->toString();
            if (in_array($pr, ['sim', 's', 'x', '1', 'yes', 'true', 'alta'], true)) {
                $data['is_priority'] = true;
            }
            foreach (['sofascore_url', 'wyscout_url', 'transfermarkt_url', 'ogol_url'] as $u) {
                $v = trim((string) $get($u));
                if ($v !== '') {
                    filter_var($v, FILTER_VALIDATE_URL) && preg_match('#^https?://#i', $v) ? $data[$u] = $v : $msgs[] = "link {$u} inválido ignorado";
                }
            }
            foreach (['phone', 'whatsapp'] as $ph) {
                $v = trim((string) $get($ph));
                if ($v !== '') {
                    strlen(preg_replace('/\D/', '', $v)) >= 8 ? $data[$ph] = Str::limit($v, 40, '') : $msgs[] = "{$ph} inválido ignorado";
                }
            }
            if (($v = trim((string) $get('email'))) !== '') {
                filter_var($v, FILTER_VALIDATE_EMAIL) ? $data['email'] = $v : $msgs[] = 'e-mail inválido ignorado';
            }
            if ($v = trim((string) $get('notes'))) {
                $data['notes'] = $v;
            }
            $cEnd = static::parseDate($get('contract_end'));
            $cStatus = static::parseContractStatus($get('contract_status'));
            if ($cEnd || $cStatus) {
                $data['__contract'] = ['contract_end' => $cEnd?->toDateString(), 'status' => $cStatus ?? 'vigente'];
            }
            if ($allowFinancial && ($mv = static::parseMoney($get('market_value'))) !== null) {
                $data['__market_value'] = $mv;
            }

            // Correspondência com cadastro existente
            $match = null;
            $action = 'criar';
            if (isset($data['cbf_id'])) {
                $match = Player::where('cbf_id', $data['cbf_id'])->first();
            }
            if (! $match) {
                $dups = Player::possibleDuplicates($data['name'], $data['birth_date'] ?? null, null);
                $exact = isset($data['birth_date']) ? $dups->first(fn ($p) => $p->birth_date?->toDateString() === $data['birth_date']) : null;
                if ($exact) {
                    $match = $exact;
                } elseif ($dups->isNotEmpty()) {
                    $action = 'ignorar';
                    $msgs[] = 'possível homônimo de '.$dups->map->displayLabel()->implode('; ').' — revisar manualmente';
                }
            }
            if ($match) {
                $action = 'atualizar';
                $msgs[] = 'corresponde a cadastro existente — só preenche campos vazios';
            }

            $plan[] = ['row' => $line, 'action' => $action, 'message' => implode('; ', $msgs), 'data' => $data, 'match_id' => $match?->id];
        }

        return $plan;
    }

    public static function commit(array $plan, string $filename, array $mapping, ?int $userId): ImportBatch
    {
        return DB::transaction(function () use ($plan, $filename, $mapping, $userId) {
            $batch = ImportBatch::create([
                'user_id' => $userId, 'original_filename' => $filename, 'mapping' => $mapping,
                'status' => 'preview', 'rows_total' => count($plan),
            ]);
            $created = $updated = $skipped = $failed = 0;
            $errors = [];
            foreach ($plan as $item) {
                if ($item['action'] === 'erro' || $item['action'] === 'ignorar') {
                    $item['action'] === 'erro' ? $failed++ : $skipped++;
                    $errors[] = ['row' => $item['row'], 'name' => $item['data']['name'] ?? null, 'message' => ($item['action'] === 'erro' ? 'Erro: ' : 'Ignorado: ').$item['message']];

                    continue;
                }
                $d = $item['data'];
                $club = isset($d['__club']) ? Club::findOrCreateByName($d['__club']) : null;
                $attrs = collect($d)->reject(fn ($v, $k) => str_starts_with($k, '__'))->all();
                if ($club) {
                    $attrs['current_club_id'] = $club->id;
                }
                if (isset($d['__agent'])) {
                    $agent = \App\Models\Agent::query()->whereRaw('lower(name) = ?', [mb_strtolower($d['__agent']['name'])])->first()
                        ?? \App\Models\Agent::create(array_filter($d['__agent']) + ['created_by' => $userId]);
                    $attrs['agent_id'] = $agent->id;
                }

                if ($item['action'] === 'atualizar') {
                    $player = Player::find($item['match_id']);
                    $fill = [];
                    foreach ($attrs as $k => $v) {
                        $cur = $player->getRawOriginal($k);
                        if ($cur === null || $cur === '' || ($k === 'preferred_foot' && $cur === 'nao_informado') || ($k === 'market_status' && $cur === 'desconhecido') || ($k === 'is_priority' && ! $cur)) {
                            $fill[$k] = $v;
                        }
                    }
                    $player->fill($fill)->save();
                    $updated++;
                } else {
                    $player = Player::create($attrs + ['data_origin' => 'importacao', 'import_batch_id' => $batch->id, 'created_by' => $userId]);
                    $created++;
                }

                if (isset($d['__contract'])) {
                    ContractObservation::create($d['__contract'] + [
                        'player_id' => $player->id, 'club_id' => $club?->id, 'confirmation' => 'nao_verificado',
                        'source_label' => 'Planilha: '.$filename, 'observed_on' => now()->toDateString(),
                        'import_batch_id' => $batch->id, 'author_id' => $userId,
                    ]);
                }
                if (isset($d['__market_value'])) {
                    MarketObservation::create([
                        'player_id' => $player->id, 'kind' => 'valor_mercado', 'amount' => $d['__market_value'], 'currency' => 'BRL',
                        'confirmation' => 'nao_verificado', 'source_label' => 'Planilha: '.$filename, 'observed_on' => now()->toDateString(),
                        'import_batch_id' => $batch->id, 'author_id' => $userId,
                    ]);
                }
                if ($item['message'] !== '') {
                    $errors[] = ['row' => $item['row'], 'name' => $d['name'], 'message' => 'Aviso: '.$item['message']];
                }
            }
            $batch->update([
                'rows_created' => $created, 'rows_updated' => $updated, 'rows_skipped' => $skipped, 'rows_failed' => $failed,
                'errors' => $errors, 'status' => ($failed + $skipped) > 0 ? 'parcial' : 'concluido', 'completed_at' => now(),
            ]);

            return $batch;
        });
    }

    /** @return array{removed:int, kept:int} */
    public static function revert(ImportBatch $batch, ?int $userId): array
    {
        return DB::transaction(function () use ($batch, $userId) {
            ContractObservation::where('import_batch_id', $batch->id)->delete();
            MarketObservation::where('import_batch_id', $batch->id)->delete();
            $removed = $kept = 0;
            foreach (Player::where('import_batch_id', $batch->id)->get() as $p) {
                $hasWork = $p->scoutingReports()->exists() || $p->roleAssessments()->exists()
                    || $p->specialtyAssessments()->exists() || $p->prospectCases()->exists() || $p->videoReferences()->exists();
                if ($hasWork) {
                    $kept++;
                } else {
                    $p->forceDelete();
                    $removed++;
                }
            }
            $batch->update(['status' => 'revertido', 'reverted_at' => now(), 'reverted_by' => $userId]);

            return ['removed' => $removed, 'kept' => $kept];
        });
    }

    public static function parseDate(mixed $v): ?Carbon
    {
        if ($v instanceof \DateTimeInterface) {
            return Carbon::instance($v)->startOfDay();
        }
        if ($v === null || $v === '') {
            return null;
        }
        if (is_numeric($v) && (float) $v > 10000 && (float) $v < 80000) { // serial do Excel
            return Carbon::create(1899, 12, 30)->addDays((int) $v);
        }
        $s = trim((string) $v);
        foreach (['d/m/Y', 'j/n/Y', 'd/m/y', 'Y-m-d', 'd-m-Y', 'd.m.Y', 'm/Y', 'n/Y'] as $fmt) {
            try {
                $d = Carbon::createFromFormat('!'.$fmt, $s);
                if ($d && $d->format($fmt) === $s) {
                    return in_array($fmt, ['m/Y', 'n/Y'], true) ? $d->endOfMonth()->startOfDay() : $d;
                }
            } catch (\Throwable) {
            }
        }

        return null;
    }

    public static function parseFoot(mixed $v): ?string
    {
        $s = Str::of((string) $v)->lower()->ascii()->trim()->toString();
        if ($s === '') {
            return null;
        }

        return match (true) {
            in_array($s, ['d', 'r', 'direito', 'destro', 'right', 'dir'], true) => Foot::Direito->value,
            in_array($s, ['e', 'l', 'esquerdo', 'canhoto', 'left', 'esq'], true) => Foot::Esquerdo->value,
            in_array($s, ['ambos', 'ambidestro', 'both', 'a'], true) => Foot::Ambidestro->value,
            default => null,
        };
    }

    public static function parseHeight(mixed $v): ?int
    {
        if ($v === null || $v === '') {
            return null;
        }
        $n = (float) str_replace(',', '.', preg_replace('/[^0-9,\.]/', '', (string) $v));
        if ($n > 1.3 && $n < 2.2) {
            $n *= 100;
        }

        return $n >= 140 && $n <= 215 ? (int) round($n) : null;
    }

    public static function parseContractStatus(mixed $v): ?string
    {
        $s = Str::of((string) $v)->lower()->ascii()->trim()->toString();
        if ($s === '') {
            return null;
        }

        return match (true) {
            str_contains($s, 'emprest') => ContractStatus::Emprestado->value,
            str_contains($s, 'livre') || str_contains($s, 'sem clube') || str_contains($s, 'free') => ContractStatus::Livre->value,
            str_contains($s, 'vigent') || str_contains($s, 'contrat') => ContractStatus::Vigente->value,
            default => ContractStatus::Desconhecido->value,
        };
    }

    /** "R$ 1.250.000,00" | "1,2 mi" | 1250000 → decimal; ambíguo → null. */
    public static function parseMoney(mixed $v): ?float
    {
        if (is_int($v) || is_float($v)) {
            return $v >= 0 ? (float) $v : null;
        }
        $s = Str::of((string) $v)->lower()->ascii()->trim()->toString();
        if ($s === '') {
            return null;
        }
        $mult = 1;
        if (preg_match('/\b(mi|milh)/', $s)) {
            $mult = 1_000_000;
        } elseif (preg_match('/\b(mil|k)\b/', $s)) {
            $mult = 1_000;
        }
        $num = preg_replace('/[^0-9,\.]/', '', $s);
        if ($num === '') {
            return null;
        }
        if (str_contains($num, ',')) {
            $num = str_replace(['.', ','], ['', '.'], $num);
        } elseif (substr_count($num, '.') > 1) {
            $num = str_replace('.', '', $num);
        }

        return is_numeric($num) ? round((float) $num * $mult, 2) : null;
    }
}
