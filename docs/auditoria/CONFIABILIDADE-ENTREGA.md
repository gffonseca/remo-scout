# Confiabilidade — clube, estatísticas, fotos e revalidação (12/10/2026)

Branch `confiabilidade` (6 commits sobre a `main` `5a90628`, 43 arquivos de código/teste, +1.970/−64). **Não publicada.** Segue o prompt mestre (`prompt-mestre-claude-cifut-remo.md`): nada em produção sem autorização específica.

## O que foi confirmado, proposto e bloqueado

| Item | Situação | Evidência |
|---|---|---|
| Clube como código nas estatísticas | **Causa confirmada no código**: o enriquecimento do Transfermarkt gravava o `clubId` da fonte em `club_name`. Corrigido | `StatsClubIdentityTest` (8), `SourcesTest` |
| Estatísticas ausentes | **Causas mensuráveis por atleta** (9 estados). Contagem real depende de produção (sem acesso de leitura ao banco) | `DataCoverageTest` |
| Fotos ausentes | **Causa confirmada**: a foto da API-Football ficava em `provider_athletes` e nunca ia ao cadastro. Corrigido com vínculo confiável | `DataCoverageTest` (fotos) |
| F01 MFA | Revalidado com **sessão real** (login → desafio → exportação), além de `actingAs` | `LoginSessionFlowTest` (3), `ExportAccessTest` |
| F02, F04–F08, F10 | Revalidados contra o prompt: já atendidos no Bloco 1 (testes seguem verdes) | `MoneyParserTest`, `ImportRevertTest`, `QueueSafetyTest`, `FixtureRefreshTest`, `RequirementsTest` |
| F03 especialidade | **Lacuna encontrada e corrigida**: especialidade obrigatória "atendia" só por existir avaliação. Agora exige nível mínimo (e amostra mínima opcional); sem limiar = Pendente | `SpecialtyThresholdTest` |
| Pro Soccer | Coletor de descoberta instalado no Mac Mini; **bloqueado** até a senha da conta estar no Chaves do Mac Mini | `collector/prosoccer.py` |
| Súmulas CBF, Football-Data (licença), comparativo API-Football × Sportmonks | **Não iniciados nesta etapa** (ordem de entrega do prompt: 3º item). Referências anexadas em `docs/auditoria/` | — |

## 1. Clube nas estatísticas (prompt 3.5)

- Estatística guarda três coisas separadas: `club_id` (clube canônico), `club_external_id` (ID do clube na fonte) e `club_source_name` (como a fonte rotula). `club_key` identifica a linha: duas passagens na mesma temporada continuam distintas; o mesmo número em fontes diferentes não se confunde.
- Clube canônico só por **vínculo do ID** (`club_external_ids`). O vínculo passou a registrar o método: manual (pessoa), nome exato/sinônimo, núcleo do nome, parte do nome, criado pela fonte, legado. Núcleo/parte do nome **não** corrigem dado sem confirmação.
- Sem vínculo, a tela mostra **"Clube não identificado"**; rótulo e ID da fonte ficam no detalhe (tooltip). Perfil e comparador corrigidos; PDFs e CSVs não exibem estatística.
- Totais de partidas guardam o clube **da partida** (histórico), não o clube atual.
- **Migração** `2026_10_16_000100`: só reestrutura, sem perda (código do Transfermarkt → `club_external_id`; nome existente → `club_source_name`; CBF recebe o ID do clube do registro; totais de partidas recebem `club_id` quando o nome identifica um único clube). Aborta, sem apagar nada, se a nova chave gerar duplicidade.
- **Backfill** `php artisan scout:stats-clubs`: prévia por padrão (tabela antes → depois, sem vínculo, vínculo a confirmar); `--apply --actor=` grava em lote com trilha (`data_fix_changes`); `--revert=<lote>` desfaz; linha editada depois vira conflito, sem sobrescrita. `--labels` busca o rótulo do clube no Transfermarkt (com cache) para quem não tem.
- **Pendências**: a tela "Cobertura de dados" lista os clubes das fontes sem vínculo; coordenador vincula (o vínculo e as linhas entram no mesmo lote reversível).

## 2. Estatísticas ausentes (prompt 3.6)

- Estado por atleta: disponível, parcial, desatualizado, identidade pendente, falha/bloqueio da fonte, sem participação confirmada (exige vínculo confirmado + escalações completas), aguardando vínculo, fora de cobertura, não coletado. Denominador explícito (atletas do scout; registros CBF e cadastros provisórios não contam).
- O perfil **não esconde** mais linha sem números (aparece com "—"); temporada com 2 ou 4 dígitos é reconhecida.
- Última coleta por atleta e última execução / última execução bem-sucedida por fonte.
- Recuperação em lotes: `scout:enrich transfermarkt --scope=cobertura --limit=N [--dry-run]`, prioridade watchlist → necessidades abertas → lista prioritária; retomável (quem foi tentado há menos de 14 dias sai da fila). Fonte indisponível não grava zero.

## 3. Fotos (prompt 3.7)

- Foto da API-Football só com vínculo confiável; vínculo novo afeta só o próprio atleta. Fotos antigas: `scout:photos --backfill` (prévia) / `--apply` / `--revert`.
- `scout:photos --check [--apply]`: host permitido (lista), https, sem redirecionamento para fora da lista, tipo e tamanho (até 3 MB), imagem genérica. Estado gravado (ok, quebrada, bloqueada, genérica, não imagem, host não permitido) **sem apagar a URL**; reversível.
- URL quebrada/genérica não é exibida; placeholder com **iniciais** e texto alternativo ("Sem foto: nome") em lista, perfil e quadro.
- Upload manual registra origem e responsável e não é substituído por foto de fonte. Foto da CBF continua armazenada no bucket.
- `scout:photos --report`: foto armazenada, URL por estado, sem foto, sem foto com identidade pendente — com denominador e data.

## Revisão independente

Um revisor separado encontrou 8 defeitos (3 altos) — todos corrigidos com teste de regressão: CBF gerando linha duplicada após a migração; migração colidindo por diferença de caixa; `down()` sem aviso; lista de "parcial/desatualizado" vazia; reverter vínculo de clube não desfazia o vínculo; reversão de verificação de foto sempre em conflito (formato de data); sonda de foto seguindo redirecionamento para qualquer host e sem limite de tamanho; consulta de cobertura lenta (7,8 s com 5 mil atletas → CTEs pré-agregadas e cache de 60 s na tela). Também endurecido o coletor Pro Soccer (redação de CPF/contatos na amostra, redirecionamento só https no mesmo host, confirmação da sessão).

## Testes

259 testes, 1.268 asserções, 0 falhas (PHP 8.3, PostgreSQL 16). Suíte verde não certifica cobertura de dados: os números reais de produção só existem depois do deploy.

## Plano de deploy (aguarda autorização do Gustavo)

1. PR `confiabilidade` → `main` com CI verde; o merge publica web e worker. O pré-deploy faz backup (pg_dump no bucket) e roda 5 migrações aditivas.
2. **Antes de corrigir dados**, só leitura: tela Cobertura de dados (números "antes"), `scout:stats-clubs` (prévia), `scout:photos --report`, `scout:photos --backfill` (prévia), `scout:enrich transfermarkt --scope=cobertura --dry-run`.
3. Com autorização: `scout:stats-clubs --apply --actor=gustavo`, `scout:photos --backfill --apply`, `scout:photos --check --apply --limit=200`; números "depois" na mesma tela.
4. **Rollback**: cada lote tem `--revert`. Código: "Rollback" no Railway para a implantação anterior; o código antigo ignora as colunas novas. Banco: backup do pré-deploy (o `down()` da migração de clube pode abortar se o código novo tiver gravado duas passagens com o mesmo rótulo).

## Limitações e decisões pendentes

- **MFA** obrigatório só para admin (decisão anterior). Recomendo exigir também de quem tem acesso financeiro — muda o login dessas pessoas; decisão sua.
- O vínculo de clubes das **partidas** (API-Football/API Futebol) ainda nasce por nome exato/núcleo; os já criados ficam como "legado". A revisão desses vínculos aparece quando gerarem pendência.
- Pro Soccer: falta a senha no Mac Mini e a resposta da Pro Soccer sobre API/termos.
