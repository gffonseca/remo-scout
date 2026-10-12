<?php

namespace App\Console\Commands;

use App\Sources\SourceClient;
use App\Sources\SumulaImporter;
use App\Sources\SumulaParser;
use Illuminate\Console\Command;

class ImportSumula extends Command
{
    protected $signature = 'scout:import-sumula {url} {--provider=sumula_fpf}';

    protected $description = 'Baixa e importa uma súmula (PDF) da FPF ou CBF';

    public function handle(): int
    {
        $pdf = SourceClient::for($this->option('provider'))->getRaw($this->argument('url'));
        $tmp = tempnam(sys_get_temp_dir(), 'sumula').'.pdf';
        file_put_contents($tmp, $pdf);
        $r = SumulaImporter::import(SumulaParser::parse(SumulaParser::textFromPdf($tmp)), $this->option('provider'), $this->argument('url'));
        @unlink($tmp);
        $this->info("{$r['fixture']->label()}: {$r['appearances']} participações, {$r['created']} atletas novos, {$r['linked']} vinculados por ID CBF");

        return self::SUCCESS;
    }
}
