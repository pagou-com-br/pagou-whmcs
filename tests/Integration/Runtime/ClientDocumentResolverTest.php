<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Tests\Integration\Runtime;

use PDO;
use Pagou\Whmcs\Application\Runtime\AddonSettings;
use Pagou\Whmcs\Application\Runtime\ClientDocumentResolver;
use PHPUnit\Framework\TestCase;

final class ClientDocumentResolverTest extends TestCase
{
    public function testFallsBackToTheConfiguredWhmcsFieldWhenLocalApiOmitsCustomFields(): void
    {
        $pdo = $this->database();
        $pdo->exec("INSERT INTO tblcustomfieldsvalues (fieldid, relid, value) VALUES (2, 335, '123.456.789-01')");
        $resolver = new ClientDocumentResolver($pdo, new AddonSettings([
            'cpf_field_id' => '2',
            'cnpj_field_id' => '2',
        ]));

        self::assertSame('12345678901', $resolver->resolve([], 335));
    }

    public function testConfiguredPriorityIsPreservedAcrossApiAndDatabaseValues(): void
    {
        $pdo = $this->database();
        $pdo->exec("INSERT INTO tblcustomfieldsvalues (fieldid, relid, value) VALUES (3, 335, '12.345.678/0001-90')");
        $resolver = new ClientDocumentResolver($pdo, new AddonSettings([
            'cpf_field_id' => '2',
            'cnpj_field_id' => '3',
        ]));

        self::assertSame('12345678901', $resolver->resolve([
            'customfields' => ['customfield' => [
                ['id' => 2, 'value' => '123.456.789-01'],
            ]],
        ], 335));
    }

    public function testMissingConfiguredDocumentIsRejectedWithoutGuessingAnotherField(): void
    {
        $pdo = $this->database();
        $pdo->exec("INSERT INTO tblcustomfieldsvalues (fieldid, relid, value) VALUES (9, 335, '123.456.789-01')");
        $resolver = new ClientDocumentResolver($pdo, new AddonSettings(['cpf_field_id' => '2']));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('CPF ou CNPJ não encontrado');
        $resolver->resolve([], 335);
    }

    private function database(): PDO
    {
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('CREATE TABLE tblcustomfieldsvalues (fieldid INTEGER NOT NULL, relid INTEGER NOT NULL, value TEXT NULL)');

        return $pdo;
    }
}
