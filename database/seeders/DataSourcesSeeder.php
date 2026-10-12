<?php

namespace Database\Seeders;

use App\Models\DataSource;
use Illuminate\Database\Seeder;

/** Catálogo de fontes. Idempotente: não altera "enabled" nem saúde de fontes já existentes. */
class DataSourcesSeeder extends Seeder
{
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
            ['cbf_parceiros', 'CBF — API de parceiros', 'oficial_credenciado', false, true, 'CBF_PARTNER_CREDENTIAL', 'https://campeonatos.cbf.com.br', 1000, null, 'Exige credencial oficial solicitada pelo clube.'],
            ['api_football', 'API-Football', 'api_comercial', false, true, 'API_FOOTBALL_KEY', 'https://v3.football.api-sports.io', 300, 75000, 'Aguardando assinatura (plano Ultra, US$ 29/mês anunciado).'],
            ['sportmonks', 'Sportmonks', 'api_comercial', false, true, 'SPORTMONKS_TOKEN', 'https://api.sportmonks.com/v3/football', 500, null, 'Aguardando trial/assinatura.'],
            ['soccersapi', 'SoccersAPI', 'api_comercial', false, true, 'SOCCERSAPI_TOKEN', 'https://api.soccersapi.com/v2.2', 500, null, 'Aguardando chave.'],
            ['spider_ogol', 'ogol via spider.cloud', 'nao_oficial', false, true, 'SPIDER_CLOUD_KEY', 'https://api.spider.cloud', 2000, null, $na.' Aguardando chave paga.'],
        ];
        foreach ($sources as [$key, $name, $kind, $enabled, $cred, $env, $url, $interval, $quota, $notes]) {
            $s = DataSource::firstOrNew(['key' => $key]);
            $isNew = ! $s->exists;
            $s->fill(['name' => $name, 'kind' => $kind, 'requires_credential' => $cred, 'credential_env' => $env, 'notes' => $notes,
                'terms_note' => $kind === 'nao_oficial' ? $na : null]);
            if ($isNew) {
                $s->fill(['enabled' => $enabled, 'base_url' => $url, 'min_interval_ms' => $interval, 'daily_quota' => $quota]);
            }
            $s->save();
        }
    }
}
