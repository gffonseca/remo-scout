<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

abstract class ScoutTestCase extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        \App\Support\ClubResolver::flush(); // cache estático de clubes não pode vazar entre testes
        \App\Sources\SourceClient::$sleeper = null;
        $this->seed([ReferenceDataSeeder::class, CatalogSeeder::class]);
    }

    protected function user(string $role, array $extra = []): User
    {
        return User::create(array_merge([
            'name' => ucfirst($role).' '.uniqid(), 'email' => uniqid($role).'@test.local', 'password' => 'secret-password-123',
            'role' => $role, 'is_active' => true,
        ], $extra));
    }
}
