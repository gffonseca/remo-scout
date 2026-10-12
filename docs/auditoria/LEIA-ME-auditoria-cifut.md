# Pacote da auditoria CIFUT Remo

Abra `auditoria-cifut-remo.html` no navegador: funciona offline e permite filtrar os 22 achados. O Markdown contém o mesmo diagnóstico em formato editável. Use `prompt-claude-refinamento-cifut.md` com Claude junto com o backlog e as evidências.

Conteúdo: relatório HTML/Markdown; backlog CSV com separador ponto e vírgula; prompt; classe de regressão; resultados normalizados; inventário e verificação; manifesto SHA-256. O ZIP original e o código inteiro não são duplicados neste pacote. Nenhuma credencial de login ou secret de produção foi incluída deliberadamente. Os artefatos anteriores de projeto permanecem separados.

Resultados: 45 testes originais passaram; 8 regressões propostas falharam no snapshot. Corrigir não significa apenas alterar testes para passar: satisfazer os critérios de aceite e preservar comportamento válido. Os testes são para ambiente isolado.
