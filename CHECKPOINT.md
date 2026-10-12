# CHECKPOINT — REMO SCOUT

Atualize a cada sessão. Última atualização: 12/10/2026 noite (confiabilidade publicada, PRs #3–#14; convergência de identidades ativa).

## Decisões vigentes (ver docs/PROMPT-v2.md §2)
- Usuários: Gustavo (admin/piloto), Rafael (coordenador), 2 analistas (a contratar), Executivo de Futebol (leitor; financeiro por permissão).
- Escala: nível de competição A/B/C/D + "não observado", por papel. Desktop primeiro.
- Infra: Railway projeto `remo-scout` (web, worker, Postgres, bucket `remo-scout-anexos`). Sem domínio público até aprovação.
- Fontes autorizadas por Gustavo em 11/10/2026: API-Football, súmulas CBF/FPF, Cartola, Sportmonks (trial), Footlink (após demo), API Futebol (chave em `API_FUTEBOL_KEY`),
  e — decisão dele, ciente dos termos — fontes não oficiais: transfermarkt-api (felipeall), Sofascore (endpoints internos), ogol/spider.cloud, SoccersAPI,
  páginas públicas da CBF (atletas por campeonato; intermediários cadastrados). Regras: consulta sob demanda, cache, rate limit, kill switch por fonte,
  rótulo "fonte não oficial", **nunca gravar CPF**.
- Campos pedidos: faixa salarial, pedida, empresário com contato, telefone/WhatsApp/e-mail do atleta (visível só a perfis com edição).

## Estado
- [x] Fatia 1: atletas (perfil estilo estação de trabalho), empresários, necessidades + verificação de requisitos, relatórios de scout com avaliação independente,
      quadro de prospecção, catálogo 40+12 versionado (JSON import/export), importador CSV/XLSX com mapeamento/prévia/reversão, dossiê PDF, export shortlist,
      auditoria, usuários/perfis/MFA para admin.
- [x] Seeds: catálogo, competições, 165 clubes 2026 (A–D, Nordeste, Verde, Parazão), carga inicial de 343 atletas (79 prioritários).
- [x] 27 testes passando (`php artisan test`, Postgres local `remo_scout_test`).
- [x] Deploy Railway verificado em 11/10/2026: https://web-production-25a4a.up.railway.app — /up 200, login OK, MFA forçado para admin, worker (fila+scheduler) rodando, seeds aplicados (343 atletas, 165 clubes).
- [ ] Fatia 2: módulo de fontes (adapters + sync_runs), súmulas → minutos, identidade por ID CBF, estatísticas no perfil.
- [ ] Fatia 3: busca avançada/comparação/watchlist/alertas; Fatia 4: notícias manual, relatório semanal, restore testado.

## Próximos passos
1. Confirmar deploy e criar usuários do Rafael/analistas.
2. Fontes adicionais indicadas por Gustavo: páginas públicas CBF de atletas por campeonato (ID CBF; descartar CPF), registro público de intermediários CBF (intermediario.cbf.com.br) para o cadastro de empresários, API CBF de parceiros (requer credencial).
3. Fatia 2 começando por: API Futebol (calendário/escalações BR), CBF atletas por competição (ID CBF), Transfermarkt (perfil/valor sob demanda), súmulas.

## 12/10/2026 — fotos, escudos e alcance das fontes a partir do Railway
- Foto do atleta: upload próprio (bucket, disco `media`) > foto da fonte externa (TM/Sofascore, hotlink). Escudo: upload > API Futebol (`scout:sync-crests`, semanal + predeploy) > TM. Produção: 123 escudos.
- Aliases de clube por provedor no ClubsSeeder (Bragantino→Red Bull Bragantino, Portuguesa→Portuguesa-SP, Abecat Ouvidorense→ABECAT).
- Probe a partir do Railway (us-west2): OK api_futebol, transfermarkt, sumula_fpf. FALHA: cbf_atletas, cbf_intermediarios, sumula_cbf (conexão — provável bloqueio de IP fora do Brasil) e sofascore (403, IP de datacenter).
- Decisão pendente (Gustavo): rota de saída com IP brasileiro para CBF/Sofascore (coletor no Mac dele via app desktop, ou proxy/VPS BR — custo). Até lá, adapters CBF ficam parados.

## 12/10/2026 — coletor local CBF (opção 1 aprovada por Gustavo; endpoint com token aprovado)
- Mac Mini (IP BR) roda `collector/remo_scout_coletor.py` via launchd (br.com.remo.scout.coletor, diário 06:20). Token em ~/.config/remo-scout/coletor.env (600) no Mini e COLLECTOR_TOKEN no serviço web do Railway.
- API `/api/coletor/*` (token, throttle 240/min): plano, inicio, lote (lista|detalhe|intermediarios), pendentes, fim. Kill switch = desligar a fonte em Fontes de dados (HTTP 423).
- Endpoints CBF: lista `www.cbf.com.br/api/cbf/atletas/campeonato/{competitionId}/pagina/{n}` (25/página, sem CPF); detalhe `/api/cbf/atletas/{id}` (nascimento, foto base64 oficial, clube atual, totais da temporada). competitionId descoberto pelo slug na página pública (2026: A 1260611, B 1260612, C 1260613, D 1260635, Copa do Brasil 1260615). Intermediários: HTML paginado `?page=N` (nome, tipo, publicação).
- Lista branca de campos no servidor + scrub no coletor. Registro `cbf_athletes` não cria atleta no scout; vínculo só inequívoco (mesmo ID CBF ou nome ≥92 no mesmo clube). Tela "Inscritos CBF" com "Cadastrar no scout".
- Foto oficial CBF vai para `players/cbf/` no bucket e nunca sobrescreve upload do departamento. Totais CBF entram como provedor `cbf` (sem minutos).
- 1ª coleta completa (12/10/2026, ~43 min): 4.483 inscrições (A 835, B 814, C 589, D 695, Copa do Brasil 1.550) → 2.928 atletas únicos no registro; 119 atletas do scout vinculados automaticamente e com detalhe (foto oficial/nascimento/totais); 2.168 intermediários (44 páginas). CBF devolve 504 esporádicos: o coletor tenta 3x e, desde a 1.0.1, pula a página e registra.
- 12/10/2026: radar ampliado (aprovado): + Copa do Nordeste (1260624), Copa Verde (`copa-verde/masculino`, 1260621), Copa Sul-Sudeste (1260625), Brasileiro Sub-20 (1260620), Sub-20 B (1260626), Copa do Brasil Sub-20 (1260649). Fora: Sub-17/15/13 (menores) e feminino. Aspirantes e Nordeste eliminatórias/pré-copa sem edição 2026 na CBF.
- 12/10/2026: sistema renomeado para **CIFUT Remo** (repo segue remo-scout). Escudo do Remo (SVG da API Futebol, hospedado em public/images/remo-escudo.svg) na barra superior, login, favicon; PDFs com o novo nome. APP_NAME não alterado (mudaria o cookie de sessão).
- Coletor 1.0.2: ritmo mínimo 2,5 s entre requisições (CBF passou a responder 429 a 1,5 s); 429 respeita Retry-After (ou 60 s) e desacelera a execução. Rodada diária estimada ~90 min.

## 12/10/2026 — auditoria validada e Bloco 1 do refinamento (branch `refino`, não publicado)
- Auditoria (docs/auditoria/) validada: F01–F22 procedem; novos F23 (workflows do esqueleto) e F24 (worker morria a cada 1 h — hotfix 00d6fe9 publicado com autorização).
- Decisões do Q&A em docs/auditoria/DECISOES-REFINAMENTO.md (deploy só no fim; fases com posse/sem posse/bola parada no Bloco 1; IA de notícias = Claude API; Sofascore só via coletor local; restauração no Mac Mini).
- Bloco 1 (F01–F08, F10, F23) concluído na branch `refino` com revisão independente; entrega e plano de deploy/rollback em docs/auditoria/BLOCO-1-ENTREGA.md. CI próprio verde (PHP 8.3 + PostgreSQL 16).
- Próximo: Bloco 2 (F09, F12–F17, F20).

## 12/10/2026 — Bloco 2 do refinamento (branch `refino`, não publicado)
- F09, F12–F17 e F20 concluídos com revisão independente: 1 grave, 7 médios e 7 baixos, todos corrigidos. Detalhes, migrações e plano de deploy em docs/auditoria/BLOCO-2-ENTREGA.md; matriz de fontes em docs/auditoria/MATRIZ-INTEGRACOES.md.
- Fontes novas:
  - **API-Football Pro**, assinado pelo Gustavo e válido até 12/11/2026. Chave no Railway web+worker. Estatística individual de Série A/B; escalação de Série C/Nordeste; nascimento para vínculo automático.
  - **Dados Futebol Pro:** elenco atual.
  - **football-data.co.uk:** força dos clubes da Série A.
  - **BID CBF:** consulta assistida, porque há CAPTCHA.
  - **Sofascore:** sob demanda via coletor do Mac (tarefas a cada 15 min).
  - Sportmonks não autorizada (Free sem Brasil). kariofreire descartado.
- Coletor 1.1.0 (manifesto, idempotência, retomada, tarefas): atualizar o Mac Mini só no deploy.
- Pendências do Gustavo:
  - autorizar o deploy dos Blocos 1+2;
  - trocar as chaves coladas no chat;
  - decidir a renovação da API-Football até 12/11.
- Pendência do Rafael: conferir a cobertura da API Futebol e da CBF (botão na tela de fontes).
- Próximo: Bloco 3 (fluxo de mercado e layout) e Bloco 4 (notícias via Claude API, operação, ensaio de restauração no Mac Mini, piloto).

## 12/10/2026 — Bloco 3 do refinamento (fluxo de mercado e layout)
- Blocos 1+2 publicados (PR #1, `b7de4b3`). Sincronizações das 3 fontes conferidas pelos logs: sem erro.
- Entregue: F11 (pesquisa da necessidade + orçamento em 4 estados), comparador fixo, watchlist com alertas no sino, F22 (catálogo A–D por fase + calibração),
  preferências de tabela por usuário, perfil em abas, Ctrl+K com atalhos e planejador de elenco (com/sem posse, carências, cenários).
- Revisão independente: 4 achados (2 médios), todos corrigidos. Detalhes em docs/auditoria/BLOCO-3-ENTREGA.md.
- Próximo: Bloco 4 — notícias via Claude API (aguarda chave e teto do Gustavo), F19 operação, ensaio de restauração no Mac Mini, F21, piloto.

## 12/10/2026 — prompt mestre: confiabilidade (branch `confiabilidade`, não publicada)
- Bloco 3 publicado antes do prompt mestre (merge PR #2 `5a90628`, autorizado). A partir do prompt mestre: nada em produção sem autorização específica.
- Clube como código: causa no Transfermarkt; clube canônico × ID da fonte × rótulo; backfill `scout:stats-clubs` com prévia/trilha/reversão.
- Estatísticas: 9 estados por atleta na tela Cobertura de dados; perfil não esconde linhas; `scout:enrich --scope=cobertura`.
- Fotos: foto da API-Football com vínculo confiável; `scout:photos` (backfill, verificação, relatório); iniciais como placeholder.
- F03: especialidade obrigatória exige limiar. F01: teste com sessão real e desafio MFA.
- Pro Soccer (portal do próprio Remo): coletor de descoberta no Mac Mini; aguarda senha no Chaves do Mac Mini. Fora: documentos, saúde, financeiro, cláusulas/valores.
- Detalhes: docs/auditoria/CONFIABILIDADE-ENTREGA.md. Próximo: súmulas CBF, Football-Data (licença), comparativo de fontes, Pro Soccer após a descoberta.

## 12/10/2026 (noite) — produção: confiabilidade publicada e enriquecimento (PRs #3–#14, merges autorizados por Gustavo)
- Publicado: clube canônico nas estatísticas (#3/#4), Cobertura com correções em lote reversíveis (#5/#6), fotos pelo próprio CIFUT (#7–#9), contagem real de fotos e enriquecimento do scout inteiro (#10/#11), convergência de identidades (#12–#14).
- Números (343 atletas do scout): estatística disponível 14, parcial 130, desatualizado 1, identidade pendente 131, aguardando vínculo 46, não coletado 21.
  Foto real 145/343 (todos os links do scout verificados; silhuetas eram de cadastros provisórios); sem fonte de foto 181, fonte sem foto 17.
- Lotes: stats_club (702 linhas), photo (1.310), photo_check (7 lotes, ~1.320 URLs) — todos com "Desfazer" na Cobertura.
- Gargalo confirmado: cadastros sem nascimento (só apelido). Vínculo automático exige nome + nascimento (F14); 37 de 40 da 1ª rodada de enriquecimento foram para a fila. Fila de identidades: 634 itens pendentes.
- Decisão do Gustavo (opção B, 12/10/2026): ligação automática quando duas fontes concordam — único candidato do Transfermarkt no clube do cadastro + único registro CBF do mesmo clube com o MESMO nascimento e nome compatível (`scout:identities-converge`, prévia/aplicar/desfazer na Cobertura). 1ª prévia: 0 ligar (18 com Transfermarkt único no clube, mas registro CBF sem nascimento). Correção #14: a fila de detalhe do coletor CBF passa a incluir registros livres do mesmo clube → nascimentos chegam na coleta das 06:20.
- Próximo: após a coleta de 13/10 06:20, rodar a prévia de convergência, aplicar e enriquecer os ligados; o restante segue para revisão humana (opção A), priorizando os 65 prioritários.
- Pendências de decisão do Gustavo: quem revisa a fila de identidades; MFA para acesso financeiro; histórico de transferências (Transfermarkt `/transfers`) ainda não coletado; Pro Soccer aguarda senha no Mac Mini.

