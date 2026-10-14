<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Tests\Unit;

use Pagou\Whmcs\Application\Runtime\{AddonSettings, ClientDocumentValidation};
use PHPUnit\Framework\TestCase;

final class ClientDocumentValidationTest extends TestCase
{
    public function testAWrongNumberOfAClientInBrazilIsRefusedInTheConfiguredField(): void
    {
        $settings = new AddonSettings(['cpf_field_id' => '2']);

        self::assertSame([ClientDocumentValidation::MESSAGE], ClientDocumentValidation::errors(['country' => 'BR', 'customfield' => [2 => '123.456.789-01']], $settings));
        self::assertSame([ClientDocumentValidation::MESSAGE], ClientDocumentValidation::errors(['country' => 'br', 'customfield' => ['2' => '1234']], $settings));
        self::assertSame([], ClientDocumentValidation::errors(['country' => 'BR', 'customfield' => [2 => '529.982.247-25']], $settings));
        self::assertSame([], ClientDocumentValidation::errors(['country' => 'BR', 'customfield' => [2 => '11.222.333/0001-81']], $settings));
    }

    public function testAbroadEmptyOtherFieldsAndADisabledCheckAreLeftToWhmcs(): void
    {
        $settings = new AddonSettings(['cpf_field_id' => '2', 'cnpj_field_id' => '3']);
        $wrong = ['customfield' => [2 => '123.456.789-01']];

        self::assertSame([], ClientDocumentValidation::errors(['country' => 'PT'] + $wrong, $settings));
        self::assertSame([], ClientDocumentValidation::errors($wrong, $settings));
        self::assertSame([], ClientDocumentValidation::errors(['country' => 'BR', 'customfield' => [2 => '', 3 => ' ', 9 => '123']], $settings));
        self::assertSame([], ClientDocumentValidation::errors(['country' => 'BR'] + $wrong, new AddonSettings([])));
        self::assertSame([], ClientDocumentValidation::errors(['country' => 'BR'] + $wrong, new AddonSettings(['cpf_field_id' => '2', 'document_check_on_profile' => '0'])));
        // The alternative field is checked as well.
        self::assertSame([ClientDocumentValidation::MESSAGE], ClientDocumentValidation::errors(['country' => 'BR', 'customfield' => [3 => '11.222.333/0001-00']], $settings));
    }
}
