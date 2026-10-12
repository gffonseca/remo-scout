<?php

namespace Database\Seeders;

use App\Models\DataSource;
use Illuminate\Database\Seeder;

/** Catálogo de fontes. Idempotente: não altera "enabled" nem saúde de fontes já existentes. */
class DataSourcesSeeder extends Seeder
{
    /** Cota mensal do contrato e horas máximas esperadas entre ingestões (diagnóstico "atrasada"). */
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
            ['sofascore', 'Sofascore (endpoints internos)', 'nao_oficial', true, false, null, 'https://api.sofascore.com', 2500, 600, $na.' Sofascore declara não oferecer API; pode bloquear IPs de servidor.'],
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
                'transport' => in_array($key, ['cbf_atletas', 'cbf_intermediarios'], true) ? 'coletor_local' : ($key === 'bid_cbf' ? 'consulta_manual' : 'direto')]);
            if ($isNew) {
                $s->fill(['enabled' => $enabled, 'base_url' => $url, 'min_interval_ms' => $interval, 'daily_quota' => $quota]);
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
