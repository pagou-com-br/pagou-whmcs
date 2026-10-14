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
        $pdo->exec("INSERT INTO tblcustomfieldsvalues (fieldid, relid, value) VALUES (2, 335, '529.982.247-25')");
        $resolver = new ClientDocumentResolver($pdo, new AddonSettings([
            'cpf_field_id' => '2',
            'cnpj_field_id' => '2',
        ]));

        self::assertSame('52998224725', $resolver->resolve([], 335));
    }

    public function testConfiguredPriorityIsPreservedAcrossApiAndDatabaseValues(): void
    {
        $pdo = $this->database();
        $pdo->exec("INSERT INTO tblcustomfieldsvalues (fieldid, relid, value) VALUES (3, 335, '11.222.333/0001-81')");
        $resolver = new ClientDocumentResolver($pdo, new AddonSettings([
            'cpf_field_id' => '2',
            'cnpj_field_id' => '3',
        ]));

        self::assertSame('52998224725', $resolver->resolve([
            'customfields' => ['customfield' => [
                ['id' => 2, 'value' => '529.982.247-25'],
            ]],
        ], 335));
    }

    public function testAWrongNumberIsSkippedForAValidOneAndOtherwiseNamedInvalid(): void
    {
        $pdo = $this->database();
        $pdo->exec("INSERT INTO tblcustomfieldsvalues (fieldid, relid, value) VALUES (3, 335, '11.222.333/0001-81')");
        $resolver = new ClientDocumentResolver($pdo, new AddonSettings(['cpf_field_id' => '2', 'cnpj_field_id' => '3']));
        $client = ['customfields' => ['customfield' => [['id' => 2, 'value' => '123.456.789-01']]]];

        // The CPF fails its check digits, so the valid CNPJ of the other field is used.
        self::assertSame('11222333000181', $resolver->resolve($client, 335));

        $pdo->exec('DELETE FROM tblcustomfieldsvalues');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('CPF ou CNPJ inválido');
        $resolver->resolve($client, 335);
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
