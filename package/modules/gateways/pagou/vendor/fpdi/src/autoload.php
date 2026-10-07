<?php

/**
 * This file is part of FPDI
 *
 * @package   Pagou\Whmcs\Vendor\Fpdi
 * @copyright Copyright (c) 2026 Setasign GmbH & Co. KG (https://www.setasign.com)
 * @license   http://opensource.org/licenses/mit-license The MIT License
 */

spl_autoload_register(static function ($class) {
    if (strpos($class, 'Pagou\Whmcs\Vendor\Fpdi\\') === 0) {
        $filename = str_replace('\\', DIRECTORY_SEPARATOR, substr($class, 14)) . '.php';
        $fullpath = __DIR__ . DIRECTORY_SEPARATOR . $filename;

        if (is_file($fullpath)) {
            /** @noinspection PhpIncludeInspection */
            require_once $fullpath;
        }
    }
});
