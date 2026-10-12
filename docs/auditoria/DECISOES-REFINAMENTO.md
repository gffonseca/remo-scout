# Refinamento CIFUT Remo — validação da auditoria e decisões

Base: auditoria de 12/10/2026 sobre o snapshot `3c35f97` (pasta `docs/auditoria/`) e prompt de refinamento.
Decisões tomadas por Gustavo em sessões de Q&A em 12/10/2026. Este arquivo é o contrato do trabalho: mudar aqui antes de mudar no código.

## Validação (feita antes de implementar)

- HEAD real = `3c35f97` (igual ao auditado). Depois disso, só o hotfix `00d6fe9` (F24) foi publicado.
- Testes de regressão da auditoria: 8/8 falham no snapshot, como descrito. Suíte original: 45 testes verdes.
- Achados F01–F22 conferidos no código: todos procedem.
  - F06: risco real, exposição prática baixa com worker único.
  - F14: CBF com mesmo clube é evidência forte, mas passa a exigir nascimento (decisão abaixo).
  - F17: parcialmente tratado nas versões 1.0.1/1.0.2 do coletor.
- `.env.example` e `docs/PROMPT-v2.md` divergentes no ZIP: erro do sanitizador da exportação (falso positivo). O repositório está íntegro.

### Achados novos

| ID | Prior. | Achado | Evidência |
|---|---|---|---|
| F23 | P2 | Workflows herdados do esqueleto Laravel: auto-merge do Dependabot com escrita (main faz deploy automático), changelog/issues sem uso, CI noturno que roda na main sem Postgres e falharia toda noite | `.github/workflows/*` |
| F24 | P1 | Worker morria a cada 1 h: `queue:work --max-time=3600` era o processo principal; o container saía com código 0 e o Railway não reiniciava | Logs: `Worker STOPPED Maximum run time exceeded` às 04:52 UTC; sem logs até o deploy das 09:15 UTC; probe 08:30 e sincronização API Futebol 09:00 não rodaram. **Corrigido no hotfix `00d6fe9`, publicado com autorização.** |

## Decisões

| Tema | Decisão |
|---|---|
| Publicação | Tudo na branch `refino`; deploy só no fim, com autorização final. Exceção já aplicada: hotfix F24. |
| Escopo de fase | Avaliação e requisitos por papel/fase entram **no Bloco 1**, como o prompt pede. |
| Fases do modelo | **Com posse, sem posse e bola parada.** Transições são comportamentos dentro do papel. |
| GitHub | Remover os 4 workflows do esqueleto. CI próprio em push/PR na `main` e `refino`, com PostgreSQL 16 e PHP 8.3, sem gatilho noturno. |
| Dinheiro na importação | Padrão BRL/pt-BR: `25.000` = 25000, `25 mil` = 25000, `R$ 25.000,50` = 25000,50. Formato ambíguo (`25,000`, `1.234,5.6`) fica pendente na prévia com o original visível. Negativo recusado. USD/EUR só se a planilha indicar. |
| Confirmar requisito obrigatório | Analista registra evidência; **só coordenador/admin confirma**. Data, responsável e anexo ou link obrigatórios. |
| Sofascore | **Mantida apenas via coletor local (Mac)**, sob demanda, com kill switch. Não é dependência de nenhum fluxo. Gustavo ciente do risco de termos de uso (endpoint interno não licenciado). Sem contornar bloqueio no Railway. |
| Vínculo de identidade | Autoaceite só com ID oficial já confirmado ou **nome + data de nascimento iguais**. Sem nascimento: candidato em revisão. CBF com mesmo clube e nome compatível vira "provável" até o nascimento conferir. |
| Ensaio de restauração | No **Mac Mini**, Postgres local isolado, com dump real. Gustavo autorizou expressamente mesmo com o FileVault desligado no Mini (decisão dele, registrada em 12/10/2026). Apagar o dump local após o ensaio. |
| IA para notícias | **Ollama no Mac Mini** (modelo local). Curadoria manual estruturada primeiro; rascunho com IA local depois, com citação por afirmação e aprovação humana. Conteúdo externo nunca comanda o modelo. |
| Nomenclatura | "Perfil psicológico" passa a ser **contexto profissional e comportamental documentado**. Sem inferência de saúde mental ou traços sensíveis; sem veto automático. |

### Bloco 3 e piloto

| Tema | Decisão |
|---|---|
| Piloto | Necessidades reais e os 2–3 papéis a calibrar são definidos pelo **Rafael com Gustavo**. O sistema entrega o fluxo e o roteiro do piloto; nada é inventado para preencher telas. |
| Alertas da watchlist | **Só dentro do sistema** (sino + painel), deduplicados. E-mail/WhatsApp desligados até aprovação. |
| Faixa salarial × orçamento | Quatro estados: **Dentro** (faixa inteira ≤ teto), **Intersecta** (parte acima do teto: negociar), **Acima** e **Desconhecido** (sem faixa). O menor valor nunca vira orçamento confirmado. |
| Layout no estilo FM (todos aprovados) | Colunas e filtros salvos por usuário; comparador fixo (3–5 atletas); planejador de elenco por posição; paleta de comandos (Ctrl+K) com ações. |

## Limites que continuam valendo

- Não comprar serviços, não enviar mensagens nem publicar em nome do Gustavo, nada que mexa em dinheiro.
- Não imprimir segredos. Não rodar teste destrutivo em produção.
- Não inventar atletas, avaliações, notícias ou disponibilidade. Dados de demonstração ficam rotulados e segregados.
