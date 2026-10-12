# Súmulas de competições da CBF — fontes e integração no CIFUT

Pesquisa e provas de acesso em 12/10/2026. **Conclusão: há um caminho público e oficial de descoberta e download de súmulas em PDF. Para ingestão permanente no CIFUT, solicitar autorização institucional e verificar acesso credenciado da CBF.** Não foi acessado sistema restrito nem aplicada mudança no CIFUT.

## Fontes e prioridade

| Canal | Acesso confirmado | Utilidade | Prioridade |
|---|---|---|---|
| Site CBF: tabelas → partida → Documentos | Navegação pública sem login; links documentais observados em partidas de 2025 e 2026 | Descobrir a URL efetivamente publicada e contexto de competição/ano/jogo | Principal para descoberta |
| conteudo.cbf.com.br/sumulas | PDFs públicos obtidos por HTTP 200 | Documento original de súmula, boletim e relatório de delegado | Principal para conteúdo |
| Notícias de Arbitragem CBF / anexos CDN | Notícia oficial contém 17 links PDF: 16 súmulas e 1 adendo | Correções/adendos e documentos publicados como anexos | Complementar, não catálogo completo |
| API — Competições Nacionais | Portal respondeu 200 e mostra login; chamadas autenticadas não testadas | Possível canal estruturado; disponibilidade de documentos depende de confirmação com a CBF | Solicitar ao clube/CBF |
| Federação estadual: FERJ | Página de Botafogo × Remo liga a PDF da CBF hospedado na federação | Fallback de documento oficial para jogo nacional sediado no estado | Complementar com reconciliação |

Links: [Tabelas CBF](https://www.cbf.com.br/futebol-brasileiro/tabelas/campeonato-brasileiro/serie-a/2026), [notícia oficial com súmulas e adendo](https://www.cbf.com.br/a-cbf/noticias/detalhes/arbitragem/cbf-divulga-sumulas-do-brasileirao-serie-a-b-c-e-d), [API Competições Nacionais](https://campeonatos.cbf.com.br/), [exemplo FERJ](https://www.fferj.com.br/partidas/4262).

A notícia é de 25/08/2024: prova um canal de anexos/adendos, não um feed de atualização regular em 2026. Foram identificados anexos no host stcbfsiteprdimgbrs.blob.core.windows.net, distinto do repositório conteudo.cbf.com.br. Não foram baixados os 17 anexos.

## Como acessar manualmente

1. Abrir Tabelas no site da CBF.
2. Selecionar competição, categoria e ano.
3. Localizar rodada/fase e abrir “Documentos do jogo”.
4. Na página da partida, selecionar a aba Documentos.
5. Abrir Súmula; quando disponíveis, conferir Boletim Financeiro e Relatório de Jogo.

A página da partida usa um ID próprio, que não é o número do jogo nem o código do PDF. O seletor de rodada significa que extrair apenas a primeira tela não cobre a temporada. O índice consultado tem links para Séries A/B/C/D, Feminino A1/A2/A3, Sub-20, Sub-20 B, Sub-17 e outras categorias históricas. Link de categoria no catálogo não comprova competição ativa ou todos os PDFs naquela temporada. O CSV anexo registra os links observados, com esse limite explícito.

Além do Brasileirão, foi confirmada página de Copa do Brasil e localizada página oficial de Copa Verde com seção Documentos. A página de Copa Verde foi localizada por pesquisa, mas seu PDF não foi baixado nesta prova. [Exemplo Copa Verde](https://www.cbf.com.br/futebol-brasileiro/jogos/copa-verde/profissional/2026%20/galvez-x-monte-roraima/833610?view=documentos).

## Provas concretas

| Documento | Evidência | Limite |
|---|---|---|
| Vasco × Remo, Série A, 10/10/2026, jogo 292 | Página atual e link observados em navegador; súmula HTTP 200, 44.532 bytes, 3 páginas, texto extraível | Uma partida recente, sem prova de todas as rodadas |
| Remo × Santos, Copa do Brasil, 04/08/2026, jogo 140 | Três links públicos; súmula 45.258 bytes/3 páginas; boletim 85.145 bytes/2 páginas; RDJ 15.449 bytes/2 páginas; todos HTTP 200 | Documento RDJ tem data 08/04/2026; página/súmula/boletim mostram 04/08/2026. Divergência de representação deve ir para conferência, sem correção silenciosa |
| Coritiba × Bahia, Série A, 25/05/2026, jogo 168 | Súmula HTTP 200, 44.534 bytes, 3 páginas, texto extraível | Link localizado por pesquisa, conteúdo depois confirmado no PDF oficial |
| Sport × Santos, Série A, 2025 | Página pública baixada, três links documentais extraídos | PDFs de 2025 não foram baixados nessa pesquisa |
| Botafogo × Remo, Série A, 02/05/2026 | Página oficial FERJ aponta PDF de 3 páginas com cabeçalho CBF | Espelho federativo; conciliar versão/hash com original quando acessível |

Links diretos oficiais testados:

- [Vasco × Remo — súmula](https://conteudo.cbf.com.br/sumulas/2026/142292se.pdf).
- [Remo × Santos — página com documentos](https://www.cbf.com.br/futebol-brasileiro/jogos/copa-do-brasil/masculino/2026/remo-x-santos-fc/834864?view=documentos).
- [Remo × Santos — súmula](https://conteudo.cbf.com.br/sumulas/2026/424140se.pdf).
- [Remo × Santos — boletim financeiro](https://conteudo.cbf.com.br/sumulas/2026/424140b.pdf).
- [Remo × Santos — relatório de jogo](https://conteudo.cbf.com.br/sumulas/2026/424140rdj.pdf).
- [Coritiba × Bahia — súmula](https://conteudo.cbf.com.br/sumulas/2026/142168se.pdf).

Padrão **observado**, não contrato de API: /sumulas/ANO/CODIGOse.pdf, CODIGOb.pdf e CODIGOrdj.pdf. Em duas amostras da Série A aparece 142 seguido do número do jogo; na Copa do Brasil aparece 424. Isso não autoriza presumir códigos das outras competições ou enumerar URLs cegamente. Descobrir o link na página oficial, preservando seus metadados. Adendos podem estar em outro host/nome e não seguem necessariamente esses sufixos.

Cinco PDFs do repositório oficial foram efetivamente baixados: três súmulas, um boletim e um RDJ. Os tamanhos e páginas foram verificados; hashes no JSON anexo. Arquivos integrais ficam na cópia local de trabalho, sem republicação no pacote de pesquisa. A extração por pypdf funcionou nas súmulas amostradas; boletim apresentou aviso de texto rotacionado, de modo que texto extraído não certifica todos os seus valores.

Algumas URLs antigas /competicoes/... retornaram 404; páginas atuais observadas usam /tabelas/... e /jogos/.... A ferramenta de pesquisa teve timeouts/erros onde navegador e download HTTP funcionaram. Isso é limitação de acesso por método, não prova de que o documento não existe.

## O que as súmulas acrescentam ao scouting

Nas amostras: identidade esportiva/ID CBF, nome/apelido, número da camisa, titular/reserva, goleiro, equipe, gols com tipo, cartões, substituições, cronologia e ocorrências. São úteis para vínculo de identidade e participação/eventos oficiais. Não demonstram todas as métricas técnicas desejadas: passes-chave, dribles, mapas de calor, xG e defesas do goleiro não foram encontrados nas súmulas amostradas.

Minutos são cálculo derivado de escalação, entradas/saídas, expulsão e duração, não campo universal pronto. Reserva relacionado não equivale a atleta que entrou. Cartão no banco não prova participação. Considerar intervalo, acréscimos, prorrogação, interrupção e partida não realizada; manter convenção explícita de minutos comparável aos demais fornecedores. Ocorrência narrada é registro da arbitragem, não diagnóstico psicológico ou sanção definitiva.

Boletim financeiro deve ficar separado da súmula esportiva; RDJ é checklist do delegado, não relatório de observação de atleta. Adendo retifica/complementa a versão anterior: nunca apagar a cadeia de versões.

## Acesso institucional e autorização

O portal campeonatos.cbf.com.br se identifica como API de Competições Nacionais e exige usuário/senha. Não foi encontrada documentação pública suficiente para confirmar endpoints, credenciamento, preço ou entrega de súmulas. Não afirmar que a credencial existente do clube dê automaticamente acesso a essa API. Gestão Web e API podem ter escopos e credenciais distintos.

Os [termos de uso atuais da CBF](https://www.cbf.com.br/termos-de-uso) contêm restrições amplas a cópia/reprodução/reutilização não autorizada, inclusive comercial ou não comercial. O download público observado demonstra possibilidade técnica de consulta, não permissão irrestrita de ingestão/redistribuição. A recomendação operacional é obter autorização específica para uso interno do Remo, retenção de PDFs e dados derivados, automação e atualizações. Não foi feita solicitação externa nem formulada conclusão jurídica definitiva sobre cada uso possível.

Pedido a preparar para a área de competições/TI da CBF, pelo relacionamento institucional do clube:

- Confirmar canal oficial e requisitos de credenciamento para Competições Nacionais/API.
- Solicitar catálogo de competições/temporadas, IDs de partidas, URLs ou acesso a súmulas, adendos, boletins e RDJ.
- Esclarecer permissão para download automatizado e armazenamento privado interno no CIFUT, incluindo dados derivados, retenção e limites de consulta.
- Confirmar atualização/correções, janelas de publicação, histórico disponível, suporte e eventual custo.

Nenhuma dessas perguntas foi enviada em nome do usuário.

## Integração recomendada

Manter dois adaptadores distintos: cbf_documentos_publicos para descoberta/PDF; cbf_competicoes_credenciada somente após documentação/credenciais autorizadas. Não usar endpoints internos não documentados como se fossem API contratada.

Pipeline proposto: catálogo/temporada → fases/rodadas → partidas → links de documentos → download validado → extração de texto/OCR apenas quando necessário → parsing → reconciliação → revisão → estatísticas com proveniência.

Guardar para cada documento: competição/ano/categoria, ID da página e número do jogo separados, URL da página e do PDF, tipo, coleta em UTC, hash, publicação quando identificável, parser/versionamento, qualidade e vínculo com eventual adendo. Validar host, tamanho, MIME e assinatura PDF; limitar concorrência, usar cache e respeitar 429/Retry-After; 404 significa ausente naquele momento, sem registrar sucesso ou produzir zeros.

Mapear atletas por ID CBF confirmado e clubes por alias revisável. Armazenar campos estruturados necessários, sem CPF e sem incorporar narrativa inteira às notas automáticas do atleta. Reconciliar corrigidos/removidos, preservando histórico. Painel deve informar partidas esperadas, PDFs encontrados, pendentes, extraídos e revisados; teste de saúde do host não comprova cobertura.

No código já auditado existem SumulaParser e SumulaImporter e a fonte sumula_cbf. Reaproveitar, mas validar: importer presume duração de 90 minutos, inicializa alguns eventos como zero e utiliza heurística de nome para vincular atletas sem ID. A coleta em lote precisa de descoberta efetiva das URLs; o repositório de PDFs sozinho não é um índice completo.

Aceite do piloto: mesmas súmulas importadas duas vezes não duplicam; três competições/categorias amostradas com identidade e eventos conferidos; substituição no intervalo e prorrogação tratadas; adendo atualiza derivado sem destruir original; ausência/divergência fica pendente; cobertura e permissão explicitamente registradas. Não declarar todas as competições disponíveis antes de varrer o catálogo autorizado e testar sua completude.
