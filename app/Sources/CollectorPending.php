<?php

namespace App\Sources;

/** A fonte é buscada pelo coletor local (Mac): o servidor não acessa a rede e pede este endereço ao coletor. */
class CollectorPending extends SourceException
{
    public function __construct(public string $url, public array $query = [])
    {
        parent::__construct('Aguardando o coletor local buscar '.parse_url($url, PHP_URL_PATH), 'aguardando_coletor');
    }

    public function request(): array
    {
        return ['url' => $this->url, 'query' => (object) $this->query];
    }
}
