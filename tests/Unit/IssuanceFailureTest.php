<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Tests\Unit;

use Pagou\Whmcs\Application\Runtime\IssuanceFailure;
use Pagou\Whmcs\Presentation\OperationDetails;
use PHPUnit\Framework\TestCase;

final class IssuanceFailureTest extends TestCase
{
    public function testPagouRejectionsBecomeStableCodesAndPortugueseReasons(): void
    {
        $cases = [
            'invalid customer document' => 'A Pagou recusou o CPF ou CNPJ do cliente. Corrija o cadastro e gere a cobrança novamente',
            'invalid customer address' => 'A Pagou recusou o endereço do cliente. Confira CEP, rua, cidade e estado no cadastro',
            'charge total must be greater than 5' => 'Valor abaixo do mínimo aceito pela Pagou',
            'conta suspensa ou inativa' => 'A conta Pagou está suspensa ou inativa',
            'charge provider rejected the operation' => 'O banco emissor recusou a cobrança. Gere novamente mais tarde',
            'something new from Pagou' => 'A Pagou recusou a cobrança. Confira o CPF ou CNPJ e o endereço do cliente',
        ];
        foreach ($cases as $message => $reason) {
            self::assertSame($reason, OperationDetails::reason('boleto_request_rejected:' . IssuanceFailure::provider($message)), $message);
        }
        // Provider text never becomes part of the stored code.
        self::assertSame('unknown', IssuanceFailure::provider('<script>alert(1)</script> CPF 123.456.789-00'));
    }

    public function testLocalValidationNamesTheMissingField(): void
    {
        self::assertSame('Falta o CEP no cadastro do cliente', OperationDetails::reason('boleto_local_validation:' . IssuanceFailure::local(new \InvalidArgumentException('Boleto payer zip is required.'))));
        self::assertSame('CPF ou CNPJ não encontrado no cadastro do cliente, nos campos configurados', OperationDetails::reason('pix_local_validation:' . IssuanceFailure::local(new \RuntimeException('CPF ou CNPJ não encontrado nos campos configurados.'))));
        // Codes stored before this version, with only the exception class, still explain themselves.
        self::assertSame('Dados do cliente incompletos para emitir a cobrança. Confira o CPF ou CNPJ e o endereço no cadastro', OperationDetails::reason('boleto_local_validation:RuntimeException'));
        self::assertSame('A Pagou recusou a cobrança. Confira o CPF ou CNPJ e o endereço do cliente', OperationDetails::reason('boleto_request_rejected'));
        self::assertSame('Falta o CEP no cadastro do cliente', OperationDetails::reason('retry_exhausted:boleto_local_validation:missing_zip'));
        self::assertSame('O boleto exige endereço no Brasil, e o cadastro do cliente está em outro país', OperationDetails::reason('boleto_local_validation:' . IssuanceFailure::local(new \InvalidArgumentException('Boleto payer address must be in Brazil.'))));
        self::assertNull(OperationDetails::reason('something_else'));
        self::assertStringContainsString('confere na Pagou se o boleto foi criado', (string) OperationDetails::reason('boleto_remote_outcome_unknown'));
    }

    public function testTheCustomerReadsWhatToDoWhenTheBoletoCannotBeIssued(): void
    {
        self::assertStringStartsWith('O boleto exige um endereço no Brasil.', IssuanceFailure::customerMessage('boleto_local_validation:foreign_address'));
        self::assertStringStartsWith('O banco não emitiu o boleto.', IssuanceFailure::customerMessage('boleto_request_rejected:provider_rejected'));
        self::assertStringStartsWith('Não foi possível gerar o boleto agora.', IssuanceFailure::customerMessage('boleto_request_rejected:account_suspended'));
        foreach (['boleto_request_rejected:unknown', 'boleto_request_rejected:invalid_customer_address', 'boleto_local_validation:missing_zip', 'boleto_request_rejected'] as $reason) {
            self::assertStringStartsWith('Não foi possível gerar o boleto com os dados do seu cadastro.', IssuanceFailure::customerMessage($reason), $reason);
        }
    }
}
