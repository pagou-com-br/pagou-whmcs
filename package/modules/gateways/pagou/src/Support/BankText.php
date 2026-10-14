<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Support;

/**
 * Text as a bank registers a boleto. WHMCS keeps some characters as HTML entities, such
 * as "&amp;", and bank files carry only Latin-1: an ampersand or a typographic dash in
 * the payer name made Pagou answer with a server error instead of a rejection, so the
 * boleto was never issued (invoices 407853, 408714 and 410269).
 */
final class BankText
{
    private const REPLACEMENTS = [
        '&' => ' e ', "\u{2013}" => '-', "\u{2014}" => '-', "\u{2012}" => '-', "\u{2212}" => '-',
        "\u{201C}" => '"', "\u{201D}" => '"', "\u{2018}" => "'", "\u{2019}" => "'", "\u{00A0}" => ' ',
    ];

    public static function clean(string $value): string
    {
        $text = strtr(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'), self::REPLACEMENTS);
        // Anything outside printable Latin-1, such as an emoji or another script, is dropped.
        $text = preg_replace('/[^\x{20}-\x{7E}\x{A1}-\x{FF}]/u', ' ', $text) ?? '';

        return trim(preg_replace('/ {2,}/', ' ', $text) ?? '');
    }
}
