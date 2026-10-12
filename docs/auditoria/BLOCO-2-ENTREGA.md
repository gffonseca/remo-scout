# Bloco 2 — entrega (12/10/2026)

Branch `refino`, commits `481a87d..95810e8` (16 commits, 96 arquivos, cerca de +5.600/−290). **Não publicado.** O deploy depende da autorização final do Gustavo e vai junto com o Bloco 1.

## Resultado dos testes

| Onde | Resultado |
|---|---|
| Local (PHP 8.3, PostgreSQL 16) | 203 testes, 926 asserções, 0 falhas |
| Coletor (Python, sem rede) | 6 testes, 0 falhas |
| CI GitHub (`Tests`, run 38261873728, commit `95810e8`) | verde: PHP 8.3 + PostgreSQL 16 e coletor Python |
| Regressões da auditoria | 8 de 8 passam. A F16, que estava incompleta no Bloco 1, foi reativada |

Suíte verde **não** certifica segurança nem cobertura: é evidência dos comportamentos testados.

## Critérios de aceite por achado

| Achado | Aceite da auditoria | Evidência |
|---|---|---|
| F09 Fonte e retry | Dois workers, 429, 403, timeout, resposta válida; cota consistente; freshness | `SourceClientTest` (13). Cota reservada de forma atômica, por dia e por mês, no horário de Brasília: 3 processos reais, 150 tentativas, cota 60, **60 aceitas**. Retry-After em segundos ou data; espera longa pausa a fonte. Circuito: 3 falhas seguidas pausam a fonte por 5 min, dobrando até 6 h. Cache sem gastar cota. URL só no host da fonte. Erro dentro de HTTP 200 conta como falha. Freshness pela última ingestão concluída ou parcial |
| F12 Estatística com contexto | Minuto desconhecido ≠ 0; por 90 com denominador válido; amostra informada | `PlayerStatsViewTest`. A temporada vem do calendário; nenhuma linha é cortada; fonte, nota e data de coleta aparecem; "indet."/"≥"; G/90 só com minutos completos e gols conferidos; `*` marca amostra pequena; estatística parcial aparece como "x de y jogos" |
| F13 Qualidade das partidas | Escalação ausente, evento sem jogador, correção e 120' coerentes; agregados sem registro obsoleto | `FixtureQualityTest` (8), `FixtureRefreshTest`. Qualidade guardada por lado (escalação, trocas, minutos), gols e duração. Reserva sem trocas registradas = participação desconhecida. Prorrogação = 120. Partida antiga não completa = gols não conferidos |
| F14 Identidade | Vínculo só com ID ou nome + nascimento; dúvida vai para revisão | `IdentityTest` (10), `ApiFootballTest`. Descartar um vínculo desfaz campos, foto e estatística e move as atuações; a mescla preserva a trilha da decisão |
| F15 Cidadania × passaporte | Campos distintos; sem promoção por inferência | `CitizenshipTest`. Passaporte só com evidência; nova evidência registra novo conferente |
| F16 Revisão tipada | Valor tipado; aceite só com aplicação confirmada | `TypedReviewTest` |
| F17 Coletor | Retomada sem perda nem duplicidade; parcial explícita; Mac desligado = atrasada | `CollectorManifestTest` (9), `collector/test_coletor.py` (6). Chave por lote; manifesto de páginas esperadas × recebidas; retomada só de execução interrompida; contadores de coleta e de ingestão separados; fonte "Atrasada" após 30 h |
| F20 Matriz | "Operacional" só com ingestão + amostra conferida | `IntegrationMatrixTest`, `docs/auditoria/MATRIZ-INTEGRACOES.md` |

## Fontes acrescentadas nesta sessão (pedidas pelo Gustavo)

| Fonte | Resultado |
|---|---|
| **API-Football (Pro)** | Conector completo: 1 chamada por partida; estatística individual em Série A/B (e Copa do Brasil nas fases finais); escalação em Série C e Nordeste; nascimento para vínculo automático. Campo de lesão descartado. Rodada real: 5 partidas da Série B |
| Dados Futebol (Pro) | Retrato semanal de elenco (Remo + clubes dos prioritários). Não altera o cadastro. Rodada real: 28 atletas do Remo |
| football-data.co.uk | Odds de fechamento da Série A → "Força dos clubes (mercado)". 1.809 jogos conferidos |
| BID CBF | **CAPTCHA**: sem coleta automática. Consulta assistida, registrada como observação contratual com data, link e autor |
| Sofascore | Sob demanda pelo coletor do Mac (decisão do Gustavo). O servidor nunca acessa a rede; o Mac só busca o que foi pedido. A rodada semanal automática foi removida |
| Sportmonks | Plano Free sem Brasil → não autorizada |
| kariofreire/api-futebol-brasileiro | Descartada (raspagem de 2022, Série A, só time) |

## Revisão independente

Um agente separado, que não escreveu o código, revisou o diff contra a auditoria. Encontrou **1 problema grave, 7 médios e 7 baixos**, todos corrigidos no commit `95810e8`, com testes:
- **Grave:** descartar um vínculo deixava as atuações de outra pessoa no atleta.
- **Médios:**
  - reserva sem trocas virava "0 minutos";
  - a mescla apagava o registro da decisão;
  - a mescla não reconstruía os totais da API-Football;
  - partidas antigas eram lidas como "gols conferidos";
  - a retomada reaproveitava uma execução parcial já encerrada;
  - o circuito não abria com erro dentro de HTTP 200;
  - estatística parcial aparecia como total.
- **Baixos:**
  - cota errada em instalação nova;
  - candidato decidido podia ser reaberto pela migração;
  - dado bruto do provedor sem lista branca;
  - falha na página 1 da CBF;
  - competição sem edição deixava toda execução parcial;
  - erro real tratado como "duplicado";
  - conferente do passaporte não era atualizado.

Correção estrutural extra: o índice único de estatísticas passou a tratar NULL como igual, com as duplicatas removidas na migração.

## Migrações (12, todas aditivas)

`2026_10_14_000100` … `001200`:
- **Revisões e identidade:** revisões tipadas; cidadania/passaporte; identidade (candidatos e status de vínculo).
- **Fontes:** controles da fonte (cota mensal, circuito, freshness); matriz de integrações.
- **Dados novos:** elenco por clube; qualidade por campo; API-Football (`appearances.stats`, `provider_athletes`, fonte ligada com 7.000/dia); odds.
- **Coletor:** lotes e tarefas.
- **Ajustes de esquema:** `appearances.used` aceita nulo; unique de estatísticas com NULLS NOT DISTINCT.

Efeitos a conhecer após o deploy:
- **Identidades a revisar:** os 119 vínculos CBF por nome + clube e os vínculos antigos do Transfermarkt e da API Futebol sem nascimento aparecem lá. Descartar um vínculo desfaz o que veio dele.
- **Passaporte:** atletas marcados "Sim" sem evidência passam a "não informado", com uma nota.
- **Lista de atletas:** oculta, por padrão, os cadastros provisórios criados a partir de partidas (há filtro para mostrá-los).
- **Execuções antigas da API Futebol:** os totais passam a mostrar "≥" quando houve divergência. Minutos de prorrogação gravados antes continuam em 90 até a partida ser reconsultada.

## Plano de deploy (junto com o Bloco 1; aguardando autorização)

1. **Antes:**
   - backup manual do Postgres no Railway;
   - confirmar as variáveis `API_FOOTBALL_KEY` e `DADOS_FUTEBOL_KEY` no web e no worker (já gravadas, sem deploy).
2. **Publicar:** PR `refino` → `main` com o CI verde. O pre-deploy roda as migrações e depois os seeders, que preenchem fontes novas, autorizações e coberturas só onde estiver vazio.
3. **Coletor no Mac Mini:** atualizar para 1.1.0 com `collector/instalar-mac.sh`. Isso instala o agente diário 06:20 e o agente de tarefas a cada 15 min. A versão 1.0.2 continua compatível até lá (sem idempotência e sem tarefas do Sofascore).
4. **Conferir depois:**
   - **Fontes de dados:** coluna "Integração" e a cota de cada fonte.
   - **Sincronizações manuais:** API-Football e "Atualizar elencos" (Dados Futebol).
   - **Telas:**
     - Força dos clubes;
     - perfil de um atleta da Série A (bloco API-Football);
     - Identidades a revisar (volume esperado de vínculos antigos).
   - **Coleta CBF:** a das 06:20 do dia seguinte, com manifesto e status.
5. **Rollback:** "Rollback" no Railway para a implantação anterior. O código antigo convive com o esquema novo, com uma exceção: `appearances.used` nulo seria lido como "não entrou" pelo código antigo, ou seja, uma regressão apenas de exibição. Se for preciso voltar o banco, restaurar o backup do passo 1.

## Limitações abertas

- **Prorrogação em partidas antigas:** as que já estão gravadas só são corrigidas quando a partida é reconsultada.
- **Cobertura da API Futebol e da CBF:** precisa da conferência do analista (Rafael) para essas fontes virarem "Operacional".
- **Sofascore:** depende do Mac ligado e pode bloquear o IP residencial (risco aceito pelo Gustavo).
- **API-Football:** o plano Pro vence em 12/11/2026, sem renovação automática. Medir o uso antes de decidir.
- **Chaves coladas no chat:** trocar API Futebol, Dados Futebol, Sportmonks e API-Football.
