<?php

namespace App\Services;

use App\Enums\RequirementStatus;
use App\Models\NeedRequirementCheck;
use App\Models\Player;
use App\Models\RecruitmentNeed;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * Registro de evidência e confirmação de requisito textual obrigatório (F04, decisão de 12/10/2026):
 * - analista (ou acima) registra evidência; o estado continua Pendente;
 * - só coordenador/admin define Atende, Não atende ou Não se aplica, e só com evidência + link ou anexo;
 * - leitor não altera nada.
 */
class RequirementConfirmation
{
    public static function record(User $user, RecruitmentNeed $need, Player $player, string $requirement, RequirementStatus $status,
        string $evidence, ?string $url = null, ?string $path = null): NeedRequirementCheck
    {
        if (! $user->is_active || ! $user->canEdit()) {
            throw new AuthorizationException('Seu perfil não pode registrar evidência de requisito.');
        }
        if (! in_array($requirement, $need->mandatory_requirements ?? [], true)) {
            throw ValidationException::withMessages(['requirement' => 'Requisito não pertence a esta necessidade.']);
        }
        if ($status !== RequirementStatus::Pendente && ! $user->isCoordinator()) {
            throw new AuthorizationException('Só coordenador ou admin confirma requisito obrigatório. Registre a evidência para revisão.');
        }
        if (blank(trim($evidence))) {
            throw ValidationException::withMessages(['evidence' => 'Descreva a evidência.']);
        }
        if ($url !== null && $url !== '' && ! preg_match('#^https?://#i', $url)) {
            throw ValidationException::withMessages(['evidence_url' => 'Link deve começar com http(s)://']);
        }
        if ($status !== RequirementStatus::Pendente && blank($url) && blank($path)) {
            throw ValidationException::withMessages(['evidence_url' => 'Para confirmar, anexe um arquivo ou informe um link da evidência.']);
        }

        $check = NeedRequirementCheck::firstOrNew([
            'recruitment_need_id' => $need->id, 'player_id' => $player->id, 'requirement_key' => NeedRequirementCheck::keyFor($requirement),
        ]);
        if ($check->exists && $check->confirmed_at && ! $user->isCoordinator()) {
            throw new AuthorizationException('Requisito já decidido por '.($check->confirmer?->name ?? 'coordenação').'. Só coordenador/admin reabre ou altera.');
        }
        $check->fill([
            'requirement_text' => $requirement, 'status' => $status, 'evidence' => trim($evidence),
            'evidence_url' => $url ?: $check->evidence_url, 'evidence_path' => $path ?: $check->evidence_path,
            'evidence_by' => $user->id, 'evidence_at' => now(),
            'confirmed_by' => $status === RequirementStatus::Pendente ? null : $user->id,
            'confirmed_at' => $status === RequirementStatus::Pendente ? null : now(),
        ])->save();

        return $check;
    }
}
