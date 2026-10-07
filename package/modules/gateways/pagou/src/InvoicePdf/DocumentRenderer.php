<?php

declare(strict_types=1);

namespace Pagou\Whmcs\InvoicePdf;

use Pagou\Whmcs\Vendor\Fpdi\PdfParser\StreamReader;

final class DocumentRenderer
{
    public function render(PaymentDocument $document): \TCPDF
    {
        $pdf = new PdfEngine();
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetCreator('Pagou para WHMCS');
        $pdf->SetTitle('Pagamento da fatura ' . $document->invoiceId);
        $pdf->SetAutoPageBreak(false);
        if ($document->method === 'boleto') {
            $count = $pdf->setSourceFile(StreamReader::createByString($document->content));
            if ($count < 1 || $count > 20) {
                throw new \RuntimeException('Quantidade de páginas do boleto não suportada.');
            }
            for ($page = 1; $page <= $count; $page++) {
                $template = $pdf->importPage($page);
                $size = $pdf->getTemplateSize($template);
                if (!is_array($size)) {
                    throw new \RuntimeException('Dimensões do boleto indisponíveis.');
                }
                $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
                $pdf->useTemplate($template);
            }
        } elseif ($document->method === 'pix' && DocumentSelector::validPix($document->content)) {
            $this->pix($pdf, $document);
        } else {
            throw new \RuntimeException('Documento de pagamento inválido.');
        }
        // Complete parsing/output before allowing the template to bypass the merchant's original code.
        $pdf->Output('', 'S');
        return $pdf;
    }

    private function pix(\TCPDF $pdf, PaymentDocument $document): void
    {
        $pdf->AddPage();
        $pdf->SetMargins(18, 16, 18);
        $dark = [15, 23, 42];
        $muted = [100, 116, 139];
        $brand = [0, 113, 223];
        $border = [226, 232, 240];
        // A raster of the official logo: older WHMCS TCPDF builds draw the rounded
        // pixels of the SVG wrongly.
        $logo = dirname(__DIR__, 4) . '/addons/pagou_payments/assets/logo-pdf.png';
        if (is_file($logo)) {
            $pdf->Image($logo, 18, 16, 40, 16.3, 'PNG');
        }
        $this->label($pdf, 110, 17, 82, 'FATURA', 'R');
        $pdf->SetTextColor(...$dark);
        $pdf->SetFont('dejavusans', 'B', 15);
        $pdf->SetXY(110, 21.5);
        $pdf->Cell(82, 8, '#' . $document->invoiceId, 0, 0, 'R');
        $pdf->SetLineStyle(['width' => 0.3, 'color' => $border]);
        $pdf->Line(18, 38, 192, 38);

        $pdf->SetFont('dejavusans', 'B', 21);
        $pdf->SetXY(18, 46);
        $pdf->Cell(174, 10, 'Pague com Pix');
        $pdf->SetTextColor(...$muted);
        $pdf->SetFont('dejavusans', '', 10);
        $pdf->SetXY(18, 57);
        $pdf->Cell(174, 6, 'Escaneie o QR Code ou use o código copia e cola no app do seu banco.');

        $pdf->RoundedRect(18, 69, 174, 31, 3, '1111', 'F', [], [239, 246, 255]);
        $this->label($pdf, 26, 76, 80, 'VALOR A PAGAR');
        $pdf->SetTextColor(...$brand);
        $pdf->SetFont('dejavusans', 'B', 24);
        $pdf->SetXY(26, 81);
        $pdf->Cell(80, 12, 'R$ ' . number_format($document->amountCents / 100, 2, ',', '.'));
        $pdf->SetLineStyle(['width' => 0.3, 'color' => [207, 224, 251]]);
        $pdf->Line(110, 75, 110, 94);
        $merchant = self::merchantName($document->content);
        $validity = trim(str_replace('(São Paulo)', '', $document->validUntil));
        $rows = array_filter([
            'RECEBEDOR' => $merchant,
            'VÁLIDO ATÉ' => $validity === '' ? '' : $validity . ', horário de Brasília',
        ], static fn (string $value): bool => $value !== '');
        $y = count($rows) > 1 ? 74.5 : 80;
        foreach ($rows as $label => $value) {
            $this->label($pdf, 118, $y, 70, $label);
            $pdf->SetTextColor(...$dark);
            $pdf->SetFont('dejavusans', $label === 'RECEBEDOR' ? 'B' : '', $label === 'RECEBEDOR' ? 10.5 : 9.5);
            $pdf->SetXY(118, $y + 4.3);
            $pdf->Cell(70, 5, mb_strimwidth($value, 0, 40, '…', 'UTF-8'));
            $y += 11;
        }

        $pdf->SetLineStyle(['width' => 0.3, 'color' => $border]);
        $pdf->RoundedRect(69, 109, 72, 72, 3, '1111', 'D');
        $pdf->write2DBarcode($document->content, 'QRCODE,M', 73, 113, 64, 64, ['border' => 0, 'padding' => 0, 'fgcolor' => [0, 0, 0], 'bgcolor' => [255, 255, 255]], 'N');
        $pdf->SetTextColor(...$muted);
        $pdf->SetFont('dejavusans', '', 9);
        $pdf->SetXY(18, 184);
        $pdf->Cell(174, 5, 'Aponte a câmera do app do seu banco para o QR Code.', 0, 0, 'C');

        $steps = [
            'Abra o app do seu banco e escolha pagar com Pix.',
            'Escaneie o QR Code ou cole o código copia e cola.',
            'Confira o recebedor e o valor e confirme o pagamento.',
        ];
        foreach ($steps as $index => $step) {
            $x = 18 + $index * 59;
            $pdf->Circle($x + 4, 201, 3.6, 0, 360, 'F', [], $brand);
            $pdf->SetTextColor(255, 255, 255);
            $pdf->SetFont('dejavusans', 'B', 8.5);
            $pdf->SetXY($x + 0.4, 198.6);
            $pdf->Cell(7.2, 4.8, (string) ($index + 1), 0, 0, 'C');
            $pdf->SetTextColor(...$dark);
            $pdf->SetFont('dejavusans', '', 8.5);
            $pdf->SetXY($x + 10, 197.4);
            $pdf->MultiCell(46, 4.2, $step, 0, 'L');
        }

        $this->label($pdf, 18, 217, 174, 'PIX COPIA E COLA');
        $pdf->SetFont('dejavusansmono', '', strlen($document->content) > 600 ? 6.5 : 8);
        $height = $pdf->getStringHeight(166, $document->content);
        if (222 + $height + 8 > 268) {
            throw new \RuntimeException('Código Pix excede a área segura do documento.');
        }
        $pdf->SetLineStyle(['width' => 0.3, 'color' => $border]);
        $pdf->RoundedRect(18, 222, 174, $height + 8, 2.5, '1111', 'DF', [], [248, 250, 252]);
        $pdf->SetTextColor(30, 41, 59);
        $pdf->SetXY(22, 226);
        $pdf->MultiCell(166, 4, $document->content, 0, 'L');

        $pdf->SetLineStyle(['width' => 0.3, 'color' => $border]);
        $pdf->Line(18, 274, 192, 274);
        $pdf->SetTextColor(...$muted);
        $pdf->SetFont('dejavusans', '', 7.5);
        $pdf->SetXY(18, 277);
        $pdf->MultiCell(174, 4, 'Este documento contém instruções de pagamento e não é um comprovante. Se a fatura já foi paga, não pague novamente.', 0, 'C');
    }

    /** @param 'L'|'R' $align */
    private function label(\TCPDF $pdf, float $x, float $y, float $width, string $text, string $align = 'L'): void
    {
        $pdf->SetTextColor(100, 116, 139);
        $pdf->SetFont('dejavusans', 'B', 7);
        $pdf->setFontSpacing(0.35);
        $pdf->SetXY($x, $y);
        $pdf->Cell($width, 4, $text, 0, 0, $align);
        $pdf->setFontSpacing(0);
    }

    /** The receiver name inside the Pix payload (EMV field 59), for the payer to check. */
    private static function merchantName(string $payload): string
    {
        $offset = 0;
        while ($offset + 4 <= strlen($payload) && ctype_digit(substr($payload, $offset, 4))) {
            $length = (int) substr($payload, $offset + 2, 2);
            if (substr($payload, $offset, 2) === '59') {
                return trim((string) preg_replace('/[^\p{L}\p{N} .,&\'-]/u', '', substr($payload, $offset + 4, $length)));
            }
            $offset += 4 + $length;
        }

        return '';
    }
}
