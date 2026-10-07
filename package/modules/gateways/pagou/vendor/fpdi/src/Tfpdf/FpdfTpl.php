<?php

/**
 * This file is part of FPDI
 *
 * @package   Pagou\Whmcs\Vendor\Fpdi
 * @copyright Copyright (c) 2026 Setasign GmbH & Co. KG (https://www.setasign.com)
 * @license   http://opensource.org/licenses/mit-license The MIT License
 */

namespace Pagou\Whmcs\Vendor\Fpdi\Tfpdf;

use Pagou\Whmcs\Vendor\Fpdi\FpdfTplTrait;

/**
 * Class FpdfTpl
 *
 * We need to change some access levels and implement the setPageFormat() method to bring back compatibility to tFPDF.
 */
class FpdfTpl extends \tFPDF
{
    use FpdfTplTrait;
}
