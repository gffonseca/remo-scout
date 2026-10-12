<?php

namespace Database\Seeders;

use App\Models\DataSource;
use Illuminate\Database\Seeder;

/** Catálogo de fontes. Idempotente: não altera "enabled" nem saúde de fontes já existentes. */
class DataSourcesSeeder extends Seeder
{
    /** Cota mensal do contrato e horas máximas esperadas entre ingestões (diagnóstico "atrasada"). */
    /** Autorização de uso já decidida (F20). Só aplicada enquanto a fonte estiver "pendente". */
    private const AUTH = [
        'api_futebol' => ['autorizado', 'Plano contratado pelo clube.', '2026-10-12'],
        'dados_futebol' => ['autorizado', 'Plano Pro contratado (chave entregue pelo Gustavo em 12/10/2026).', '2026-10-12'],
        'api_football' => ['autorizado', 'Plano Pro assinado pelo Gustavo em 12/10/2026.', '2026-10-12'],
        'sumula_fpf' => ['autorizado', 'Documento público; uso interno, sem redistribuição.', '2026-10-12'],
        'sumula_cbf' => ['autorizado', 'Documento público; uso interno, sem redistribuição.', '2026-10-12'],
        'cbf_intermediarios' => ['autorizado', 'Registro público; uso interno.', '2026-10-12'],
        'football_data' => ['autorizado', 'CSV público; uso interno.', '2026-10-12'],
        'bid_cbf' => ['autorizado', 'Registro público consultado manualmente (CAPTCHA no navegador).', '2026-10-12'],
        'cbf_atletas' => ['restrito', 'Fonte não oficial autorizada pelo Gustavo em 11/10/2026 via coletor local; CPF descartado na entrada.', '2026-10-11'],
        'transfermarkt' => ['restrito', 'Não oficial; autorizado pelo Gustavo em 11/10/2026 para consulta sob demanda, com intervalo e cache.', '2026-10-11'],
        'sofascore' => ['restrito', 'Não oficial; termos restringem coleta. Gustavo decidiu (Bloco 2) manter só sob demanda pelo coletor do Mac, ciente do risco de bloqueio.', '2026-10-12'],
        'sportmonks' => ['nao_autorizado', 'Plano Free sem ligas brasileiras; assinatura não aprovada.', '2026-10-12'],
    ];

    /** Cobertura medida (amostra conferida) em 12/10/2026. Só aplicada se ainda não houver conferência registrada. */
    private const COVERAGE = [
        'api_football' => ['2026-10-12 13:40:00', '6 jogos por competição (2026): estatística individual Série A 6/6, B 6/6, Copa do Brasil 3/6; escalação Série C 5/6, Nordeste 6/6; Série D, Paraense e Copa Norte sem escalação. Rodada real: 5 jogos da Série B, gols dos eventos = placar em 5/5, minutos do provedor em 9 de 10 lados.'],
        'dados_futebol' => ['2026-10-12 13:20:00', 'Elenco do Remo: 28 atletas conferidos; sem nascimento, posição pouco confiável; estatística só por time e incompleta. Uso restrito a retrato de elenco.'],
        'football_data' => ['2026-10-12 13:47:00', 'Série A 2022–2026: 1.809 jogos (4×380 + 289); em 2026, 578 jogos-clube (2 por partida). Sem atletas.'],
    ];

    private const LIMITS = [
        'api_futebol' => ['expected_refresh_hours' => 30],
        'api_football' => ['expected_refresh_hours' => 30],
        'dados_futebol' => ['monthly_quota' => 30000, 'expected_refresh_hours' => 192], // elencos: semanal
        'cbf_atletas' => ['expected_refresh_hours' => 30], // coletor do Mac diário às 06:20
        'cbf_intermediarios' => ['expected_refresh_hours' => 30],
        'football_data' => ['expected_refresh_hours' => 192], // semanal
    ];

    public function run(): void
    {
        $na = 'Fonte não oficial: termos do site restringem coleta automatizada. Uso autorizado por Gustavo em 11/10/2026 — consulta sob demanda, com intervalo e cache.';
        $sources = [
            ['api_futebol', 'API Futebol', 'api_comercial', true, true, 'API_FUTEBOL_KEY', 'https://api.api-futebol.com.br', 400, 2000, 'Plano atual: Série A, B, C, D e Copa do Brasil (Paraense, Copa Verde e Nordeste fora do plano).'],
            ['transfermarkt', 'Transfermarkt (transfermarkt-api)', 'nao_oficial', true, false, null, 'https://transfermarkt-api.fly.dev', 2500, 600, $na.' Instância pública fly.dev sem garantia; trocar base_url por instância própria quando possível.'],
            ['sofascore', 'Sofascore (endpoints internos)', 'nao_oficial', true, false, null, 'https://api.sofascore.com', 2500, 600, $na.' Sofascore declara não oferecer API e bloqueia IP de servidor: buscado sob demanda pelo coletor do Mac (tarefas a cada 15 min), com intervalo de 2,5 s e kill switch.'],
            ['sumula_fpf', 'Súmulas FPF (PDF)', 'documento_publico', true, false, null, 'https://www.fpfpara.com.br/files/sumulas', 2000, null, 'Documento público; uso interno, sem redistribuição.'],
            ['sumula_cbf', 'Súmulas CBF (PDF)', 'documento_publico', true, false, null, 'https://conteudo.cbf.com.br/sumulas', 2000, null, 'Documento público; uso interno. Termos da CBF restringem cópia de dados.'],
            ['cbf_atletas', 'CBF — atletas por campeonato', 'nao_oficial', true, false, null, 'https://www.cbf.com.br', 3000, 500, $na.' A API interna expõe CPF: o campo é descartado na entrada e nunca gravado.'],
            ['cbf_intermediarios', 'CBF — intermediários cadastrados', 'documento_publico', true, false, null, 'https://intermediario.cbf.com.br', 3000, 300, 'Registro público de intermediários; alimenta o cadastro de empresários.'],
            ['bid_cbf', 'BID CBF (Boletim Informativo Diário)', 'documento_publico', true, false, null, 'https://bid.cbf.com.br', 3000, null, 'Registros e transferências oficiais, com foto e histórico. A consulta exige CAPTCHA de imagem: sem coleta automática (não contornamos controle de acesso). Uso: consulta assistida no perfil do atleta, registrada como observação contratual com data, link e autor.'],
            ['football_data', 'football-data.co.uk (resultados e odds)', 'documento_publico', true, false, null, 'https://football-data.co.uk', 3000, 50, 'CSV público de resultados e odds de fechamento. Brasil: só Série A (2012→). Sem atletas. Uso: força dos clubes segundo o mercado (contexto do time), não avaliação individual.'],
            ['cbf_parceiros', 'CBF — API de parceiros', 'oficial_credenciado', false, true, 'CBF_PARTNER_CREDENTIAL', 'https://campeonatos.cbf.com.br', 1000, null, 'Exige credencial oficial solicitada pelo clube.'],
            ['api_football', 'API-Football', 'api_comercial', false, true, 'API_FOOTBALL_KEY', 'https://v3.football.api-sports.io', 300, 75000, 'Plano Pro assinado em 12/10/2026 (7.500/dia; pré-pago até 12/11/2026, sem renovação automática). Cobertura 2026 medida em 6 jogos por competição: estatística individual Série A 6/6, Série B 6/6, Copa do Brasil 3/6 (fases finais); escalação Série C 5/6 e Nordeste 6/6; Série D, Paraense e Copa Norte praticamente sem escalação; Copa Verde sem jogos de 2026.'],
            ['dados_futebol', 'Dados Futebol', 'api_comercial', true, true, 'DADOS_FUTEBOL_KEY', 'https://api.dadosfutebol.com.br', 1000, 1000, 'Plano Pro (1.000/dia, 30.000/mês). Útil para elenco atual (clube, camisa), artilharia e estatística coletiva. Estatística é só por time e com cobertura irregular; posição e idade dos atletas não são confiáveis. Não serve para avaliação individual.'],
            ['sportmonks', 'Sportmonks', 'api_comercial', false, true, 'SPORTMONKS_TOKEN', 'https://api.sportmonks.com/v3/football', 500, null, 'Chave recebida em 12/10/2026, plano Free: só Dinamarca e Escócia, nenhuma liga brasileira. Brasil exige plano pago (decisão do Gustavo).'],
            ['soccersapi', 'SoccersAPI', 'api_comercial', false, true, 'SOCCERSAPI_TOKEN', 'https://api.soccersapi.com/v2.2', 500, null, 'Aguardando chave.'],
            ['spider_ogol', 'ogol via spider.cloud', 'nao_oficial', false, true, 'SPIDER_CLOUD_KEY', 'https://api.spider.cloud', 2000, null, $na.' Aguardando chave paga.'],
        ];
        foreach ($sources as [$key, $name, $kind, $enabled, $cred, $env, $url, $interval, $quota, $notes]) {
            $s = DataSource::firstOrNew(['key' => $key]);
            $isNew = ! $s->exists;
            $s->fill(['name' => $name, 'kind' => $kind, 'requires_credential' => $cred, 'credential_env' => $env, 'notes' => $notes,
                'terms_note' => $kind === 'nao_oficial' ? $na : null,
                // CBF recusa IP fora do Brasil: coleta pelo coletor local (Mac Mini), que envia para a API /api/coletor.
                // Sofascore recusa IP de servidor: sob demanda pelo coletor do Mac (decisão do Gustavo, Bloco 2).
                'transport' => in_array($key, ['cbf_atletas', 'cbf_intermediarios', 'sofascore'], true) ? 'coletor_local' : ($key === 'bid_cbf' ? 'consulta_manual' : 'direto')]);
            if ($isNew) {
                $s->fill(['enabled' => $enabled, 'base_url' => $url, 'min_interval_ms' => $interval, 'daily_quota' => $quota]);
            }
            if (isset(self::AUTH[$key]) && in_array($s->usage_authorization ?? 'pendente', ['pendente', null], true)) {
                [$st, $note, $at] = self::AUTH[$key];
                $s->fill(['usage_authorization' => $st, 'usage_note' => $note, 'usage_authorized_at' => $at]);
            }
            if (isset(self::COVERAGE[$key]) && ! $s->coverage_checked_at) {
                $s->fill(['coverage_checked_at' => self::COVERAGE[$key][0], 'coverage_note' => self::COVERAGE[$key][1]]);
            }
            // Limites e expectativa de atualização: só preenche o que ainda está vazio (admin pode ajustar na tela).
            foreach (self::LIMITS[$key] ?? [] as $field => $value) {
                if ($s->{$field} === null) {
                    $s->{$field} = $value;
                }
            }
            $s->save();
        }
    }
}
