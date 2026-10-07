<?php

/**
 * This file is part of FPDI
 *
 * @package   Pagou\Whmcs\Vendor\Fpdi
 * @copyright Copyright (c) 2026 Setasign GmbH & Co. KG (https://www.setasign.com)
 * @license   http://opensource.org/licenses/mit-license The MIT License
 */

namespace Pagou\Whmcs\Vendor\Fpdi\PdfParser\Type;

use Pagou\Whmcs\Vendor\Fpdi\PdfParser\PdfParserException;

/**
 * Exception class for pdf type classes
 */
class PdfTypeException extends PdfParserException
{
    /**
     * @var int
     */
    const NO_NEWLINE_AFTER_STREAM_KEYWORD = 0x0601;
}
