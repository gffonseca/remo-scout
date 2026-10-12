# CIFUT Remo — súmulas oficiais CBF

Use fontes-sumulas-cbf.md e evidencias-acesso.json como pesquisa, não como autorização para alterar produção, acessar sistema restrito ou copiar credenciais. Inspecione versão atual do CIFUT e os existentes SumulaParser, SumulaImporter, fonte sumula_cbf e filas. Prepare diff revisável em ambiente isolado; não faça deploy nem solicite acesso externo em nome de Gustavo sem autorização específica.

Implementar descoberta de documentos pelo caminho público observado Tabelas → competição/categoria/ano → rodadas/fases → partida → Documentos. Coletar URLs efetivamente publicadas, sem gerar/enumerar nomes de PDFs por palpite. Guardar IDs de página, jogo e documento separadamente. Indexar súmula, boletim, RDJ e adendos como tipos distintos. Notícias/anexos oficiais complementam correções; federação é fallback com proveniência, não substituição silenciosa.

Preparar adaptador inicialmente em staging/desativado até registrar autorização de ingestão permanente conforme termos CBF. API Competições Nacionais exige credenciamento; documentação/escopo não foram confirmados. Não inferir que login de Gestão Web se aplica à API e não contornar bloqueios. Desenhar interface para adaptador credenciado futuro sem inventar endpoints.

Download com allowlist de hosts confirmados, assinatura PDF, tamanho máximo, timeout, hash, cache, backoff/Retry-After e baixa concorrência. Extrair texto primeiro; OCR somente PDF sem texto. Parsing versionado e validação de competição/equipes/data/gols/IDs antes de promover. Preservar texto original necessário e procedência em armazenamento privado conforme autorização. Não armazenar CPF nem produzir conclusão psicológica por ocorrências.

Titular/reserva, participação, gols normais/pênalti/contra/falta, cartões e substituições separados. Não assumir que reserva participou ou que cartão prova entrada. Minutos derivados devem tratar intervalo, acréscimos, prorrogação/abandono e convenção de cálculo. Dados desconhecidos não são zero. PDF oficial não contém por si só dribles/xG/defesas: não fabricar campos.

Preservar versões e adendos com hash; reprocessar correções e retirar eventos obsoletos dos agregados. Idempotência e controle de concorrência. Divergência de datas na amostra Remo × Santos: RDJ 08/04/2026 contra página/súmula/boletim 04/08/2026; não normalizar sem conferência. Validar datas por contexto e registrar ambiguidade.

Testes: reimportação, alteração de PDF/adendo, reserva sem uso, cartão no banco, intervalo, acréscimo, prorrogação, nomes truncados/acentos, ID ambíguo, schema/PDF inesperado e 404/429. Proteger médias/por90 por qualidade e contexto. Dashboard mede partidas esperadas/documentos encontrados/pendentes/rejeitados/revisados por competição/ano. Não tratar resposta 200 do host como integração completa.

Entregar coletor/parser/diff, migrações, testes, matriz de cobertura e limitações, roteiro de piloto e plano de deploy/rollback. Preparar minuta de pedido institucional de autorização/documentação como rascunho, sem enviar. Primeiro piloto com amostras de competições diferentes; ampliar só com cobertura e autorização verificadas.
