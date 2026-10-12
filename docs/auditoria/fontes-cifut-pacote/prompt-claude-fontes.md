# Extensão de fontes do CIFUT — prompt para Claude

Analise o repositório atual do CIFUT Remo e a avaliação-fontes-cifut.md. Não presuma que fonte cadastrada é coletor implementado. Preserve a stack e corrija as falhas da auditoria anterior que afetem ingestão/fila antes de ativar novas fontes.

Prepare em branch/ambiente isolado um adaptador football_data_co_uk para o CSV público brasileiro. Use a prova local e o validador anexos como referência de schema e qualidade, não como dataset homologado. Integração inicialmente em staging/desativada, até esclarecer permissão de uso institucional e retenção. Não publique, faça deploy ou compre APIs sem autorização específica.

Escopo: download com timeout/tamanho máximo, hash, backoff, validade e fonte; parsing UTF-8 BOM e datas explícitas; resultado ausente null, não 0–0; preservar hora raw e fuso desconhecido; mapeamento revisável de competição/clubes; reconciliação idempotente de partida com mudança de data e resultado; trilha de correções e painel de cobertura. Não gerar estatística individual a partir de resultados coletivos, nem usar odds como nota de papel/atleta. Odds desligadas no MVP. Não mandar partidas para o importador de atletas.

Adicionar testes para ingestão repetida, arquivo atualizado, partida reagendada, linha histórica sem resultado, schema inesperado, clube ambíguo e recuperação de falha. Não expor tokens/payload sensível. Relatar dados e critérios que não puderam ser confirmados. Não chamar endpoints parceiros/pagos sem acesso autorizado; não contornar 429 ou bloqueios com rotação de IP.

Em seguida, preparar roteiro comparativo de API-Football e Sportmonks, já existentes no catálogo do snapshot: inspecionar adaptadores atuais e implementar os ausentes somente com documentação atual. Para API-Football, coverage de liga/temporada antes de players/fixtures; medir campos ausentes mesmo se flag positiva. Para Sportmonks, validar ligas e includes de players/statistics/lineups, histórico e pacote contratado. Não presumir xG disponível em todas as ligas ou planos. Selecionar amostra real autorizada de 20–30 atletas do mercado alvo; não fabricar dados para demonstrar integração.

Entregar diff, testes, migrações compatíveis, matrizes de cobertura e custos publicados revalidados, status de licença, plano de rollout/rollback e limitações. Distinguir prova técnica local, acesso autenticado testado e integração de produção homologada. Não marcar operacional antes de ingestão ponta a ponta, reconciliação e amostra útil ao departamento.
