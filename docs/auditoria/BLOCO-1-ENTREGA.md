# Bloco 1 — entrega (12/10/2026)

Branch `refino`, commits `bf24830..7ba817c` (9 commits, 51 arquivos, +2.168/−267). **Não publicado.** O deploy depende da autorização final do Gustavo.

## Resultado dos testes

| Onde | Resultado |
|---|---|
| Local (PHP 8.3, PostgreSQL 16) | 120 testes, 502 asserções, 0 falhas |
| CI GitHub (`Tests`, run 38248586713, PHP 8.3 + PostgreSQL 16 + poppler) | 119 passaram + 1 incompleto (F16, de propósito), 502 asserções |
| Regressões da auditoria (8) | 7 passam; 1 incompleta (F16 → Bloco 2) |
| Suíte original (45) | todos passam (o teste de requisito reprovado passou a esperar "Não atende" no lugar de "Reprova") |

Suíte verde **não** certifica segurança nem cobertura: é evidência dos comportamentos testados.

## Critérios de aceite por achado

| Achado | Aceite da auditoria | Evidência |
|---|---|---|
| F01 MFA nas exportações | Sem MFA → cadastramento; com MFA → exporta; perfis sem acesso testados | `ExportAccessTest`: admin sem MFA redirecionado nas 3 rotas; admin com MFA baixa PDF; coordenador/analista/leitor seguem a policy; inativo e visitante barrados; troca de senha invalida a sessão (AuthenticateSession). O desafio do 2º fator ocorre no login do painel. |
| F02 Dinheiro | R$ 25.000 = 25000; R$ 25.000,50; negativo recusado; locale estrangeiro; ida/volta | `MoneyParserTest` (12 aceitos, 10 recusados, ida/volta), `ImportRevertTest::test_ambiguous_or_negative_money...`. Prévia mostra original → interpretação. |
| F03 Potencial × observado | Só potencial = Pendente; abaixo = Não atende; adequado e válido = Atende; fases não se substituem | `RequirementsTest` (potencial, mais recente vence, fases, vencida, migração de nível antigo). |
| F04 Obrigatório desconhecido | Pendente até confirmar; uma falha = Não atende; só todos confirmados = Atende; evidência, responsável e data | `RequirementsTest::test_manual_requirement_lifecycle_and_permissions`, `..._analyst_cannot_reopen...`. Só coordenador/admin confirma, com link ou anexo. |
| F05 Reversão | Restaura campos e derivados; edição posterior = conflito; reverte uma vez com trilha | `ImportRevertTest` (9 testes): campos e salário derivado restaurados, edição posterior mantida, uso posterior detectado pelas FKs do banco, observação editada mantida, clube com ID externo mantido, reversão única, lote antigo. |
| F06 Fila | Timeout < retry_after efetivo; sem duplicidade; sem job preso | `QueueSafetyTest` + verificação com 2 processos reais e cache em banco (o 2º foi recusado com a trava ativa e rodou após a liberação). Prazo interno de 1.200 s; execução órfã fechada como "falhou". |
| F07 CI | Push/PR na main com PostgreSQL e PHP 8.3 | Workflow `tests.yml`, run verde citado acima. |
| F08 Partidas | Parcial → completa atualiza; correção remove evento antigo sem duplicar | `FixtureRefreshTest`: parcial → completa → corrigida; escalação ausente/vazia não apaga; payload igual não reprocessa; seleção de reconsulta. |
| F10 Nível atual | Potencial isolado = desconhecido; vencida identificada; repetição do mesmo autor não reforça | `RequirementsTest` (relatórios de potencial, vencida, um voto por autor, escopo `currentObserved`). |
| F23 Workflows | — | 4 workflows do esqueleto removidos; Dependabot sem auto-merge. |
| F24 Worker | — | Hotfix `00d6fe9` já em produção (autorizado). |

## Revisão independente

Um agente separado, que não escreveu o código, revisou o diff contra a auditoria e as decisões. Encontrou 13 problemas (4 graves): PDF da shortlist com lógica antiga, reversão apagando atleta editado fora do snapshot ou usado depois, cascatas silenciosas, `unique` com NULL no PostgreSQL, filtro de nível aceitando potencial, analista desfazendo decisão do coordenador, trava podendo expirar no meio da sincronização, escalação vazia apagando atuações, testes tautológicos. **Todos foram corrigidos** no commit `00d773c`, com testes.

## Migrações (não destrutivas)

1. `2026_10_13_000100_phases_and_requirement_states`
   - Adiciona `observed_on` às avaliações (preenchido com a data de criação).
   - Fases antigas: `transicao_ofensiva` → com posse, `transicao_defensiva` → sem posse, `geral`/outras → sem fase. O valor original fica anotado no início da evidência.
   - Cria `need_role_requirements` (copia o nível-alvo das necessidades existentes como requisito "geral") e `need_requirement_checks`.
   - O `down()` remove as tabelas novas, mas **não desfaz o remapeamento de fases** (o original continua na evidência).
2. `2026_10_13_000200_import_change_log`: cria `import_changes`; `revert_report` e `has_change_log` nos lotes; `import_batch_id` nos empresários.

Efeito colateral a conhecer: uma avaliação antiga de "transição ofensiva" vira "com posse" e, se for a mais recente dessa fase, passa a ser a que vale para requisitos de com posse. Em produção não há avaliações de papel registradas (auditoria: 0 relatórios), então o impacto esperado é nulo.

## Plano de deploy (aguardando autorização)

1. **Antes:** no painel do Railway, gerar um backup manual do Postgres (ou confirmar o backup automático do dia). Conferir que `DB_QUEUE_RETRY_AFTER` não está definido no Railway (padrão do código: 1.800 s); hoje não está.
2. **Publicar:** PR `refino` → `main`, com o CI verde. O merge na `main` dispara o deploy automático de web e worker; o pre-deploy roda as 2 migrações e os seeders idempotentes.
3. **Conferir depois:**
   - `/up` responde; login com MFA; dossiê PDF como admin.
   - Tela de necessidade com "Requisitos por papel e fase"; ação "Requisitos" nos candidatos.
   - Lotes de importação com relatório de reversão.
   - Logs do worker com `[cifut] worker` e o agendador rodando; a coleta da CBF das 06:20 no dia seguinte.
4. **Rollback:** "Rollback" no Railway para a implantação anterior (`00d6fe9`).
   - O código antigo convive com o esquema novo: tabelas e colunas novas ficam sem uso, e as fases remapeadas usam valores que o código antigo já conhecia.
   - Não é preciso desfazer migração. Se for preciso voltar o banco, restaurar o backup do passo 1.

## Limitações abertas (Bloco 2 em diante)

- **F16** (revisão aceita sem aplicar clube por UUID): teste marcado incompleto, corrigido no Bloco 2.
- **Lotes anteriores:** importados antes desta versão não têm trilha campo a campo. A reversão deles continua só removendo o que criaram, com aviso na tela.
- **Súmulas:** a reimportação de uma súmula corrigida recalcula só os atletas presentes nela. A remoção de agregado de quem saiu da súmula fica para o Bloco 2 (qualidade por campo).
- **Validade de 12 meses:** foi uma premissa minha, ajustável por variável (`SCOUT_ASSESSMENT_VALID_MONTHS`).
