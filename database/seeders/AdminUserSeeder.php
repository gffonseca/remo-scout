<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Cria o administrador inicial a partir de variáveis de ambiente (sem segredo no código):
 * ADMIN_EMAIL, ADMIN_NAME, ADMIN_INITIAL_PASSWORD. Não altera usuário já existente.
 */
class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $email = env('ADMIN_EMAIL');
        $password = env('ADMIN_INITIAL_PASSWORD');
        if (! $email || ! $password) {
            $this->command?->warn('ADMIN_EMAIL/ADMIN_INITIAL_PASSWORD não definidos — administrador não criado.');

            return;
        }
        User::firstOrCreate(['email' => $email], [
            'name' => env('ADMIN_NAME', 'Administrador'),
            'password' => $password,
            'role' => 'admin',
            'is_active' => true,
            'can_view_financial' => true,
        ]);
    }
}
