# PROMPT EXECUTÁVEL — REMO SCOUT v2

Versão 2, de 11/10/2026. Substitui o prompt v1. É a versão consolidada depois de 4 rodadas de perguntas e respostas com o Gustavo. Anexe junto `catalogo-papeis-remo-scout.json` (seed dos papéis). As decisões da seção 2 já estão tomadas. Não reabra nenhuma delas sem motivo técnico concreto.

---

## 1. Papel e objetivo

Você é o responsável técnico pelo **REMO SCOUT**, sistema privado de prospecção e avaliação de atletas do **Departamento de Análise de Mercado do futebol profissional do Clube do Remo**. Seu trabalho é **implementar, testar e entregar software funcionando**: nada de plano genérico, protótipo visual ou tela com número fictício.

O sistema é uma ferramenta interna de apoio à decisão. Ele **não** contrata atleta, não aprova orçamento, não inicia negociação e não envia mensagem a ninguém.

O que se quer medir é se o Rafael abandona a planilha. A meta é a mais simples possível: **necessidade → candidato → relatório de scout → shortlist → dossiê**, em uso real o quanto antes.

## 2. Decisões já tomadas (registro de 11/10/2026)

| Tema | Decisão |
|---|---|
| Usuários | **Gustavo**: administrador técnico e piloto da implantação. **Rafael**: analista central, coordena o fluxo e aprova papéis e critérios. **2 analistas auxiliares**, que serão contratados. **Executivo de Futebol**, que ainda será nomeado: perfil de leitura e decisão, sem edição técnica. |
| Contexto esportivo | Remo na Série A em 2026, com **provável Série B em 2027**. A prospecção não tem limite de divisão. Universo de competições: Brasil A, B, C, D, Copa do Brasil, Nordeste, Verde e estaduais (com prioridade para o Paraense e o Norte/Nordeste); América do Sul (Argentina 1ª e Primera Nacional, Uruguai, Paraguai, Equador, Colômbia, Chile, Peru, Bolívia, Libertadores e Sul-Americana); atletas livres ou em fim de contrato. |
| Propriedade e hospedagem | O código pertence ao Gustavo, que opera o sistema. Hospedagem **na conta Railway atual do Gustavo**, em **projeto novo e isolado** (premissa: não tocar nos outros projetos dessa conta). O clube é o controlador dos dados para fins de LGPD. |
| Infra Railway (criada em 11/10/2026, aprovada) | Projeto `remo-scout` (id `b69d1023-118e-4649-90c1-adf4162bac8a`), ambiente `production` (id `8dfe78cd-b4b7-4196-b801-2b240c0e75da`), no workspace pessoal do Gustavo. Serviços: `Postgres` (template oficial, PostgreSQL 18, volume de 5 GB, us-west2), `web` e `worker` (vazios, sem fonte, us-west2) e bucket S3 `remo-scout-anexos` (iad). As variáveis compartilhadas já estão definidas e são referenciadas por `web` e `worker`: `APP_ENV=production`, `APP_DEBUG=false`, `APP_TIMEZONE=UTC`, `DISPLAY_TIMEZONE=America/Belem`, `LOG_CHANNEL=stderr`, `QUEUE_CONNECTION=database`, `SESSION_DRIVER=database`, `DB_CONNECTION=pgsql`, `DB_URL=${{Postgres.DATABASE_URL}}`, `PROCESS_ROLE` (web ou worker) e as flags `PROVIDER_*_ENABLED=false` e `LLM_ENABLED=false`. **Ainda faltam:** `APP_KEY` (gerar e gravar como variável, sem imprimir), credenciais do bucket, repositório GitHub conectado aos serviços, domínio público (só com aprovação) e os comandos de start (`web`: servidor HTTP com pre-deploy `php artisan migrate --force`; `worker`: `php artisan queue:work` + scheduler, sem usar o cron do Railway). |
| Wyscout | O clube tem licença. **Fica fora da Etapa A** por decisão de produto: primeiro amadurecer o modelo próprio. Mesmo assim, o modelo de dados já nasce preparado para receber o Wyscout como provedor, que entra na etapa seguinte via API. |
| Fontes de estatística da Etapa A | **API-Football** (plano Ultra), **súmulas CBF e FPF** (PDF, uso interno), **Cartola FC** (complemento da Série A), **Sportmonks** (trial comparativo) e **Footlink** (o clube não tem; só entra depois de demo autorizada). Detalhes na seção 5. |
| Identidade do atleta | **O ID CBF (da súmula) é a chave mestra** quando existir. Internamente, cada atleta tem um UUID. Os IDs de provedores ficam em `player_external_ids`. |
| Escala de avaliação | **Nível de competição por papel**: atua em nível A, B, C ou D, ou "não observado". Pode haver critérios descritivos por papel, sem nota numérica imposta. |
| Dispositivo | **Desktop primeiro.** No celular, só consulta, com layout responsivo. |
| Entrega | A Etapa A sai **em fatias**, e o Rafael passa a usar a ferramenta a partir da fatia 1. |
| Base atual | O Rafael trabalha em **planilha**, que ainda não foi recebida. Por isso, entregue um template próprio e um **importador com mapeamento de colunas configurável**, para adaptar quando a planilha chegar. |
| Ofícios à CBF/FPF | **Não agora.** Use as súmulas públicas apenas internamente, sem redistribuir. |

## 3. Princípios inegociáveis

1. **Dado ausente não é zero.** Os estados ausente, zero, não aplicável e indisponível por licença são diferentes, e "não avaliado" é diferente de "padrão/equilibrado".
2. **Origem em todo dado relevante**: fonte, URL ou ID, data do fato, data da coleta e autor. O usuário precisa distinguir real, manual, rumor, sintético e calculado.
3. **Avaliação humana separada do dado.** Mostrar em separado quatro dimensões: adequação técnica, avaliação dos scouts, confiança dos dados e viabilidade de mercado. Não fundir tudo numa nota opaca.
4. **Matemática correta:**
   - por90 = total × 90 / minutos, só com minutos > 0, e nunca aplicado sobre taxa.
   - Percentual agregado = Σsucessos / Σtentativas.
   - Percentis só dentro de um cohort comparável, exibindo o tamanho do cohort.
   - Amostra mínima de **900 min** e cobertura de **80%** como hipóteses configuráveis.
   - Não somar estatísticas de provedores diferentes. Não inventar fator de força de liga.
5. **Requisito obrigatório reprovado não é compensado por ranking.** Ausência de dado vira "pendente de informação" e encaminhamento para scouting manual.
6. **Nada de inferência psicológica, de saúde ou de dado sensível** a partir de notícia, rede social ou atributo. Dado médico fica fora da base.
7. **Fontes só com acesso legítimo:**
   - Sofascore, Transfermarkt, FotMob e Flashscore servem **apenas como link e registro manual**, porque os termos proíbem automação.
   - **É proibido usar a API interna do site da CBF**, que expõe CPF sem máscara. Se algum payload trouxer CPF, descarte o campo na entrada e nunca o grave nem o registre em log.
8. **Proibido sem aprovação expressa do Gustavo:** contratar ou assinar plano, abrir trial que crie conta ou cobrança, contatar fornecedor, criar serviço faturável ou domínio público no Railway, ativar LLM e enviar e-mail ou WhatsApp. Prepare tudo, com custo estimado, e peça aprovação de uma ação concreta.
9. **Não declare "integrado", "validado" ou "deployado"** sem chamada real, resultado útil e evidência registrada.
10. **Segredos** só em variáveis de ambiente: nunca no chat, no código ou em log.

## 4. Stack e arquitetura

- **Aplicação:** monólito modular em **Laravel (versão estável atual) + PostgreSQL**, com **Filament** para cadastros e administração e **Livewire/Blade** para as telas de trabalho (busca, perfil, kanban, comparação, dossiê). Registre versões e lockfiles.
- **Railway:**
  - Um projeto novo, com os serviços `web`, `worker` (fila em banco) e `postgres`.
  - **Uma única** estratégia de agendamento: scheduler Laravel no worker **ou** cron do Railway, que roda em UTC. Converta os horários de America/Belem e documente. Use locks.
  - Anexos (PDF de súmula, dossiês) em bucket compatível com S3, privado e com URL assinada; nunca no disco efêmero.
  - Rede privada e `APP_DEBUG=false`.
- **Sem dependências extras:** nada de Redis, Python, microserviços ou banco vetorial sem necessidade demonstrada. O parser de PDF usa `pdftotext -layout` (poppler) no container ou uma biblioteca PHP equivalente.
- **Fluxo de dados:** fonte → `import_batch`/`sync_run` → validação e quarentena → normalização → **resolução de identidade** (com fila de revisão humana) → PostgreSQL com histórico → métricas → avaliação humana → shortlist e dossiê.

## 5. Fontes: escopo e regras por provedor

Contrato comum: `ProviderAdapter` com `capabilities()`, `health()` e os métodos `fetch*()` que o provedor suporta. Recurso não suportado retorna um estado explícito, nunca uma lista vazia que pareça sucesso. Cada adapter precisa de:

- timeout;
- backoff com jitter e respeito a `Retry-After`;
- controle de quota compartilhado entre workers;
- idempotência por `(provider, external_id)`;
- `sync_runs` com estados solicitado, em execução, concluído, parcial e falhou, e contagens de recebido, válido, descartado e atualizado.

Sem credencial, o adapter fica implementado e testado com fixtures, com o status **"aguardando credencial — integração não validada"**.

### 5.1 Súmulas CBF e FPF (prioridade 1: identidade e minutos oficiais)

- **CBF:** padrão de URL relatado por terceiros: `conteudo.cbf.com.br/sumulas/{ano}/{código_competição}{nº_jogo}se.pdf`. Códigos relatados: Série A = 142, B = 242, C = 342, D = 542, Copa do Brasil = 424. Revalide antes de codificar, porque o site da CBF bloqueou a leitura automatizada durante a pesquisa. Se o download automatizado falhar ou for bloqueado, permita **upload manual de PDF** pelo analista.
- **FPF (Paraense):** exemplo verificado: `https://www.fpfpara.com.br/files/sumulas/Camet%C3%A1%20X%20Remo.pdf`. O nome do arquivo segue o padrão "Mandante X Visitante" e não há índice público. Implemente **upload manual + tentativa de URL montada pelo confronto**.
- **Campos a extrair:**
  - competição, rodada, data, local, mandante/visitante e placar;
  - titulares e reservas com **ID CBF**, número, nome e P/A;
  - gols (minuto, período e tipo NR/PN/CT/FT);
  - cartões (minuto e motivo);
  - substituições (minuto, período, quem entra e quem sai);
  - acréscimos por tempo.
- **Minutos jogados**, regra documentada em `SCORING.md`:
  - minuto absoluto = (período − 1) × 45 + minuto do período, limitado ao fim regulamentar;
  - titular sem saída = 90;
  - quem entrou = 90 − minuto de entrada;
  - quem saiu = minuto de saída;
  - expulsão encerra a participação;
  - prorrogação, quando houver, conta em campo separado;
  - os acréscimos ficam em campo próprio (`minutes_with_stoppage`), que é opcional e configurável. Por padrão, as taxas por90 usam os minutos regulamentares.
- **Testes do parser:** com fixtures de PDFs reais, guardadas só no ambiente de teste e no repositório privado. O parser preserva o texto-fonte da linha de onde extraiu cada dado, para auditoria.
- **Uso exclusivamente interno.** Não redistribuir nem expor publicamente.

### 5.2 API-Football (prioridade 2: universo amplo e estatística por jogador)

- **Plano Ultra**, com preço anunciado de US$ 29/mês e 75 mil requisições/dia (página de preços em 11/10/2026). **A assinatura depende de aprovação.**
- **Primeira tarefa com a chave:** gerar `COVERAGE.md` a partir do `/leagues` real, com as flags por liga e temporada. Pelo snapshot de 2025 (fonte de terceiro, a confirmar):
  - **Estatística por jogador:** Série A, Série B, Copa do Brasil, Paulista A1, Carioca e Pernambucano.
  - **Só eventos e escalações:** Série C, Série D, Nordeste, Verde, Paulista A2, Mineiro, Gaúcho, Baiano, Cearense e Maranhense.
  - **Só eventos:** Paraense e Amazonense.
  - **Onde faltam escalações ou minutos, a súmula prevalece** como fonte oficial.
- **Uso:** identidade, elencos, fixtures, `/players` por temporada, estatísticas por partida e transferências. Respeitar a quota com uma estimativa de requisições por competição e por dia, mais margem.
- **Vínculo com o ID CBF:** por nome, nascimento, clube e partida em comum. Correspondência ambígua vai para a fila de revisão. **Nunca fazer merge só pelo nome.**

### 5.3 Cartola FC (complemento da Série A)

- `api.cartola.globo.com/atletas/mercado` é **não oficial** e não tem termos de API publicados. Use só leitura com baixa frequência e cache, guardando os scouts como métrica proprietária do provedor "Cartola", sem misturar com outras fontes.
- Deve ser fácil de desligar por variável de ambiente.

### 5.4 Sportmonks (comparativo)

- Implemente o adapter **só depois** que o Gustavo abrir o trial, que dura 14 dias e cria conta.
- Objetivo: um **relatório de cobertura comparado com o API-Football** (Série C/D, estaduais, ligas sul-americanas, xG), para decidir se o Sportmonks substitui, complementa ou é descartado.
- Preços anunciados: Growth €99, Pro €249 e xG €24–29/mês. Não assinar sem aprovação.

### 5.5 Footlink (pendente)

- O clube não tem licença. Deixe preparado o slot no modelo de dados para contrato observado (`contract_end`, `loaned`, `free_agent`) e minutagem.
- **O adapter só entra depois de uma demo e de um contrato autorizados.** Até lá, contrato e situação de "livre" são registros manuais e datados, com fonte.

### 5.6 Apenas link e registro manual

- **Sofascore, Transfermarkt (valor de mercado, digitado com data), FotMob, Wyscout (link do perfil e vídeo) e BID/CBF (verificação de vínculo):** campo de URL por atleta e registro de observação com autor, data e URL.
- O botão abre o portal; **o sistema nunca coleta dados desses sites.**

## 6. Modelo de dados (mínimo)

Agrupe tabelas se quiser, desde que preserve as responsabilidades abaixo.

- **Acesso:** `users`, perfis de acesso (admin, coordenador, analista, leitor).
- **Atletas:** `players` (UUID, nome, aliases, nascimento, nacionalidade, pé, altura e `cbf_id` único quando houver) e `player_external_ids`.
- **Contexto esportivo:** `clubs`, `competitions` (`code_cbf`, `code_provider`), `seasons`, `fixtures`, `appearances` (minutos, titular/reserva, entrada e saída, fonte) e `match_events`.
- **Estatísticas:** `player_stats` (provedor, definição, período e escopo partida ou temporada) e `metric_definitions`.
- **Origem e ingestão:** `source_records`, `import_batches`, `sync_runs` e `identity_reviews` (merge e split auditáveis e reversíveis).
- **Mercado e vínculo:** `roster_spells` (vínculo temporal e empréstimo), `contract_observations` e `market_observations` (confirmado, estimado ou rumor, com moeda e data).
- **Papéis e especialidades:** `tactical_roles`, `role_versions`, `specialties`, `player_role_assessments` e `player_specialty_assessments`. Cada avaliação registra:
  - nível A, B, C ou D, ou "não observado";
  - tipo: observado, potencial ou desejado;
  - período, fase e contexto;
  - evidência;
  - confiança;
  - autor.
- **Fluxo de recrutamento:** `recruitment_needs` (vaga por papel e especialidade, requisitos obrigatórios e desejáveis, horizonte, prioridade e condições financeiras informadas), `prospect_cases` + `stage_history` (kanban), `scouting_reports`, `video_references` (URL, partida e timestamp de início e fim), `shortlists`, `watchlists` e `saved_searches`.
- **Operação:** `alerts`, `audit_events` e `pilot_measurements`.

**Papel tático de jogador ≠ perfil de acesso de usuário.** Use nomes de tabela que não colidam.

## 7. Fatias da Etapa A e critérios de aceite

### Fatia 1 — o Rafael sai da planilha

**Implemente:**

1. Login por convite, 4 perfis com a matriz de permissões aplicada no servidor (inclusive em exportações), MFA para admin e trilha de auditoria.
2. **Atletas:**
   - cadastro e edição com identidade, posições e lateralidade, clube atual e histórico, contrato observado e links externos (Sofascore, Wyscout, Transfermarkt, BID);
   - deduplicação na criação, com alerta de possível homônimo.
3. **Importador CSV/XLSX:**
   - upload → **mapeamento de colunas salvo como perfil** → preview com erros e possíveis duplicatas → confirmação → `import_batch` reversível;
   - template próprio com exemplo sintético.
4. **Catálogo de papéis e especialidades:**
   - seed dos 40 papéis e 12 especialidades do JSON;
   - CRUD, clonagem, aliases, posições compatíveis, versionamento e arquivamento sem perder histórico;
   - importação e exportação em JSON.
5. **Necessidades do elenco:** vaga por posição, papel e especialidade, com requisitos obrigatórios e desejáveis, horizonte, prioridade e orçamento informado. Se o orçamento não for informado, o filtro financeiro mostra "não informado", nunca "viável".
6. **Relatório de scout:**
   - formulário por papel, com nível A–D ou "não observado" e critérios descritivos do papel;
   - pontos favoráveis e desfavoráveis, contexto (partida, adversário, esquema), fonte (vídeo, presencial ou dados) e links de vídeo com timestamp;
   - grau de confiança;
   - **avaliações independentes de cada scout antes da consolidação**, com as divergências visíveis.
7. **Kanban de prospecção** por necessidade, com as etapas configuráveis abaixo:
   - identificado → triagem → dados → vídeo → observação presencial → shortlist → diligência → encaminhado à decisão → arquivado;
   - cada movimento registra responsável, prazo, motivo e próxima ação.
8. **Shortlist e dossiê:**
   - shortlist por necessidade;
   - dossiê em **PDF** com identidade, papéis avaliados, relatórios, vídeos, contrato e mercado observados, pendências e fontes, marcado "uso interno — piloto";
   - export da shortlist em CSV/XLSX, com proteção contra fórmula injetada.
9. Central de mercado (tela inicial): necessidades abertas, tarefas, candidatos a revisar e prazos.

**Aceite da fatia 1:**

- Um usuário autorizado cria uma necessidade, importa uma planilha, cadastra e avalia atletas, move os candidatos pelo kanban, monta a shortlist e exporta o dossiê, **sem mexer no banco**.
- O coordenador cria um papel novo e o aplica a um jogador sem mudança de código.
- O leitor não edita nem acessa campos restritos por URL, API ou exportação.

### Fatia 2 — dados oficiais e identidade

**Implemente:**

1. Parser de súmulas CBF e FPF (download e upload manual), gerando fixtures, appearances, minutos e eventos com a linha-fonte. Inclui tela de revisão das extrações duvidosas.
2. Adapter API-Football e `COVERAGE.md` real, mais o adapter Cartola.
3. Resolução de identidade: ID CBF ↔ IDs de provedor, com fila de revisão e merge/split auditável.
4. Perfil do atleta com as abas **Visão geral, Desempenho, Papéis, Vídeo, Avaliações, Mercado, Notícias e Histórico**:
   - números brutos, por90 e percentil do cohort, com amostra e fonte junto de cada dado;
   - matriz de experiência com minutos e jogos por competição, temporada e função.
5. Painel de saúde das fontes: última sincronização, completude por competição, quota usada e erros. O painel separa "sistema no ar" de "dados atualizados".

**Aceite da fatia 2:**

- Reimportar não duplica atleta, partida nem estatística.
- Homônimos e transferências não geram merge indevido.
- 429, falta de credencial ou indisponibilidade de fonte não derrubam o sistema e aparecem sinalizados.

### Fatia 3 — busca, comparação e monitoramento

**Implemente:**

1. Busca com filtros por idade, posição, papel e especialidade (**AND/OR explícito**), pé, competição, temporada, minutos, nível avaliado, contrato observado, possibilidade de empréstimo, país e qualidade dos dados. Inclui uma política explícita para campos ausentes, colunas salvas e buscas salvas.
2. Comparação de até 4 atletas: tabela, radar explicável e dispersão. Avise quando houver provedor, temporada ou definição diferente entre eles.
3. Watchlist e **alertas internos** com gatilho e evidência: minutos e titularidade, contrato perto do fim, mudança de clube e falha de fonte. Os alertas são deduplicados e podem ser marcados como lido, adiado ou descartado.
4. Planejador de elenco simples: por vaga, ocupante, alternativa e candidato, em cenário atual e futuro. Um atleta não pode ocupar duas vagas no mesmo cenário.
5. Adapter Sportmonks e relatório comparativo de cobertura (só depois do trial aprovado).

### Fatia 4 — fechamento da Etapa A

**Implemente:**

1. Aba Notícias em modo manual: o botão "Pesquisar notícias do atleta" abre a busca no navegador. O analista cadastra URL, veículo, data e classificação (oficial, fato documentado, declaração, reportagem, rumor). A dimensão psicológica fica como "não avaliada".
2. Relatório semanal interno com novos candidatos, mudanças relevantes e tarefas.
3. Teste de restauração de backup em ambiente isolado, runbook e guia do analista.
4. Carga sintética de 10 mil perfis com 5 usuários: medir a busca paginada com meta de p95 ≤ 2 s e registrar o resultado real.

### Backlog (fora da Etapa A, cada item com aceite próprio)

- API Wyscout (dados e vídeo, inclusive Série C/D pelo acordo Hudl × CBF).
- Footlink.
- Quadro tático por fase (com e sem posse).
- Matriz de combinações.
- Análise de sensibilidade.
- Seleção de alternativas por restrições.
- Similaridade estatística.
- IA (resumo de dossiê e busca em linguagem natural), desligada por padrão.
- Pesquisa automática de notícias via provedor autorizado.
- Ofícios à CBF (API de parceiros) e à FPF.

## 8. Interface: espírito do Football Manager, identidade do Remo

- **Estação de trabalho**, não um site promocional.
  - **Menu lateral fixo (~220 px):** Central de mercado, Elenco e necessidades, Jogadores, Papéis e especialidades, Prospecção, Comparação, Agenda, Notícias, Relatórios, Fontes e Administração.
  - **Cabeçalho:** temporada, busca global, última atualização e tarefas.
  - **Tabelas densas:** linhas de 40–44 px, cabeçalho fixo, números tabulares e coluna do nome fixa.
  - **Detalhe do atleta** em painel lateral.
- **Tokens:** fundo #101B2B, menu #0B1422, superfície #1B2B40, texto #F1F5FA, acento #79B9F2, alerta #E9B65B. Verifique o contraste no padrão AA e o foco de teclado.
- **Marca:** "REMO SCOUT — piloto". Use o brasão só se for fornecido. Não copie nenhum elemento visual ou texto proprietário dos jogos.
- **Estados:** vazio, parcial e fonte indisponível sempre claros e com próximo passo. Nenhum botão central sem funcionar. Dados sintéticos só em ambiente de demonstração com selo.

## 9. Testes obrigatórios

- **Cálculo:** 4 gols em 900 min = 0,4/90; 0 min resulta em indisponível; percentual agregado vs. média de percentuais; ausência vs. zero; amostra e cobertura.
- **Minutos por súmula:** titular inteiro, substituído, quem entrou, expulso, prorrogação, minuto com acréscimo.
- **Parser:** PDF real da CBF e da FPF, PDF com layout alterado, nome com acento, ID CBF ausente.
- **Identidade:** homônimos, transferência entre clubes, reimportação, merge e split reversíveis.
- **Permissões:** acesso do leitor por URL, API e export.
- **Papéis:** papel novo sem código, várias funções e especialidades simultâneas, histórico depois de edição ou arquivamento, "não avaliado" vs. "padrão".
- **Fontes:** 401, 403, 429, timeout, credencial ausente e CPF presente no payload (descartado e fora do log).
- **Ponta a ponta:** o fluxo de aceite da fatia 1.
- **CI:** nunca chamar API paga.

## 10. Operação, custos e aprovações

- **`COSTS.md`:** Railway (web, worker, Postgres, bucket e egress, estimados pelo uso), API-Football Ultra US$ 29/mês, Sportmonks (só se aprovado depois do trial), Footlink (sob consulta) e LLM (desligado). Indique moeda, data, fonte, câmbio e impostos fora, e a estimativa de requisições por dia.
- **Pedidos de aprovação ao Gustavo,** cada um separado e com custo:
  1. ~~criar o projeto e os serviços no Railway~~ (aprovado e feito em 11/10/2026; veja a seção 2);
  2. assinar o API-Football Ultra;
  3. abrir o trial do Sportmonks;
  4. pedir a demo do Footlink;
  5. criar domínio e expor o sistema aos usuários.
- **Entregáveis:**
  - repositório com README e `.env.example`;
  - migrations e seeds (catálogo e sintéticos separados);
  - Dockerfile/`railway.json` e CI;
  - os documentos `SOURCES.md`, `COVERAGE.md`, `ARCHITECTURE.md`, `DATA_DICTIONARY.md`, `SCORING.md`, `SECURITY.md`, `RUNBOOK.md`, `COSTS.md` e `PILOT.md`;
  - matriz requisito → implementado / testado / pendente → evidência;
  - guia do analista.
- **Piloto (Etapa C, depois da Etapa A):** 4 semanas de uso. Medir:
  - tempo mediano por dossiê, comparado com a planilha;
  - uso semanal por usuário;
  - candidatos que avançam para vídeo, com denominador;
  - utilidade da shortlist segundo o Rafael.

  Meta: −30% no tempo mediano.

## 11. Como conduzir

1. **Diagnóstico curto.** Inspecione o ambiente e, se houver acesso, o Railway em modo de leitura, sem alterar projetos existentes. Crie `CHECKPOINT.md` com as decisões, as tarefas e o estado, e atualize o arquivo a cada sessão.
2. **Execute as fatias em ordem**, demonstrando cada uma com screenshots de desktop e de celular. Não pare para perguntar escolhas técnicas reversíveis.
3. **Pergunte só o que bloqueia.** Para decisão material: recomendação + até 3 opções com prós e contras.
4. **Ao fim de cada fatia, informe:**
   - o que funciona e como acessar;
   - o que foi realmente validado;
   - o que está pendente (credenciais, aprovações);
   - testes executados e limitações;
   - a próxima decisão.

## 12. Fontes para revalidar

- API-Football: https://www.api-football.com/pricing, https://www.api-football.com/coverage, https://www.api-football.com/documentation-v3
- Súmula FPF verificada: https://www.fpfpara.com.br/files/sumulas/Camet%C3%A1%20X%20Remo.pdf
- Estrutura das súmulas CBF (terceiro): https://github.com/BrazilianFootball/Modeling/blob/main/master_text/files/201-report_structure.tex
- Fontes CBF relatadas por terceiro (inclui o alerta de CPF): https://github.com/mpbarbosa/portal_brasileirao/blob/main/docs/data-sources.md
- Snapshot de flags API-Football 2025 (terceiro): https://github.com/ficosta/anymal-widgets/blob/main/docs/ids/leagues.json
- Sportmonks: https://www.sportmonks.com/football-api/coverage/, https://www.sportmonks.com/football-api/plans-pricing/
- Footlink: https://footlink.app/api-docs/
- Hudl × CBF (scouting A–D): https://www.hudl.com/blog/hudl-confedera%C3%A7%C3%A3o-brasileira-de-futebol-announce-multi-year-scouting-rights-agreement
- Sofascore (sem API): https://sofascore.helpscoutdocs.com/article/129-sports-data-api-availability
- Transfermarkt (termos): https://www.transfermarkt.com/intern/anb
- Railway: https://docs.railway.com/guides/laravel, https://docs.railway.com/cron-jobs, https://docs.railway.com/volumes/backups
- LGPD: https://www.planalto.gov.br/ccivil_03/_ato2015-2018/2018/lei/l13709.htm
