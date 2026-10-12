# Fontes para o CIFUT Remo — avaliação em 12/10/2026

**Recomendação:** Football-Data.co.uk é tecnicamente extraível e pode ser fonte complementar de partidas/contexto de clubes. Para estatísticas individuais, testar primeiro API-Football e Sportmonks. O artigo da LSports é um catálogo comercial de fornecedores, não um feed a extrair para fichas de atletas.

## 1. Football-Data.co.uk: prova real de extração

A página data.php retornou 429 no navegador de pesquisa, mas uma requisição HTTP convencional conseguiu obtê-la com status 200, assim como o CSV público brasileiro. Isso distingue limitação do método de pesquisa de indisponibilidade geral. Não foram usadas credenciais nem técnicas de contorno de proteção.

Fontes: [página original](https://football-data.co.uk/data.php), [Brasil](https://football-data.co.uk/brazil.php), [CSV efetivamente baixado](https://football-data.co.uk/new/BRA.csv), [dicionário](https://football-data.co.uk/notes.txt).

Verificação do arquivo obtido em 12/10/2026:

| Item | Resultado |
|---|---|
| HTTP / formato | 200 / CSV UTF-8 com BOM |
| Tamanho / campos | 644.551 bytes / 25 colunas |
| Registros | 5.609 |
| Competição no arquivo | Serie A, Brasil |
| Temporadas | 2012–2026 |
| Conferência da soma | 14 × 380 + 289 = 5.609 |
| Datas | 19/05/2012–09/10/2026 |
| Partidas com Remo | 29 linhas, todas em 2026 |
| Duplicidades | 0 na chave temporada/data/mandante/visitante |
| Resultado ausente | 1 linha, Chapecoense-SC × Atletico-MG, 11/12/2016 |
| Resultado e gols | 5.608 linhas preenchidas internamente consistentes; nenhum conflito nessa verificação |
| Atletas, escalações, minutos individuais | Não existem no schema brasileiro examinado |
| SHA-256 | 02604bab08431da8bf22b1f80e62ce075883fc50be843b6c02f0102127c2ae2b |

As contagens, datas e consistência foram calculadas sobre o CSV baixado. Não houve certificação externa dos placares. A linha ausente não deve virar 0–0 nem partida realizada. As 29 linhas do Remo são o conteúdo da fonte, não prova de completude de todos os jogos do clube.

Campos centrais: Country, League, Season, Date, Time, Home, Away, HG, AG, Res; os outros 15 campos são odds de fechamento, médias/máximos e bookmakers. A documentação geral lista também estatísticas de equipes quando disponíveis em outros arquivos; isso **não** significa que o Brasil tenha chutes, escanteios, cartões ou jogadores.

O site declara download gratuito. Não foi identificada licença explícita que esclareça uso institucional/comercial automatizado, retenção e redistribuição; preço zero não resolve esse ponto. Confirmar os usos pretendidos com o responsável antes de ativar uma ingestão permanente. [Contato oficial](https://football-data.co.uk/contact.php).

**Uso proposto:** histórico de resultados, mando, forma de equipe, calendário histórico e contexto dos adversários. Uma medida derivada de força de equipe pode ser explorada, mas precisa de método validado e não deve virar correção automática de nota de atleta. Não fornece dribles, passes-chave, xG individual, mapas de calor, salários ou notícias. O CSV brasileiro examinado não inclui Série B/C/D nem estaduais. Football-Data.co.uk não é football-data.org nem API-Football.

## 2. Artigo LSports: como aproveitar

O [artigo informado](https://www.lsports.eu/pt-br/blog/9-melhores-apis-de-dados-esportivos-para-apostas-e-analises-em-2026/) enumera nove fornecedores e promove a própria LSports. Seu critério dominante é operação de apostas. “Scouts Feed” ali se refere à coleta ao vivo de eventos, não a pareceres de olheiros sobre contratação. É útil para descobrir fornecedores, mas não comprova adequação ao scouting do Remo.

Abaixo, a classificação é **minha aplicação ao CIFUT**, com verificação adicional de páginas oficiais. Nenhuma API paga/autenticada foi chamada e nenhuma assinatura foi iniciada. Para todas, licenciamento, cobertura do alvo e completude por campo permanecem condicionantes.

| Fornecedor | Extração / utilidade verificada | Decisão sugerida | Fonte primária |
|---|---|---|---|
| LSports | Produto comercial de feeds esportivos/odds, acesso negociado; profundidade individual no alvo não comprovada | Baixa prioridade para este MVP; avaliar se pacote específico trouxer dados úteis | [Sports API](https://www.lsports.eu/sports-api/) e [documentação](https://docs.lsports.eu/u) |
| The Odds API | REST para eventos, placares e mercados de apostas; props não equivalem a desempenho completo | Não priorizar para prospecção individual | [API V4](https://the-odds-api.com/liveapi/guides/v4/) |
| Sportmonks | REST/JSON, players e relações de estatísticas, equipes, transferências e escalações | **Candidata ao teste comparativo** | [Players](https://docs.sportmonks.com/v3/endpoints-and-entities/endpoints/players) |
| Stats Perform / Opta | Dados e produtos explicitamente voltados a análise, recrutamento e planejamento | Opção para etapa avançada mediante proposta/cobertura | [Performance e recrutamento](https://www.statsperform.com/team-performance/performance-solutions-for-football/) |
| SportsDataIO | Endpoints de estatísticas por atleta/jogo/temporada e box scores | Reserva; Brasil e ligas alvo não confirmados; trial documentado restringe futebol à Champions League | [Documentação Soccer](https://sportsdata.io/developers/api-documentation/soccer) |
| API-SPORTS / API-Football | Perfis e estatísticas individuais por temporada e partida; cobertura por competição/ano | **Primeiro teste recomendado** | [Guia oficial](https://www.api-football.com/news/post/how-to-get-started-with-api-football-the-complete-beginners-guide) |
| TheRundown | Odds, eventos, scores e estatísticas onde suportadas; foco de apostas | Não priorizar sem prova de dados individuais brasileiros | [Produto oficial](https://therundown.io/) |
| Sportradar | Soccer API com perfis, elencos, estatísticas e eventos; níveis de cobertura e autenticação | Opção avançada, dependente de pacote e amostra | [Soccer v4](https://developer.sportradar.com/soccer/reference/soccer-overview) |
| SportsGameOdds | API de odds/mercados com endpoint de identificação de players | Não priorizar; endpoint players não comprova métricas completas de scouting | [Players](https://sportsgameodds.com/docs/endpoints/getPlayers) |

## 3. Escolha prática: API-Football versus Sportmonks

**API-Football:** a lista pública inclui Paraense, Copa Verde, Série A/B/C/D e outras competições brasileiras. A existência na lista não garante estatísticas de todos os atletas/jogos. Consultar o objeto coverage por liga/temporada e depois medir respostas reais. A documentação reconhece disponibilidade parcial mesmo com flags positivas. [Lista de cobertura](https://www.api-football.com/coverage).

Referência comercial verificada em 12/10/2026: gratuito 100 requisições/dia e temporadas limitadas; Pro US$ 19/mês e 7.500/dia; Ultra US$ 29/mês e 75.000/dia. São preços publicados, sem câmbio, tributos ou contratação. O artigo de terceiros não deve controlar orçamento. [Preços oficiais](https://www.api-football.com/pricing).

**Sportmonks:** documentação permite buscar atletas por ID/país/nome e carregar estatísticas/clubes/transferências. Histórico pode ser incompleto. Plano inicial publicado a partir de €29/mês para cinco ligas; xG e outros pacotes podem ter custo adicional. A página comercial tem mensagens amplas de recursos inclusos, mas lista adicionais: confirmar o pacote efetivo. Free plan informa ligas dinamarquesa e escocesa, portanto não prova cobertura brasileira. [Planos](https://www.sportmonks.com/football-api/plans-pricing/) e [produto](https://www.sportmonks.com/football-api/).

No snapshot CIFUT auditado, database/seeders/DataSourcesSeeder.php já cadastra api_football e sportmonks, desativadas/aguardando acesso. Em app/Sources não foram encontrados adaptadores completos desses dois fornecedores. Assim, habilitar um registro ou preencher token não basta: implementar coletor, validação, reconciliação e teste ponta a ponta.

## 4. Como incorporar Football-Data no modelo do CIFUT

Implementação proposta, sem mudança em produção:

1. Fonte football_data_co_uk, inicialmente em staging, categoria CSV de resultados/contexto; registrar permissão de uso separada de saúde técnica.
2. Download por job agendado de baixa frequência, condicionado a atualização/hash; timeout, tamanho máximo, tratamento de 429/5xx e respeito ao Retry-After. Uma consulta diária é ponto de partida proposto; adaptar à política e frequência da fonte. Não precisa de polling ao vivo.
3. Guardar origem, coleta em UTC, hash/schema, competição e temporada. Arquivo bruto somente conforme permissão de retenção. Resultado ausente é null; não inventar status final. Hora raw preservada até confirmar fuso: não converter silenciosamente para America/Belem.
4. Importar partidas e contexto de clubes em camada apropriada; não criar PlayerAppearance nem PlayerSeasonStat a partir de dados agregados. Manter odds fora dos indicadores de contratação; deixá-las desligadas no MVP.
5. Mapear nomes de clubes com alias revisados. Remo pode ser candidato ao Clube do Remo, mas validar ID; não juntar Atletico-MG e outros clubes por similaridade irrestrita. Não há ID global de partida neste CSV: chave de reconciliação e tratamento de mudança de data são necessários.
6. Upsert idempotente e revisão de correções; preservar cada origem ao cruzar com CBF/API Futebol. Não sobrescrever minutos/atletas provenientes de outra fonte. Marcar ingestão concluída com linhas esperadas, válidas, pendentes e rejeitadas.

Critérios de aceite: segunda ingestão do mesmo arquivo não duplica partidas; mudança de placar gera revisão/versionamento; linha sem placar permanece pendente; mudança de data é reconciliada; nenhum jogador ou minuto é fabricado; painel diferencia “baixou arquivo” de “cobertura validada”.

## 5. Prova e próximo teste

O pacote inclui um extrator/validador Python de biblioteca padrão, os 5.609 registros de partidas normalizados **sem odds**, o recorte de 29 linhas do Remo e a verificação JSON. São arquivos de estudo locais, não dados homologados para inserir cegamente na produção. O script lê arquivo local ou baixa o CSV público uma vez, grava somente no diretório escolhido e não se conecta ao CIFUT.

Uso: `python3 validar-football-data.py --arquivo BRA.csv --saida prova`; sem --arquivo efetua o download público. O CSV de saída usa ponto e vírgula e preserva timezone=unknown. Ele não é compatível automaticamente com o importador de atletas do CIFUT.

Próximo teste para os fornecedores: selecionar 20–30 atletas e uma amostra de partidas nas competições alvo; confirmar identidade, minutos, escalações, campos por papel, histórico e correções; contabilizar quota e campos ausentes; comparar com fontes independentes. Aprovar API pelo resultado desse teste, não pelo total de ligas anunciado. Nenhum teste autenticado ou contratação foi realizado nesta etapa.
