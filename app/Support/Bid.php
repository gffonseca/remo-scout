<?php

namespace App\Support;

use App\Models\ContractObservation;
use App\Models\Player;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * BID — Boletim Informativo Diário da CBF (bid.cbf.com.br).
 * A busca e o histórico do atleta exigem CAPTCHA de imagem: não há coleta automática (seria contornar controle de acesso).
 * Integração por consulta assistida: o analista abre o BID, resolve o CAPTCHA e registra o que viu como observação
 * contratual datada, com origem "BID CBF", link e autor.
 */
class Bid
{
    public const BASE = 'https://bid.cbf.com.br';

    public const CONTRACT_TYPES = [
        'profissional' => 'Profissional', 'emprestimo' => 'Empréstimo / cessão temporária', 'formacao' => 'Formação / não profissional',
        'rescisao' => 'Rescisão / distrato', 'reversao' => 'Reversão de empréstimo', 'outro' => 'Outro',
    ];

    public static function url(Player $p): string
    {
        return $p->cbf_id && ctype_digit((string) $p->cbf_id) ? self::BASE.'/atleta-competicoes/'.$p->cbf_id : self::BASE.'/home';
    }

    /** @param array{tipo:string, numero?:?string, inicio?:?string, publicacao?:?string, fim?:?string, club_id?:?string, notas?:?string} $d */
    public static function record(Player $p, array $d, User $user): ContractObservation
    {
        $fmt = fn (?string $date) => $date ? Carbon::parse($date)->format('d/m/Y') : 'não informado';
        $status = match ($d['tipo']) {
            'emprestimo' => 'emprestado',
            'rescisao' => 'livre',
            'profissional', 'reversao' => 'vigente',
            default => 'desconhecido',
        };
        $line = 'BID CBF — tipo: '.(self::CONTRACT_TYPES[$d['tipo']] ?? $d['tipo'])
            .(filled($d['numero'] ?? null) ? ' · nº '.trim($d['numero']) : '')
            .' · início: '.$fmt($d['inicio'] ?? null).' · publicação: '.$fmt($d['publicacao'] ?? null)
            .'. Consulta manual por '.$user->name.' em '.now(config('scout.display_timezone'))->format('d/m/Y H:i').'.';

        return $p->contractObservations()->create([
            'status' => $status, 'club_id' => $d['club_id'] ?? null, 'contract_end' => $d['fim'] ?? null,
            'confirmation' => 'confirmado', 'observed_on' => $d['publicacao'] ?? now()->toDateString(),
            'source_label' => 'BID CBF', 'source_url' => static::url($p),
            'notes' => trim($line."\n".($d['notas'] ?? '')), 'author_id' => $user->id,
        ]);
    }
}
