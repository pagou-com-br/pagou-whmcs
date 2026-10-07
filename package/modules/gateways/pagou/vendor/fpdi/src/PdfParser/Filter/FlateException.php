<?php

/**
 * This file is part of FPDI
 *
 * @package   Pagou\Whmcs\Vendor\Fpdi
 * @copyright Copyright (c) 2026 Setasign GmbH & Co. KG (https://www.setasign.com)
 * @license   http://opensource.org/licenses/mit-license The MIT License
 */

namespace Pagou\Whmcs\Vendor\Fpdi\PdfParser\Filter;

/**
 * Exception for flate filter class
 */
class FlateException extends FilterException
{
    /**
     * @var integer
     */
    const NO_ZLIB = 0x0401;

    /**
     * @var integer
     */
    const DECOMPRESS_ERROR = 0x0402;
}
