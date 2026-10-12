# CIFUT Remo (repo remo-scout) — instruções para agentes

Sistema interno de prospecção do Departamento de Análise de Mercado do Clube do Remo (piloto).
Especificação: `docs/PROMPT-v2.md`. Estado e decisões: `CHECKPOINT.md` (atualize a cada sessão).

- Stack: Laravel 13, Filament 5 (Livewire 4), PostgreSQL. Testes: `php artisan test` (PostgreSQL local `remo_scout_test`).
- Dado ausente ≠ zero; "não avaliado" ≠ "padrão". Toda informação relevante guarda origem e data.
- Papel tático de jogador (`tactical_roles`) ≠ perfil de acesso de usuário (`users.role`).
- Não habilitar fontes não oficiais, LLM, e-mail/WhatsApp, domínio público ou planos pagos sem aprovação do Gustavo.
- Nunca gravar CPF nem segredos em código/log.
