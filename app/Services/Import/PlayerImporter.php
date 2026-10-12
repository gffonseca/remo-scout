<?php

namespace App\Services\Import;

use App\Enums\ContractStatus;
use App\Enums\Foot;
use App\Enums\Position;
use App\Models\Club;
use App\Models\ContractObservation;
use App\Models\ImportBatch;
use App\Models\ImportChange;
use App\Models\MarketObservation;
use App\Models\Player;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use App\Support\MoneyParser;
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
                // Dinheiro (F02): só entra o que foi lido sem ambiguidade; o resto fica na prévia para revisão.
                $money = function (string $field, string $label) use ($get, &$msgs): ?array {
                    $r = MoneyParser::parse($get($field));
                    if ($r['status'] === 'vazio') {
                        return null;
                    }
                    $msgs[] = $label.': '.MoneyParser::describe($r);

                    return $r['status'] === 'ok' ? $r : null;
                };
                $smin = $money('salary_min', 'salário mín.');
                $smax = $money('salary_max', 'salário máx.');
                if ($smin && $smax && $smin['currency'] !== $smax['currency']) {
                    $msgs[] = 'faixa salarial com moedas diferentes não importada';
                    $smin = $smax = null;
                }
                if ($smin || $smax) {
                    [$lo, $hi] = [$smin['value'] ?? null, $smax['value'] ?? null];
                    if ($lo !== null && $hi !== null && $hi < $lo) {
                        [$lo, $hi] = [$hi, $lo];
                        $msgs[] = 'faixa salarial invertida corrigida';
                    }
                    $data['salary_min'] = $lo;
                    $data['salary_max'] = $hi;
                    $data['salary_currency'] = ($smin ?? $smax)['currency'];
                    $data['salary_observed_on'] = now()->toDateString();
                }
                if ($ap = $money('asking_price', 'pedida')) {
                    $data['asking_price'] = $ap['value'];
                    $data['asking_price_currency'] = $ap['currency'];
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
            if ($allowFinancial && ($mvr = MoneyParser::parse($get('market_value')))['status'] !== 'vazio') {
                $msgs[] = 'valor de mercado: '.MoneyParser::describe($mvr);
                if ($mvr['status'] === 'ok') {
                    $data['__market_value'] = ['amount' => $mvr['value'], 'currency' => $mvr['currency']];
                }
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
                'status' => 'preview', 'rows_total' => count($plan), 'has_change_log' => true,
            ]);
            $log = fn (string $type, string $id, string $action, ?string $field = null, mixed $old = null, mixed $new = null) => ImportChange::create([
                'import_batch_id' => $batch->id, 'entity_type' => $type, 'entity_id' => $id, 'action' => $action,
                'field' => $field, 'old_value' => $old, 'new_value' => $new,
            ]);
            Player::$importBatchContext = $batch->id;
            try {
                $created = $updated = $skipped = $failed = 0;
                $errors = [];
                foreach ($plan as $item) {
                    if ($item['action'] === 'erro' || $item['action'] === 'ignorar') {
                        $item['action'] === 'erro' ? $failed++ : $skipped++;
                        $errors[] = ['row' => $item['row'], 'name' => $item['data']['name'] ?? null, 'message' => ($item['action'] === 'erro' ? 'Erro: ' : 'Ignorado: ').$item['message']];

                        continue;
                    }
                    $d = $item['data'];
                    $club = null;
                    if (isset($d['__club'])) {
                        // Usa apelidos/variações já cadastrados antes de criar clube novo
                        $club = \App\Support\ClubResolver::byName($d['__club']);
                        if (! $club) {
                            $club = Club::create(['name' => Str::limit(trim($d['__club']), 120, ''), 'country' => 'Brasil']);
                            \App\Support\ClubResolver::flush();
                            $log('club', $club->id, 'created', null, null, ['name' => $club->name]);
                        }
                    }
                    $attrs = collect($d)->reject(fn ($v, $k) => str_starts_with($k, '__'))->all();
                    if ($club) {
                        $attrs['current_club_id'] = $club->id;
                    }
                    if (isset($d['__agent'])) {
                        $agent = \App\Models\Agent::query()->whereRaw('lower(name) = ?', [mb_strtolower($d['__agent']['name'])])->first();
                        if (! $agent) {
                            $agent = \App\Models\Agent::create(array_filter($d['__agent']) + ['created_by' => $userId, 'import_batch_id' => $batch->id]);
                            $log('agent', $agent->id, 'created', null, null, ['name' => $agent->name]);
                        }
                        $attrs['agent_id'] = $agent->id;
                    }

                    if ($item['action'] === 'atualizar') {
                        $player = Player::find($item['match_id']);
                        $fill = $before = [];
                        foreach ($attrs as $k => $v) {
                            $cur = $player->getRawOriginal($k);
                            if ($cur === null || $cur === '' || ($k === 'preferred_foot' && $cur === 'nao_informado') || ($k === 'market_status' && $cur === 'desconhecido') || ($k === 'is_priority' && ! $cur)) {
                                $fill[$k] = $v;
                                $before[$k] = $cur;
                            }
                        }
                        $player->fill($fill)->save();
                        foreach (array_keys($fill) as $k) {
                            $log('player', $player->id, 'updated', $k, $before[$k], $player->getRawOriginal($k));
                        }
                        $updated++;
                    } else {
                        $player = Player::create($attrs + ['data_origin' => 'importacao', 'import_batch_id' => $batch->id, 'created_by' => $userId]);
                        $log('player', $player->id, 'created', null, null, collect(array_keys($attrs))->mapWithKeys(fn ($k) => [$k => $player->getRawOriginal($k)])->all());
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
                            'player_id' => $player->id, 'kind' => 'valor_mercado', 'amount' => $d['__market_value']['amount'], 'currency' => $d['__market_value']['currency'],
                            'confirmation' => 'nao_verificado', 'source_label' => 'Planilha: '.$filename, 'observed_on' => now()->toDateString(),
                            'import_batch_id' => $batch->id, 'author_id' => $userId,
                        ]);
                    }
                    if ($item['message'] !== '') {
                        $errors[] = ['row' => $item['row'], 'name' => $d['name'], 'message' => 'Aviso: '.$item['message']];
                    }
                }
            } finally {
                Player::$importBatchContext = null;
            }
            $batch->update([
                'rows_created' => $created, 'rows_updated' => $updated, 'rows_skipped' => $skipped, 'rows_failed' => $failed,
                'errors' => $errors, 'status' => ($failed + $skipped) > 0 ? 'parcial' : 'concluido', 'completed_at' => now(),
            ]);

            return $batch;
        });
    }

    /**
     * Reverte um lote uma única vez (F05):
     * - campos preenchidos em atleta existente voltam ao valor anterior, se ninguém os alterou depois;
     * - atletas/empresários/clubes criados pelo lote saem, se não foram editados nem usados depois;
     * - observações (contrato, mercado, salário derivado) do lote saem;
     * - tudo o que não pôde ser desfeito vira conflito explícito no relatório, sem sobrescrever trabalho posterior.
     *
     * @return array{removed:int, removed_other:int, kept:int, restored:int, conflicts:list<array{entity:string, id:string, label:string, field:?string, reason:string}>}
     */
    public static function revert(ImportBatch $batch, ?int $userId): array
    {
        return DB::transaction(function () use ($batch, $userId) {
            $batch = ImportBatch::whereKey($batch->id)->lockForUpdate()->firstOrFail();
            if ($batch->reverted_at) {
                throw new \RuntimeException('Este lote já foi revertido em '.$batch->reverted_at->format('d/m/Y H:i').'.');
            }
            $removed = $kept = $restored = $removedOther = 0;
            $conflicts = [];
            $conflict = function (string $entity, string $id, string $label, ?string $field, string $reason) use (&$conflicts) {
                $conflicts[] = compact('entity', 'id', 'label', 'field', 'reason');
            };

            // Observações do lote: saem, salvo se alguém as editou/confirmou depois (aí ficam como conflito,
            // e o atleta delas também fica — apagá-lo levaria a observação junto, em cascata).
            $keptObsPlayers = [];
            foreach ([ContractObservation::class => 'contract_observation', MarketObservation::class => 'market_observation'] as $cls => $type) {
                foreach ($cls::where('import_batch_id', $batch->id)->get() as $o) {
                    if ($o->updated_at && $o->created_at && $o->updated_at->gt($o->created_at->copy()->addSeconds(2))) {
                        $conflict($type, $o->id, $o->player?->name ?? '—', null, 'observação editada depois da importação; mantida');
                        $keptObsPlayers[] = $o->player_id;
                    } else {
                        $o->delete();
                    }
                }
            }

            // 1) campos alterados em atletas que já existiam
            foreach ($batch->changes()->where('entity_type', 'player')->where('action', 'updated')->get() as $ch) {
                $raw = DB::table('players')->where('id', $ch->entity_id)->first();
                if (! $raw) {
                    $conflict('player', $ch->entity_id, '(excluído)', $ch->field, 'atleta não existe mais');

                    continue;
                }
                if (static::same($raw->{$ch->field} ?? null, $ch->new_value)) {
                    DB::table('players')->where('id', $ch->entity_id)->update([$ch->field => $ch->old_value, 'updated_at' => now()]);
                    $restored++;
                } else {
                    $conflict('player', $ch->entity_id, $raw->name, $ch->field, 'campo alterado depois da importação; mantido o valor atual');
                }
            }

            // 2) atletas criados pelo lote
            $createdLog = $batch->changes()->where('entity_type', 'player')->where('action', 'created')->get()->keyBy('entity_id');
            $createdIds = $batch->has_change_log ? $createdLog->keys() : Player::where('import_batch_id', $batch->id)->pluck('id');
            foreach (Player::withTrashed()->whereIn('id', $createdIds)->get() as $p) {
                // qualquer registro que aponte para o atleta (exceto observações deste lote) = trabalho posterior
                $usedBy = static::referencedBy('players', $p->id, ['contract_observations' => $batch->id, 'market_observations' => $batch->id]);
                $snap = $createdLog->get($p->id)?->new_value ?? [];
                $edited = collect($snap)->filter(fn ($v, $f) => ! static::same($p->getRawOriginal($f), $v))->keys();
                $touchedLater = $batch->completed_at && $p->updated_at && $p->updated_at->gt($batch->completed_at->copy()->addSeconds(2));
                if (in_array($p->id, $keptObsPlayers, true)) {
                    $usedBy[] = 'observação do lote mantida';
                }
                if ($usedBy !== [] || $edited->isNotEmpty() || $touchedLater) {
                    $kept++;
                    $conflict('player', $p->id, $p->name, null, match (true) {
                        $usedBy !== [] => 'já referenciado por: '.implode(', ', $usedBy),
                        $edited->isNotEmpty() => 'editado depois da importação: '.$edited->implode(', '),
                        default => 'cadastro alterado depois da importação',
                    });
                } else {
                    $p->forceDelete();
                    $removed++;
                }
            }

            // 3) empresários e clubes criados pelo lote, se não usados por ninguém
            foreach ($batch->changes()->where('action', 'created')->whereIn('entity_type', ['agent', 'club'])->get() as $ch) {
                $model = $ch->entity_type === 'agent' ? \App\Models\Agent::find($ch->entity_id) : Club::find($ch->entity_id);
                if (! $model) {
                    continue;
                }
                $usedBy = static::referencedBy($ch->entity_type === 'agent' ? 'agents' : 'clubs', $model->id);
                if ($usedBy !== []) {
                    $conflict($ch->entity_type, $model->id, $model->name, null, 'em uso por: '.implode(', ', $usedBy).'; mantido');
                } else {
                    $model->delete();
                    $removedOther++;
                }
            }

            $report = ['removed' => $removed, 'removed_other' => $removedOther, 'kept' => $kept, 'restored' => $restored, 'conflicts' => $conflicts];
            $batch->update(['status' => 'revertido', 'reverted_at' => now(), 'reverted_by' => $userId, 'revert_report' => $report]);
            \App\Models\AuditEvent::record('import_revert', $batch, ['restored' => $restored, 'removed' => $removed, 'conflicts' => count($conflicts)]);

            return $report;
        });
    }

    /**
     * Tabelas que ainda referenciam o registro, descobertas pelas chaves estrangeiras do próprio banco
     * (cobre tabelas futuras sem manutenção manual). $ignoreBatch: tabela => lote cujas linhas não contam.
     *
     * @return list<string>
     */
    private static function referencedBy(string $table, string $id, array $ignoreBatch = []): array
    {
        static $fks = [];
        $fks[$table] ??= DB::select("
            select kcu.table_name as tbl, kcu.column_name as col
            from information_schema.referential_constraints rc
            join information_schema.key_column_usage kcu on kcu.constraint_name = rc.constraint_name and kcu.constraint_schema = rc.constraint_schema
            join information_schema.key_column_usage pk on pk.constraint_name = rc.unique_constraint_name and pk.constraint_schema = rc.unique_constraint_schema
            where pk.table_name = ? and kcu.constraint_schema = current_schema()", [$table]);
        $hits = [];
        foreach ($fks[$table] as $fk) {
            $q = DB::table($fk->tbl)->where($fk->col, $id);
            if (isset($ignoreBatch[$fk->tbl])) {
                $q->where(fn ($w) => $w->whereNull('import_batch_id')->orWhere('import_batch_id', '!=', $ignoreBatch[$fk->tbl]));
            }
            if ($q->exists()) {
                $hits[] = $fk->tbl;
            }
        }

        return array_values(array_unique($hits));
    }

    /** Compara valor bruto do banco com o registrado (decimais "25000.00" = 25000, JSON, booleanos, datas). */
    private static function same(mixed $current, mixed $recorded): bool
    {
        $norm = function (mixed $v) {
            if (is_string($v) && $v !== '' && in_array($v[0], ['[', '{'], true) && json_validate($v)) {
                $v = json_decode($v, true);
            }
            if (is_array($v)) {
                return json_encode($v);
            }
            if (is_bool($v)) {
                return $v ? '1' : '0';
            }
            if (is_int($v) || is_float($v) || (is_string($v) && is_numeric($v))) {
                return rtrim(rtrim(number_format((float) $v, 4, '.', ''), '0'), '.');
            }
            if (is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}/', $v)) {
                return substr($v, 0, 10);
            }

            return $v === null ? null : trim((string) $v);
        };

        return $norm($current) === $norm($recorded);
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

    /** Valor monetário inequívoco (pt-BR) ou null. Detalhes e motivo em MoneyParser::parse(). */
    public static function parseMoney(mixed $v): ?float
    {
        $r = MoneyParser::parse($v);

        return $r['status'] === 'ok' ? $r['value'] : null;
    }
}
