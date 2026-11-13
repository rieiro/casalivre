<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/src/Db.php';
require_once dirname(__DIR__, 2) . '/src/I18n.php';
require_once dirname(__DIR__, 2) . '/src/Settings.php';
require_once dirname(__DIR__, 2) . '/src/CityRepository.php';
require_once dirname(__DIR__, 2) . '/src/CepRepository.php';
require_once dirname(__DIR__, 2) . '/src/helpers.php';

final class AccountPageTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        putenv('APP_ENV=testing');
        Db::loadEnv();
        $this->pdo = db();
        $this->pdo->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    public function testAccountViewRendersLuxuryHeaderAndCards(): void
    {
        $user = [
            'id' => 1,
            'name' => 'Rafael Anfitrião',
            'email' => 'rafael@casalivre.test',
            'cpf' => '123.456.789-00',
            'phone' => '(48) 99999-8888',
            'cep' => '88010-000',
            'street' => 'Rua Felipe Schmidt',
            'address_number' => '100',
            'address_complement' => 'Apto 101',
            'created_at' => '2026-01-01 12:00:00',
        ];

        ob_start();
        try {
            $error = null;
            $csrf = 'csrf_token_test';
            require dirname(__DIR__, 2) . '/views/account.php';
            $html = (string) ob_get_clean();
        } catch (Throwable $e) {
            ob_end_clean();
            throw $e;
        }

        // Luxury Header & navigation
        $this->assertStringContainsString('account-page', $html);
        $this->assertStringContainsString('account-header', $html);
        $this->assertStringContainsString('/painel', $html);
        $this->assertStringContainsString('Voltar ao Painel', $html);

        // Required IDs for account.js
        $this->assertStringContainsString('id="account-form"', $html);
        $this->assertStringContainsString('id="account-error"', $html);
        $this->assertStringContainsString('id="account-ok"', $html);
        $this->assertStringContainsString('id="delete-account"', $html);

        // Luxury Card Structure
        $this->assertStringContainsString('account-card', $html);

        // Form fields
        $this->assertStringContainsString('name="name"', $html);
        $this->assertStringContainsString('name="cpf"', $html);
        $this->assertStringContainsString('name="phone"', $html);
        $this->assertStringContainsString('name="cep"', $html);
        $this->assertStringContainsString('name="street"', $html);
        $this->assertStringContainsString('name="city"', $html);
        $this->assertStringContainsString('name="address_number"', $html);
        $this->assertStringContainsString('rafael@casalivre.test', $html);
    }
}
