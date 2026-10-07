<?php

/**
 * This file is part of FPDI
 *
 * @package   Pagou\Whmcs\Vendor\Fpdi
 * @copyright Copyright (c) 2026 Setasign GmbH & Co. KG (https://www.setasign.com)
 * @license   http://opensource.org/licenses/mit-license The MIT License
 */

namespace Pagou\Whmcs\Vendor\Fpdi;

use Pagou\Whmcs\Vendor\Fpdi\PdfParser\CrossReference\CrossReferenceException;
use Pagou\Whmcs\Vendor\Fpdi\PdfParser\PdfParserException;
use Pagou\Whmcs\Vendor\Fpdi\PdfParser\Type\PdfIndirectObject;
use Pagou\Whmcs\Vendor\Fpdi\PdfParser\Type\PdfNull;

/**
 * Class Fpdi
 *
 * This class let you import pages of existing PDF documents into a reusable structure for FPDF.
 */
class Fpdi extends FpdfTpl
{
    use FpdiTrait;
    use FpdfTrait;

    /**
     * FPDI version
     *
     * @string
     */
    const VERSION = '2.6.8';
}
