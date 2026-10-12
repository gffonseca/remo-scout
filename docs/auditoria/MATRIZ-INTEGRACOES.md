# Matriz de integrações (F20) — 12/10/2026

A tela **Fontes de dados** mostra a coluna "Integração", calculada a partir de cada estágio:

| Estágio | Exige |
|---|---|
| Só cadastrada | nada |
| Acesso testado | último teste de saúde com sucesso |
| Ingerindo (cobertura não conferida) | execução concluída ou parcial que **recebeu dados** |
| **Operacional** | ingestão + **cobertura conferida** (amostra registrada) + uso **autorizado ou restrito** |
| Consulta manual | fonte sem coleta automática (BID) |
| Não autorizada / Desligada | decisão registrada / kill switch |

O coordenador registra a conferência de cobertura com "Registrar conferência de cobertura". A autorização de uso é registrada só pelo admin, em "Autorização de uso". Os valores iniciais abaixo vêm de decisões já tomadas e de medições feitas nesta sessão. O seeder só os aplica enquanto o campo estiver vazio, para não sobrescrever uma decisão posterior.

## Fontes

| Fonte | Tipo / acesso | Uso | Competições e campos (medido) | Cota | Situação esperada após o deploy |
|---|---|---|---|---|---|
| API Futebol | comercial, chave | autorizado (plano do clube) | Série A–D e Copa do Brasil: escalação, gols, cartões e substituições. Fora do plano: Paraense, Copa Verde e Nordeste | 2.000/dia | Ingerindo. Falta a conferência de cobertura do analista |
| **API-Football** | comercial, chave (Pro até 12/11/2026) | autorizado | **Estatística individual: Série A 6/6, B 6/6, Copa do Brasil 3/6.** Escalação: Série C 5/6, Nordeste 6/6. Série D, Paraense e Copa Norte praticamente vazias; Copa Verde sem jogos de 2026. Nascimento e altura dos atletas | 7.000 de 7.500/dia | Operacional depois da 1ª sincronização em produção |
| Dados Futebol | comercial, chave (Pro) | autorizado | Elenco atual (nome, camisa). Sem nascimento; posição não confiável; estatística só por time e incompleta | 1.000/dia, 30.000/mês | Operacional (só o retrato de elenco) |
| football-data.co.uk | CSV público | autorizado | Só Série A, 2012→: placar e odds de fechamento. Sem atletas | 50/dia | Operacional (força dos clubes) |
| CBF — atletas | não oficial, coletor no Mac | restrito (11/10/2026) | Inscritos por competição (A–D, Copas, Sub-20); detalhe com nascimento e totais. CPF descartado | — | Ingerindo. Falta a conferência de cobertura |
| CBF — intermediários | registro público, coletor no Mac | autorizado | Lista de intermediários | — | Ingerindo |
| Súmulas FPF/CBF | documento público (PDF) | autorizado | Escalação, minutos, gols e cartões com ID CBF | — | Ingestão por demanda |
| BID CBF | registro público, **CAPTCHA** | autorizado | Contratos, transferências e foto. Sem coleta automática | — | Consulta manual |
| Transfermarkt | não oficial | restrito (11/10/2026) | Perfil, nascimento, cidadania e valor. Sob demanda | 600/dia | Conforme uso |
| Sofascore | não oficial, **coletor no Mac** | restrito (decisão no Bloco 2) | Estatística por temporada, sob demanda. Recusa IP de servidor | 600/dia | Conforme uso; depende do Mac |
| Sportmonks | comercial | **não autorizada** | Plano Free sem ligas brasileiras | — | Não autorizada |
| SoccersAPI, ogol/spider, CBF parceiros | — | pendente | Sem chave ou credencial | — | Desligadas |

## Descartadas nesta sessão

- **kariofreire/api-futebol-brasileiro:** projeto pessoal parado desde 2022. Faz raspagem por regex de um portal de terceiros e cobre só a Série A, no nível do time.

## Regras que continuam valendo

- Fontes **não são somadas**: o perfil mostra cada uma separada, com data de coleta.
- Dado ausente não vira zero. O "por 90" só aparece quando os minutos são válidos.
- Vínculo automático de identidade só por ID confirmado ou nome + nascimento; o resto vai para "Identidades a revisar".
- Fonte não oficial: só sob demanda, com intervalo e kill switch. Nada que exija resolver CAPTCHA ou contornar bloqueio.
