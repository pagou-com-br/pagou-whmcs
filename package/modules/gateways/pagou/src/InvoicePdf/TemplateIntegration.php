<?php

declare(strict_types=1);

namespace Pagou\Whmcs\InvoicePdf;

use Pagou\Whmcs\Infrastructure\Whmcs\PrivateStorage;

/** Opt-in integration. Never replaces a merchant's template with a bundled template. */
final class TemplateIntegration
{
    private const MARKER = 'PAGOU INVOICE PDF BRIDGE';

    public function __construct(private readonly string $root, private readonly PrivateStorage $storage)
    {
    }

    public static function snippet(): string
    {
        return <<<'PHP'

// BEGIN PAGOU INVOICE PDF BRIDGE v1
if (in_array((string) ($paymentmodule ?? ''), ['pagou_pix', 'pagou_boleto'], true)
    && is_file(ROOTDIR . '/modules/gateways/pagou/invoice-pdf.php')) {
    require_once ROOTDIR . '/modules/gateways/pagou/invoice-pdf.php';
    if (\Pagou\Whmcs\InvoicePdf\TemplateBridge::replace($pdf, (int) ($invoiceid ?? 0), (string) $paymentmodule)) {
        return;
    }
}
// END PAGOU INVOICE PDF BRIDGE v1

PHP;
    }

    /** @return array{theme:string,hash:string,installed:bool,writable:bool,path:string,snippet:string} */
    public function inspect(string $theme): array
    {
        [$path, $source] = $this->read($theme);
        $installed = str_contains($source, self::snippet());
        if (!$installed && str_contains($source, self::MARKER)) {
            throw new \RuntimeException('A integração existente foi modificada. Solicite revisão manual do template.');
        }
        if ($installed && substr_count($source, self::MARKER) !== 2) {
            throw new \RuntimeException('O template contém integrações duplicadas. Solicite revisão manual.');
        }
        $this->insertionOffset($source);
        if ($installed) {
            $this->assertActiveBlock($source);
        }
        return [
            'theme' => $theme,
            'hash' => hash('sha256', $source),
            'installed' => $installed,
            'writable' => is_writable($path) && is_writable(dirname($path)),
            'path' => 'templates/' . $theme . '/invoicepdf.tpl',
            'snippet' => self::snippet(),
        ];
    }

    public function change(string $theme, string $expectedHash, bool $install): void
    {
        $lockPath = $this->storage->path('pdf-template.lock');
        $lock = fopen($lockPath, 'c');
        if ($lock === false) {
            throw new \RuntimeException('Não foi possível bloquear a integração do tema.');
        }
        try {
            if (!flock($lock, LOCK_EX)) {
                throw new \RuntimeException('Outra alteração no tema está em andamento.');
            }
            $state = $this->inspect($theme);
            if (!hash_equals($state['hash'], $expectedHash)) {
                throw new \RuntimeException('O template mudou. Atualize esta página e confira novamente.');
            }
            if ($state['installed'] === $install) {
                return;
            }
            if (!$state['writable']) {
                throw new \RuntimeException('O tema não permite escrita. Utilize as instruções de integração manual.');
            }
            [$path, $source] = $this->read($theme);
            $offset = $this->insertionOffset($source);
            $updated = $install
                ? substr($source, 0, $offset) . self::snippet() . substr($source, $offset)
                : str_replace(self::snippet(), '', $source);
            if (token_get_all($updated, TOKEN_PARSE) === []) {
                throw new \RuntimeException('O template resultante está vazio.');
            }
            $backup = 'pdf-template-backups/' . hash('sha256', $path) . '/' . $state['hash'] . '.tpl';
            if (!is_file($this->storage->path($backup))) {
                $this->storage->put($backup, $source);
            }
            if (!hash_equals(hash('sha256', $this->storage->read($backup)), $state['hash'])) {
                throw new \RuntimeException('A cópia de segurança não pôde ser confirmada. O tema foi preservado.');
            }
            $temporary = tempnam(dirname($path), '.pagou-pdf-');
            if ($temporary === false) {
                throw new \RuntimeException('Não foi possível preparar a alteração do tema.');
            }
            try {
                $mode = fileperms($path);
                if (
                    file_put_contents($temporary, $updated) !== strlen($updated)
                    || $mode === false || !chmod($temporary, $mode & 0777)
                ) {
                    throw new \RuntimeException('Não foi possível gravar a integração do tema.');
                }
                [, $current] = $this->read($theme);
                if (!hash_equals($expectedHash, hash('sha256', $current)) || !rename($temporary, $path)) {
                    throw new \RuntimeException('O template mudou durante a operação. Confira novamente.');
                }
            } finally {
                if (is_file($temporary)) {
                    unlink($temporary);
                }
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function assertActiveBlock(string $source): void
    {
        $offset = strpos($source, self::snippet());
        $prefix = '';
        foreach (token_get_all(substr($source, 0, $offset === false ? 0 : $offset), TOKEN_PARSE) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $prefix .= $token[0] === T_OPEN_TAG ? '<?php' : $token[1];
            } else {
                $prefix .= $token;
            }
        }
        if (!in_array($prefix, ['<?php', '<?phpdeclare(strict_types=1);', '<?phpdeclare(strict_types=0);'], true)) {
            throw new \RuntimeException('A chamada da Pagou não está no início executável do template. Solicite revisão manual.');
        }
    }

    /** @return array{string,string} */
    private function read(string $theme): array
    {
        if (preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_-]{0,99}\z/', $theme) !== 1) {
            throw new \RuntimeException('O nome do tema exige integração manual.');
        }
        $root = realpath($this->root);
        if ($root === false) {
            throw new \RuntimeException('A instalação WHMCS não foi localizada.');
        }
        $path = $root;
        foreach (['templates', $theme, 'invoicepdf.tpl'] as $component) {
            $path .= '/' . $component;
            if (is_link($path)) {
                throw new \RuntimeException('Este tema usa links simbólicos. Utilize a integração manual.');
            }
        }
        if (!is_file($path) || !is_readable($path) || filesize($path) > 1048576) {
            throw new \RuntimeException('O PDF do tema não está disponível para integração automática.');
        }
        $source = file_get_contents($path);
        if ($source === false) {
            throw new \RuntimeException('Não foi possível ler o PDF do tema.');
        }
        return [$path, $source];
    }

    private function insertionOffset(string $source): int
    {
        try {
            $tokens = token_get_all($source, TOKEN_PARSE);
        } catch (\ParseError) {
            throw new \RuntimeException('O template precisa de revisão de sintaxe antes da integração.');
        }
        if (
            !isset($tokens[0]) || !is_array($tokens[0]) || $tokens[0][0] !== T_OPEN_TAG
            || !str_starts_with($source, '<?php')
        ) {
            throw new \RuntimeException('O início do template exige integração manual.');
        }
        foreach ($tokens as $token) {
            if (is_array($token) && in_array($token[0], [T_NAMESPACE, T_HALT_COMPILER], true)) {
                throw new \RuntimeException('A estrutura deste template exige integração manual.');
            }
        }
        $offset = strlen($tokens[0][1]);
        // Inserting a statement before strict_types would make a valid template invalid.
        if (preg_match('/\A<\?php\s*(declare\s*\(\s*strict_types\s*=\s*[01]\s*\)\s*;)/', $source, $match) === 1) {
            $offset = strlen($match[0]);
        }
        return $offset;
    }
}
