<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Tests\Unit\Domain;

use InvalidArgumentException;
use Pagou\Whmcs\Domain\Document;
use Pagou\Whmcs\Domain\DocumentKind;
use PHPUnit\Framework\TestCase;

final class DocumentTest extends TestCase
{
    public function testItNormalizesAndValidatesCpf(): void
    {
        $document = Document::fromString('529.982.247-25');

        self::assertSame('52998224725', $document->digits());
        self::assertSame(DocumentKind::Cpf, $document->kind());
        self::assertSame('***.***.***-25', $document->redacted());
    }

    public function testItRejectsInvalidDocument(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Document::fromString('111.111.111-11');
    }
}
