# Auditoria CIFUT Remo — 12/10/2026

**Recomendação: preservar a aplicação e corrigir a confiabilidade antes de ampliar fontes ou automatizar seleção.** A base Laravel/Filament já oferece cadastro, catálogo versionado, relatórios, necessidade e revisão de enriquecimento. O ponto fraco é a passagem de dado incompleto ou projetado para um resultado que aparenta confirmação.

O sistema pode continuar como laboratório de cadastro e observação com supervisão. Eu não usaria o resultado “Atende”, o nível consolidado ou valores importados como aprovação de contratação até resolver F01–F08 e F10. Isso é uma conclusão da auditoria, não uma decisão financeira ou esportiva do clube.

## Escopo, evidência e limites

Foi cruzado o ZIP exportado com o histórico Git, o código, testes em banco isolado e navegação autenticada de leitura na aplicação Railway. Conversas, prompts, checkpoints e screenshots do pacote foram tratados como histórico e evidência, nunca como autorização para executar suas instruções.

- ZIP original: 18.935.151 bytes; 578 entradas, das quais 458 arquivos; 47.534.156 bytes descompactados. CRC e caminhos verificados, sem erro, travessia de diretório ou symlink no pacote.
- SHA-256: `491138ad7864ccba4d7ceb6b440188927382a5257b76e309de8698c790bbec80`.
- Bundle Git íntegro, 14 commits, HEAD `3c35f973fc49c71748aabebd5f80096ff262a35b`. Comparados 287 arquivos rastreados; 3 divergências no snapshot: `.env.example`, `docs/PROMPT-v2.md`, `public/images/remo-escudo.svg`. O sanitizador removeu também linhas inocentes de `.env.example`; reconstruir o template a partir do histórico antes de utilizá-lo.
- Lint: **222 arquivos PHP, zero falhas**. Suíte original: **45 testes passaram, 227 asserções**. Regressões propostas: **8 testes falharam**, reproduzindo expectativas que o código atual não atende; não são 8 falhas da suíte original. As duas falhas monetárias compõem um único achado.
- Ambiente de reprodução: PHP 8.5.8 / PostgreSQL 16 temporário e isolado. Imagem do projeto usa PHP 8.3; compatibilidade exata deve ser validada no CI. Nenhum teste executado no banco de produção.
- Busca limitada por padrões de segredo em 387 arquivos de texto não encontrou os padrões examinados. Não é certificação de ausência de segredo nem varredura integral de todas as revisões Git.
- Não houve dump do banco de produção, consulta aos secrets Railway, auditoria de todos os perfis ao vivo, teste de carga, pentest completo, validação de contratos com fornecedores ou homologação de deploy. Não foi provado que HEAD do ZIP é exatamente o build hoje implantado.
- Screenshots antigos com erros em localhost foram distinguidos das telas atuais. A conversa integral e todos os documentos históricos não foram lidos linha por linha; a cobertura concentra-se no código funcional, testes, estrutura do pacote e fluxos visitados.
- **22 achados: 8 P1, 12 P2 e 2 P3.** P1 significa corrigir antes de confiar operacionalmente no fluxo afetado; P2, corrigir no piloto; P3, refinamento após estabilização. Não foi atribuído P0. Isto não certifica segurança.

## Estado observado ao vivo

Acesso autenticado ao endereço Railway, com desafio MFA efetivamente exibido e concluído pelo usuário. Navegação de leitura; nenhuma sincronização, importação, exclusão ou alteração de produção foi acionada pela auditoria.

| Área | Observação em 12/10/2026 | Implicação |
|---|---|---|
| Atletas | 343 resultados | Coincide com a quantidade de linhas da base inicial do pacote; isso não comprova igualdade integral dos registros. |
| Registro CBF | 3.270 resultados | Base maior que checkpoint histórico; contagem atual não mede completude das competições. |
| Central de mercado | 0 necessidades abertas, 0 candidatos, 0 shortlist, 0 relatórios nos últimos 7 dias, 0 tarefas | Fluxo de decisão ainda não validado por operação real nessas telas. |
| Relatórios / partidas | 0 registros nas duas telas | Não há demonstração ao vivo de análise com amostra de partidas. |
| Fontes | 12 cadastradas | Cadastro e teste de saúde não equivalem a integração funcional. |
| Sofascore | Bloqueada; sem execução concluída exibida | Não tratar como integração entregue ou dependência essencial. |
| API Futebol | Saúde OK, 57/2.000 no contador diário, 0 execuções | Resposta a probe não comprova ingestão. É diferente do fornecedor API-Football. |
| CBF atletas | Instável; 3 execuções; última coleta mostrada cerca de 6 horas antes | Investigar cobertura/erros e distinguir coleta local de disponibilidade direta do servidor. |
| Transfermarkt não oficial | Saúde OK; 1 execução; atividade recente | Não demonstra licença, estabilidade futura ou veracidade de contratos e valores. |

Perfil de Pedro Raul visitado: clube Corinthians, CBF ID 502361, temporada 2026 com 33 jogos/2 gols, minutos indeterminados e cálculo por 90 não disponível; sem papel avaliado, relatório ou vídeo nessa tela. Estes números são **o que a aplicação apresentou**, não uma certificação externa de desempenho. O contrato exibido em captura histórica não foi presumido como contrato atual.

## O que merece ser preservado

- Monólito Laravel/Filament/PostgreSQL é operável por você e adequado ao piloto. Não há evidência que justifique reescrever em outra stack.
- Catálogo com criação e versionamento de papéis; 40 papéis, 8 grupos e 12 especialidades. Base inicial: 343 atletas, 79 prioritários, 165 clubes; não foram encontrados pares nome/clube repetidos na base inicial e as 343 linhas não tinham data de nascimento.
- Separação de estatísticas por fonte, revisão de divergências e registro de coleta, em vez de mesclar silenciosamente todos os fornecedores.
- Políticas de relatórios e segregação de campos financeiros nas exportações; proteção de fórmulas em CSV; mídia privada com URL assinada; coletor remove CPF e contatos pessoais do payload. Esses controles existem e testes originais passaram, sem inferir cobertura perfeita de todos os cenários.

## Achados priorizados

### F01 · P1 · MFA nas exportações

**Base:** Teste reproduzido. **Referência no snapshot:** `routes/web.php:7`.

**Evidência:** Um administrador autenticado sem MFA cadastrado recebe HTTP 200 no dossiê. A rota externa ao painel aplica auth, mas não exige o cadastramento obrigatório do segundo fator.

**Impacto/limite:** Exportação acessível a uma conta que ainda não completou a exigência de MFA. O desafio MFA do usuário real funcionou; não foi demonstrado contorno do desafio de uma conta já cadastrada.

**Correção:** Aplicar uma política comum de MFA às rotas do painel e às exportações, preservando as autorizações de cada recurso.

**Aceite:** Conta sem MFA deve seguir para cadastramento; conta com MFA deve completar o desafio; conta autorizada após MFA exporta. Testar também perfis sem acesso.

### F02 · P1 · Importação monetária altera o valor

**Base:** Teste reproduzido. **Referência no snapshot:** `app/Services/Import/PlayerImporter.php:399`.

**Evidência:** parseMoney("R$ 25.000") retorna 25,0; parseMoney("-1000") retorna 1000,0.

**Impacto/limite:** Valor salarial ou de mercado pode ficar mil vezes menor; um negativo torna-se positivo silenciosamente.

**Correção:** Definir moeda e locale na importação, rejeitar valores ambíguos e negativos, mostrar original e interpretação na prévia; armazenar dinheiro em decimal ou centavos.

**Aceite:** R$ 25.000 = 25000 no locale pt-BR; R$ 25.000,50 = 25000,50; negativo recusado. Cobrir locale estrangeiro explicitamente e ida/volta CSV.

### F03 · P1 · Potencial atende requisito de nível observado

**Base:** Teste reproduzido. **Referência no snapshot:** `app/Services/RequirementCheck.php:50`.

**Evidência:** Um atleta com somente avaliação de potencial A foi marcado Atende em necessidade com nível alvo B. A consulta usa a última avaliação do papel sem distinguir kind.

**Impacto/limite:** Shortlist pode tratar projeção futura como capacidade presente.

**Correção:** Consultar nível observado para o papel, fase e contexto da necessidade; manter potencial em campo separado e sinalizar ausência/validade.

**Aceite:** Somente potencial => Pendente; observado abaixo => Não atende; observado adequado e válido => Atende; fases diferentes não se substituem.

### F04 · P1 · Requisito obrigatório sem confirmação aparece atendido

**Base:** Teste reproduzido. **Referência no snapshot:** `app/Services/RequirementCheck.php:73`.

**Evidência:** Necessidade com requisito manual obrigatório não verificado retorna Atende (+ itens manuais).

**Impacto/limite:** A interface pode autorizar avanço apesar de disponibilidade, condição contratual ou outro requisito ainda desconhecido.

**Correção:** Criar estados por requisito: atende, não atende, pendente e não aplicável; exigir evidência, responsável e data para confirmar o obrigatório.

**Aceite:** Todo obrigatório desconhecido mantém o resultado geral Pendente. Uma falha obrigatória produz Não atende; só todos confirmados produzem Atende.

### F05 · P1 · Reverter importação não restaura atleta existente

**Base:** Teste reproduzido. **Referência no snapshot:** `app/Services/Import/PlayerImporter.php:308`.

**Evidência:** Atleta existente com altura nula recebeu 188 cm; após reverter lote a altura continuou 188.

**Impacto/limite:** A ação de reversão promete mais do que executa e pode conservar alterações erradas.

**Correção:** Registrar alterações por entidade/campo, antes/depois e versão; restaurar somente quando não houver edição posterior conflitante. Associar todos os registros gerados ao lote.

**Aceite:** Teste restaura campos preexistentes e derivados; edição posterior gera conflito explícito; lote pode ser revertido uma única vez com trilha.

### F06 · P1 · Timeout de job maior que retry_after

**Base:** Inspeção de código. **Referência no snapshot:** `app/Jobs/RunArtisanSync.php:16; config/queue.php:43`.

**Evidência:** Timeout do job = 3500 s; retry_after padrão da fila de banco = 90 s.

**Impacto/limite:** Outro worker pode reservar um job ainda em execução. O valor efetivo de produção não foi inspecionado; duplicação real não foi demonstrada.

**Correção:** Dividir sincronização em jobs limitados por página/atleta; timeout menor que retry_after efetivo; exclusão mútua por fonte/lote e idempotência.

**Aceite:** Verificar configuração efetiva sem imprimir segredos; simular dois workers, retry e encerramento. Nenhum item aplicado duas vezes e nenhum job preso indefinidamente.

### F07 · P1 · CI não acompanha branch e banco do projeto

**Base:** Inspeção de código. **Referência no snapshot:** `.github/workflows/tests.yml:5`.

**Evidência:** Workflow de push lista master e *.x; bundle contém main. Testes usam PostgreSQL, mas o workflow não provisiona serviço e configuração correspondentes.

**Impacto/limite:** Push em main pode ficar sem validação e a execução CI não reproduz o ambiente de testes. Não foram consultadas execuções reais do GitHub.

**Correção:** Habilitar main, provisionar PostgreSQL/pdo_pgsql e banco isolado; executar testes originais e regressões no PHP compatível com a imagem de produção.

**Aceite:** Push/PR em main executa e passa a suíte com PostgreSQL, sem depender de banco externo. Validar PHP 8.3 além do ambiente local usado na auditoria.

### F08 · P1 · Partida parcial deixa de ser atualizada

**Base:** Inspeção de código. **Referência no snapshot:** `app/Sources/ApiFutebol.php:94`.

**Evidência:** A sincronização exclui qualquer partida com fetched_at preenchido antes de buscar o detalhe novamente.

**Impacto/limite:** Uma resposta inicial parcial ou posteriormente corrigida pode permanecer congelada. No ambiente visitado havia zero partidas; efeito operacional ainda não observado.

**Correção:** Reconsultar partidas parciais e recentes por TTL/status, comparar hash depois da consulta e reconciliar eventos removidos/corrigidos.

**Aceite:** Primeira resposta parcial seguida de completa atualiza minutos/eventos; terceira resposta corrigida remove evento antigo sem duplicar atleta ou partida.

### F09 · P2 · Controle de fonte e retry insuficientes

**Base:** Inspeção de código. **Referência no snapshot:** `app/Sources/SourceClient.php:52`.

**Evidência:** Quota é consultada antes do lock; TTL de 30 s é menor que o tempo possível de retries/timeouts. Não há cache de respostas no cliente nem circuit breaker efetivo.

**Impacto/limite:** Concorrência, repetição e bloqueio de fonte podem consumir quota e produzir estados inconsistentes.

**Correção:** Reserva atômica de quota; cache por fonte/recurso/parâmetros e TTL; backoff com jitter em jobs, Retry-After respeitado e circuito com estado/retomada.

**Aceite:** Testar dois workers, 429, 403, timeout e resposta válida; quota consistente, sem ultrapassar limites pela concorrência e com diagnóstico de freshness.

### F10 · P2 · Nível atual pode vir de relatório de potencial

**Base:** Teste reproduzido. **Referência no snapshot:** `app/Support/PlayerInsight.php:24`.

**Evidência:** Sem avaliação observada de papel, um relatório submetido do tipo potencial com nível A aparece como nível atual A.

**Impacto/limite:** Comparação e leitura do perfil confundem projeção com observação. O uso do melhor nível histórico e contagem agregada também perde contexto.

**Correção:** Separar observado/potencial e contexto/validade; consolidar por papel/fase, com autor e evidência. Revisar rótulo Completo baseado em soma simples de registros.

**Aceite:** Potencial isolado deixa nível atual desconhecido. Evidência vencida é identificada; consenso não se torna mais forte pela repetição do mesmo autor.

### F11 · P2 · Busca da necessidade perde contexto e orçamento

**Base:** Refinamento de produto/código. **Referência no snapshot:** `app/Filament/Resources/Players/PlayerResource.php:344; app/Filament/Resources/RecruitmentNeeds/Pages/ViewRecruitmentNeed.php:25`.

**Evidência:** A URL da busca reaproveita posição, idade e pé, mas omite papel/especialidade/nível. Filtros de papel e nível podem considerar avaliações diferentes. Faixa salarial usa o menor extremo.

**Impacto/limite:** Resultados exigem retrabalho e podem aparentar caber no teto com salário máximo superior. Rótulo atual de nível em qualquer papel reduz a ambiguidade, mas não resolve busca orientada à necessidade.

**Correção:** Salvar pesquisa contextual; tratar desconhecidos como pendentes; distinguir faixa que intersecta orçamento de faixa inteiramente dentro; permitir ambidestro no pé compatível.

**Aceite:** Necessidade e busca exibem os mesmos critérios e estados; exportação explica inclusões; atleta com faixa acima do teto não aparece como orçamento confirmado.

### F12 · P2 · Estatística precisa de cobertura e contexto

**Base:** Inspeção de código/interface. **Referência no snapshot:** `resources/views/scout/player-profile.blade.php:9`.

**Evidência:** Temporadas recentes são fixas em 2026/2025 etc.; lista limita seis linhas por fonte. Perfil atual de Pedro Raul mostrou fonte cbf, jogos/gols e minutos indeterminados.

**Impacto/limite:** Usuário pode comparar fontes, competições e amostras distintas; truncamento e desatualização não ficam claros.

**Correção:** Ano dinâmico, filtros de competição/clube/temporada, rótulo de fonte legível, data de coleta, cobertura, minutos e amostra; expandir linhas e explicar indisponibilidade.

**Aceite:** Não transformar minutos desconhecidos em zero; por 90 depende de denominador válido. Comparação usa contexto equivalente e informa amostra pequena/ausente.

### F13 · P2 · Qualidade de eventos não comprova minutos completos

**Base:** Inspeção de código. **Referência no snapshot:** `app/Sources/ApiFutebol.php:144`.

**Evidência:** Heurística de completude considera substituições, soma de gols e atletas nulos; não garante escalação integral. Tratamento de duração usa 90 minutos.

**Impacto/limite:** Dados incompletos podem produzir zeros aparentes e minutos errados, especialmente em prorrogação.

**Correção:** Validar titulares, relógio, substituições, acréscimos/prorrogação; distinguir evento ausente de zero confirmado e guardar qualidade por campo.

**Aceite:** Fixtures com escalação ausente, evento sem jogador, correção e 120 minutos têm estados e totais coerentes; agregados removem registros obsoletos.

### F14 · P2 · Vínculo automático de identidade pouco conservador

**Base:** Inspeção de código. **Referência no snapshot:** `app/Services/PlayerEnrichment.php:81; app/Services/Collector/CbfIngest.php`.

**Evidência:** Alguns caminhos aceitam candidato único por similaridade/nome e clube sem exigir data de nascimento ou segundo identificador.

**Impacto/limite:** Homônimos e mudança de clube podem misturar carreiras. Não foi medida taxa de falsos vínculos nem demonstrado atleta real vinculado incorretamente.

**Correção:** Priorizar ID oficial já confirmado; nome + nascimento ou outra evidência independente para autoaceite; divergência vai para revisão e vínculo reversível.

**Aceite:** Conjunto de homônimos/acentos/apelidos/transferências não produz falso autoaceite; painel exibe evidência e confiança por vínculo.

### F15 · P2 · Cidadania europeia vira passaporte confirmado

**Base:** Inspeção de código. **Referência no snapshot:** `app/Services/PlayerEnrichment.php:164`.

**Evidência:** Cidadania de país da UE obtida do Transfermarkt pode definir eu_passport=true.

**Impacto/limite:** Um indicador documental é preenchido por inferência de cidadania.

**Correção:** Separar cidadania declarada por fonte de passaporte documentalmente confirmado e elegibilidade regulatória verificada.

**Aceite:** Cidadania importada não confirma documento; campos preservam fonte/data/responsável e estado desconhecido.

### F16 · P2 · Revisão aceita sem aplicar clube em UUID

**Base:** Teste de contrato do serviço. **Referência no snapshot:** `app/Services/PlayerEnrichment.php:299`.

**Evidência:** propose(current_club_id, UUID, cbf) seguido de accept marca a revisão aceita, mas não muda clube porque o parser espera texto com (#UUID).

**Impacto/limite:** Aceite pode divergir da alteração efetiva. Teste é de contrato genérico do serviço; o caminho atual do coletor não demonstrou esta entrada na interface.

**Correção:** Guardar old_value/new_value tipados, ID separado de label, controle de versão e transação; só marcar aceito depois de aplicação validada.

**Aceite:** Aceite de UUID muda FK; label não controla persistência; valor atual divergente abre conflito e nenhuma revisão sem efeito é marcada aceita.

### F17 · P2 · Coletor local sem reconciliação completa

**Base:** Inspeção de código. **Referência no snapshot:** `collector/remo_scout_coletor.py`.

**Evidência:** Falhas de página/competição podem deixar execução incompleta; reenvio após resposta perdida não tem chave de idempotência por lote. Quota exibida e chamadas upstream medem coisas diferentes.

**Impacto/limite:** Cobertura aparenta completa com páginas ausentes e contadores podem somar reenvios. Não significa que todas as entidades sejam duplicadas, pois há upserts.

**Correção:** Manifesto esperado/recebido por competição/página, checkpoints retomáveis, idempotency key, heartbeat e contadores separados de coleta/ingestão.

**Aceite:** Interrupção e retomada não perdem nem duplicam itens; conclusão parcial é explícita; Mac desligado gera estado atrasado e não saudável.

### F18 · P2 · Notícias ainda são pesquisa externa manual

**Base:** Lacuna de produto. **Referência no snapshot:** `resources/views/scout/player-profile.blade.php; ações Pesquisar fora`.

**Evidência:** Há abertura de busca externa; não foi encontrado fluxo completo de dossiê de notícias com síntese, fontes e revisão.

**Impacto/limite:** O requisito original de resumo profissional/pessoal permanece parcial. Notícias não sustentam diagnóstico psicológico ou nota de personalidade automática.

**Correção:** Criar dossiê de contexto profissional com identidade confirmada, deduplicação, título/URL/data, fatos versus alegações, citação por frase e revisão humana.

**Aceite:** Botão pesquisa, apresenta fontes e gera rascunho rastreável; sem fontes informa insuficiência. Homônimo, notícia antiga e alegação não viram fato; aprovação humana antes de consolidar.

### F19 · P2 · Operação e recuperação precisam de evidência

**Base:** Inspeção/limite operacional. **Referência no snapshot:** `scripts/start.sh; configuração Railway não inspecionada`.

**Evidência:** Script inicia scheduler em background e worker com max-time=3600. Exportação não comprova política efetiva de reinício, backup restaurável, logs e alertas de produção.

**Impacto/limite:** Saúde HTTP não comprova fila/scheduler ativos ou recuperação de dados. Falha real de reinício não foi observada.

**Correção:** Processos supervisionados ou serviços separados; heartbeat, alertas de jobs falhos/atrasados, backup de banco e mídias e ensaio de restauração.

**Aceite:** Matar worker/scheduler gera reinício/alerta; restaurar backup em ambiente isolado mede RPO/RTO. Registrar evidência sem expor secrets.

### F20 · P2 · Cadastro de fontes não equivale a integração pronta

**Base:** Observação ao vivo + pesquisa. **Referência no snapshot:** `/fontes, observado em 12/10/2026`.

**Evidência:** 12 fontes cadastradas; Sofascore Bloqueada; API Futebol com saúde OK, mas 0 execuções; API-Football, Sportmonks, SoccersAPI e ogol sem teste/execução. Partidas: 0.

**Impacto/limite:** Não há prova de cobertura estatística integrada suficiente para seleção. API Futebol brasileira e API-Football são fornecedores diferentes.

**Correção:** Matriz por fonte: acesso, licença/termos, competições, campos, quota, coleta bem-sucedida, freshness e teste de cobertura. Selecionar uma fonte autorizada que cubra o alvo do Remo.

**Aceite:** Somente rotular operacional após ingestão ponta a ponta e amostra reconciliada. Sofascore não é dependência crítica enquanto bloqueada e sem acesso autorizado comprovado.

### F21 · P3 · Carregamento do perfil e busca devem ser medidos

**Base:** Refinamento/inspeção. **Referência no snapshot:** `resources/views/scout/player-profile.blade.php:13`.

**Evidência:** Perfil carrega aparições para depois ordenar/limitar; listagem agrega relações. Não foi feito teste de carga.

**Impacto/limite:** Custo tende a crescer com partidas, relatórios e base CBF. Não há evidência de lentidão atual ou necessidade de troca de stack.

**Correção:** Limitar/ordenar no SQL, medir queries e páginas; índices orientados a consultas reais, pg_trgm somente se busca justificar; observabilidade primeiro.

**Aceite:** Perfil e busca dentro de meta acordada com volume representativo; medir p95/queries antes/depois e evitar N+1.

### F22 · P3 · Catálogo precisa de calibração e planejador

**Base:** Refinamento de produto. **Referência no snapshot:** `database/seeders/CatalogSeeder.php; catálogo versionado`.

**Evidência:** 40 papéis em 8 grupos e 12 especialidades; infraestrutura de criação/versionamento existe. Critérios gerais por posição e status proposta não comprovam calibração operacional.

**Impacto/limite:** Uma taxonomia extensa pode aparentar precisão sem avaliações comparáveis. Planejador tático completo ainda não foi demonstrado.

**Correção:** Aprovar 2–3 perfis prioritários, critérios observáveis e exemplos A–D; separar posse/sem posse e bola parada; depois construir planejador de elenco e cenários.

**Aceite:** Analistas avaliam mesmas partidas e discutem divergências; papel, fase e contexto explícitos; nenhum nível global substitui todos os papéis.

## Ferramentas e fluxo que eu implementaria

**1. Comparador orientado à necessidade.** Comparar 3–5 candidatos no mesmo papel/fase, competição e janela temporal. Exibir observado e potencial em colunas distintas, minutos/amostra, evidências, requisitos pendentes, disponibilidade e faixa financeira com acesso restrito. Percentis somente com população definida e métrica equivalente; ausente fica desconhecido, sem nota inventada. Permitir ponderações versionadas e explicação da pontuação, sem esconder vetos e pendências num score único.

**2. Watchlist e alertas úteis.** Lista salva por analista/necessidade, responsável, próxima revisão e motivo de inclusão. Alertar em mudança confirmada de clube/contrato, atualização de avaliação ou nova notícia relevante; deduplicar e não notificar repetição. Arquivar razões de descarte e medir retorno dos alertas antes de automatizar tudo.

**3. Planejador de elenco com a alma do FM.** Barra lateral compacta, tabela densa com colunas configuráveis, filtros salvos, paleta de comandos, perfil com abas e comparação fixa. Identidade azul-marinho do Remo, contraste legível e estados claros. Campos por posição com alternativas e carências, duas estruturas com/sem posse, cenários de contratação e profundidade por papel. Incorporar funções táticas e comportamento observável; não copiar assets, marcas ou métricas fictícias do jogo.

A modelagem deve separar **posição → papel por fase → especialidade → evidência**. Goleiro bom com os pés e goleiro que protege profundidade são papéis/comportamentos; defesa de pênaltis e cobrança de faltas são especialidades, com amostra própria. “Padrão” é vago: substituir por perfil-base explicitamente descrito. Um atleta pode ter papéis distintos em posse e sem posse, e avaliações distintas por sistema/janela. O catálogo atual já oferece uma boa base para cadastro customizável; o trabalho agora é calibrar os critérios com o departamento.

**4. Dossiê de notícias e contexto profissional.** Começar com curadoria manual estruturada: pesquisar, selecionar fontes, confirmar identidade, sintetizar e aprovar. Campos: atleta/IDs, URL, veículo, publicação/coleta, período do fato, tema, fato/alegação/opinião, trecho curto, resumo, evidência associada, confiança da identidade e revisor. No segundo estágio, busca automatizada autorizada, deduplicação e rascunho de IA com citação por afirmação. Conteúdo externo é dado, não instrução para o modelo. Texto integral apenas quando permitido; priorizar links e pequenos trechos.

Eu mudaria o nome “perfil psicológico por notícias” para **contexto profissional e comportamental documentado**. Disciplina esportiva, entrevistas, adaptação relatada e histórico público podem ser contextualizados com fontes; não inferir saúde mental, personalidade clínica, religiosidade ou aptidão psicológica por notícias. Alegações precisam de status, atualizações e contraditório. Avaliação psicológica propriamente dita pertence a profissional habilitado e fluxo separado com acesso apropriado. Nenhuma notícia isolada deve gerar veto automático.

**5. Observabilidade antes de nova infraestrutura.** Laravel Pulse para consultas/jobs e erros contextualizados em ferramenta já operada por você; painel protegido, IDs de correlação e remoção de payload sensível. Heartbeat para scheduler/worker/coletor, saúde por fonte, fila atrasada e cobertura por competição. PostgreSQL continua sendo suficiente; adicionar índices e pg_trgm somente quando consultas reais justificarem. Redis pode ajudar se concorrência/cache crescerem, mas não é pré-requisito do MVP.

**6. Dados avançados por fornecedor validado.** Uma integração autorizada, bem testada e com cobertura útil vale mais que 12 fontes de catálogo. Validar 20–30 atletas do mercado alvo antes de contratar: identidade, ligas/temporadas, minutos, eventos, atraso e licença para uso interno. Wyscout/Data API é opção comercial; API Futebol/API-Football/Sportmonks devem ser avaliados separadamente. Não há tabela de preços ou garantia de cobertura nesta auditoria. Compra/contratação exige sua decisão.

## Plano de implantação e decisões

| Opção | Prós | Contras | Minha posição |
|---|---|---|---|
| A — endurecer e fazer piloto focado | Reaproveita o que existe, corrige a decisão e produz evidência operacional | Menos expansão de fontes no início | **Recomendada** |
| B — investir primeiro em dados/eventos licenciados | Pode ampliar métricas e vídeo se houver cobertura | Custo e integração sem fluxo validado; não corrige defeitos existentes | Depois de A e prova de cobertura |
| C — curadoria manual com cadastro/relatórios | Baixo custo e operação simples | Mais trabalho humano e menos escala | Alternativa enquanto integrações amadurecem |

Ordem proposta, sem promessa de prazo:

1. **Confiabilidade:** F01–F08 e F10; incorporar regressões, CI PostgreSQL, valores e estados, rollback, MFA e jobs. Aceite: suíte antiga e nova passa, configuração de fila comprovada, ensaio de restauração e nenhuma migração destrutiva inadvertida.
2. **Piloto de decisão:** 1–2 necessidades reais autorizadas pelo departamento, 20–30 candidatos, 2–3 perfis de papel calibrados e comparação de pelo menos dois analistas quando possível. Confirmar IDs, contexto e evidências; corrigir F11/F12/F14/F16. Quantidades são proposta de amostra, não tamanho já validado do mercado.
3. **Ferramentas de uso:** comparador, watchlist, notícias manuais e planejador inicial. Medir tempo até shortlist revisada, proporção de requisitos documentados, pendências, divergência entre analistas e aceitação dos alertas. Definir metas com o departamento antes de declarar o piloto bem-sucedido.
4. **Expansão seletiva:** decidir fonte comercial/automação de notícias apenas após cobertura, utilidade e custo demonstrados. Incorporar F09/F13/F17–F22 conforme necessidade, preservando proveniência e revisão.

O score deve ajudar a ordenar trabalho, não substituir a reunião técnica. Para ICE/RICE do backlog, falta estimativa de esforço e impacto pelo time: não inventei números. Prioridade aqui deriva do risco de erro demonstrado e das dependências.

## Entregáveis e reprodução

`backlog-cifut-remo.csv` contém os 22 achados com correção e aceite; `prompt-claude-refinamento-cifut.md` dá a ordem de implementação; `evidencias-cifut/` contém a classe dos oito testes, resultados e verificações. Copiar a classe para `tests/Feature/` de uma cópia isolada do código. Ela usa `ScoutTestCase` e fixtures existentes. Os testes assertam o comportamento desejado, por isso falham na versão auditada.

Usar banco PostgreSQL exclusivamente de teste e as variáveis exigidas pelo projeto. Comando: `php artisan test --filter=AuditRegressionTest`. Executar depois `php artisan test`. O APP_KEY usado no laboratório era artificial; não reaproveitar chave/banco/credenciais de produção. O log normalizado omite caminhos pessoais do laboratório. Os UUIDs de teste são sintéticos.

Não foram aplicadas correções no sistema live, deploy, contratação de API ou mensagens externas. Os arquivos adicionais e dependências existiram somente na cópia local de auditoria. O relatório é uma avaliação do snapshot e dos fluxos descritos, não uma homologação global do sistema.

## Fontes primárias consultadas

- [Sofascore — disponibilidade de API](https://sofascore.helpscoutdocs.com/article/129-sports-data-api-availability) — A FAQ oficial informa que não disponibiliza API de dados esportivos. Um endpoint interno acessível não equivale a integração licenciada; no CIFUT visitado a fonte estava bloqueada.

- [Football Manager 26 — posse e sem posse](https://www.footballmanager.com/fm26/features/possession-out-possession-fm26s-new-tactical-evolution) — Referência para funções e estruturas distintas com e sem posse. Adaptar o conceito ao futebol real, sem importar notas de jogo como dado empírico.

- [FM26 — recrutamento e TransferRoom](https://www.footballmanager.com/fm26/features/powered-transferroom-fm26s-recruitment-revamp) — Referência de fluxo por necessidade, função e oportunidade. A funcionalidade do jogo não concede acesso comercial ao TransferRoom.

- [FIFA — talent identification](https://www.fifatrainingcentre.com/en/environment/guide/high-performance/find/talent-identification-guide.php) — Base para observação contextual, evidência e desenvolvimento do processo de identificação.

- [Hudl Wyscout — Data API](https://www.hudl.com/products/wyscout/data-api) — Opção comercial de dados/eventos. Cobertura de competições, licença e custo precisam de proposta para o caso do Remo.

- [Laravel 13 — filas](https://laravel.com/framework/docs/13.x/queues) — Fundamenta timeout menor que retry_after, retries e processamento confiável.

- [Railway — Laravel](https://docs.railway.com/guides/laravel) — Referência operacional de implantação da stack.

- [Railway — backups de volumes](https://docs.railway.com/volumes/backups) — Backup disponível não substitui prova de restauração do banco e mídias no ambiente concreto.

- [Laravel Pulse](https://laravel.com/framework/docs/13.x/pulse) — Opção de observabilidade interna para jobs e consultas; proteger painel por autorização.

- [PostgreSQL — pg_trgm](https://www.postgresql.org/docs/current/pgtrgm.html) — Busca por similaridade com índices, a adotar depois de medir consultas e necessidades.
