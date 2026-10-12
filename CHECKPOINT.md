# CHECKPOINT — REMO SCOUT

Atualize a cada sessão. Última atualização: 11/10/2026 (sessão de construção inicial).

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
