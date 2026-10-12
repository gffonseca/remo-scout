<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/** Seed seguro para produção: catálogo, referências e admin inicial. Dados sintéticos ficam em DemoSeeder. */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([ReferenceDataSeeder::class, CatalogSeeder::class, ClubsSeeder::class, AdminUserSeeder::class, InitialPlayersSeeder::class]);
    }
}
