# Bloco 3 — entrega (12/10/2026)

Branch `refino`, commits `50727bb..df3e27a` (8 commits, cerca de 130 arquivos, +3.650/−240) sobre a `main` (`b7de4b3`). Deploy autorizado pelo Gustavo ("pode fazer o deploy; após prossiga para as próximas fases").

## Resultado dos testes

| Onde | Resultado |
|---|---|
| Local (PHP 8.3, PostgreSQL 16) | 239 testes, 1.108 asserções, 0 falhas |
| CI GitHub | ver PR do Bloco 3 |

Suíte verde **não** certifica segurança nem cobertura: é evidência dos comportamentos testados.

## Entregas e aceite

| Item | O que faz | Evidência |
|---|---|---|
| F11 Pesquisa da necessidade | Os mesmos critérios e estados da necessidade aplicados à base. Classes: atende, com pendências, não atende. Filtro explícito por pendência. Exporta CSV com o motivo de cada critério. Adiciona ao quadro | `NeedSearchTest` (7) |
| Orçamento em 4 estados | Dentro, intersecta (negociar), acima, desconhecido. O piso da faixa nunca confirma orçamento. Moeda ou periodicidade incompatível = desconhecido. Só aparece para quem tem acesso financeiro; para os demais não entra na classe | `NeedSearchTest` |
| Comparador fixo | De 2 a 5 atletas por usuário, salvo. Estatística de uma única base (fonte + temporada), sem somar fontes. Nível observado por papel e fase, com data. Financeiro só com permissão | `ComparatorTest` |
| Watchlist | Responsável, motivo, próxima revisão. Um item ativo por atleta. Alertas só no sino do sistema, deduplicados: revisão vencida, troca de clube (inclusive atleta sem clube que assina), elenco divergente, relatório de outro analista, contrato terminando em até 90 dias | `WatchlistTest` (6) |
| F22 Catálogo | Critério A–D observável por fase. "Ativo" só pelo botão Aprovar (coordenador), com critérios completos. Edição de critério por quem não aprova, ou fase nova sem critério, volta o perfil para proposta. Tela de calibração mostra divergência entre analistas | `CatalogApprovalTest` |
| Layout: preferências | Colunas, filtros, ordenação e itens por página salvos por usuário nas listas, com botão de restaurar | `TablePreferencesTest` |
| Layout: perfil em abas | Perfil + cada registro do atleta numa aba com contagem (vazio não mostra "0") | `SquadPlannerTest::test_profile_tabs_show_counts` |
| Layout: Ctrl+K | Paleta com atalhos (telas, criar registro, pesquisar candidatos de cada necessidade aberta) antes dos registros. Respeita o perfil de acesso. ">" mostra só atalhos | `SquadPlannerTest::test_command_palette_lists_actions_by_permission` |
| Planejador de elenco | Duas estruturas (com e sem posse), 6 desenhos. Titular e alternativas por posto, papel do posto e nível observado vigente nesse papel e fase (geral não substitui fase). Carências: sem titular, sem alternativa, titular fora do elenco, titular sem avaliação. Cruza com as necessidades abertas. Profundidade por posição. Cenário = cópia independente do plano. Copiar escalação entre estruturas | `SquadPlannerTest` (9) |

"Sem avaliação" não é nível baixo; o planejador não decide contratação.

## Revisão independente

4 achados, todos corrigidos com teste de regressão:

| Gravidade | Achado | Correção |
|---|---|---|
| Médio | Pesquisa fazia consultas por atleta (≈6 por atleta; 3.000 atletas ≈ 18 mil consultas a cada filtro) | Dados da necessidade calculados uma vez; avaliação em memória com as avaliações pré-carregadas. Teste garante que 5 e 50 atletas fazem o mesmo número de consultas |
| Médio | Watchlist não alertava quando atleta sem clube assinava (nulo significava "sem base" e "sem clube") | Marca de linha de base (`club_baseline_at`) na criação; nulo passa a ser "sem clube". Chave de alerta por transição e dia |
| Baixo-médio | Coordenador podia criar perfil já "ativo", sem critérios | Na criação só proposta/arquivado; fase ou comportamento novo sem critério devolve o perfil ativo para proposta |
| Baixo | Novo titular em posto sem titular deixava buraco na numeração das alternativas | Renumeração após inserir |

Também: comparador com `?need=` inválido na URL dava erro 500; agora ignora.

## Migrações novas

`2026_10_15_000100` (comparador e preferências), `000200` (notificações, `data` jsonb), `000300` (watchlist), `000400` (guias de nível do catálogo), `000500` (planejador), `000600` (linha de base do clube na watchlist). Todas aditivas. O pre-deploy faz backup antes de migrar.

## O que fica para o Bloco 4

Notícias via Claude API (depende de `ANTHROPIC_API_KEY` e teto mensal aprovados pelo Gustavo), F19 (heartbeat e alertas de operação), ensaio de restauração no Mac Mini, F21 (desempenho) e piloto com metas definidas pelo departamento.
