<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Infrastructure\Whmcs;

use InvalidArgumentException;
use RuntimeException;

/** Local immutable artefact storage. Its base directory must be outside the web root. */
final class PrivateStorage
{
    private string $baseDirectory;

    public function __construct(string $baseDirectory, ?string $webRoot = null)
    {
        if ($baseDirectory === '') {
            throw new InvalidArgumentException('A private storage directory is required.');
        }

        $this->baseDirectory = rtrim($baseDirectory, DIRECTORY_SEPARATOR);
        if ($webRoot !== null && $webRoot !== '') {
            $normalBase = $this->normalise($this->baseDirectory);
            $normalWebRoot = rtrim($this->normalise($webRoot), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
            if (str_starts_with($normalBase . DIRECTORY_SEPARATOR, $normalWebRoot)) {
                throw new InvalidArgumentException('Private storage cannot be located inside the web root.');
            }
        }

        if (!is_dir($this->baseDirectory) && !mkdir($this->baseDirectory, 0700, true) && !is_dir($this->baseDirectory)) {
            throw new RuntimeException('Could not create private storage directory.');
        }
        @chmod($this->baseDirectory, 0700);
    }

    public function put(string $relativePath, string $contents): string
    {
        $target = $this->path($relativePath);
        $directory = dirname($target);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Could not create private storage subdirectory.');
        }
        @chmod($directory, 0700);

        $temporary = tempnam($directory, '.pagou-');
        if ($temporary === false) {
            throw new RuntimeException('Could not create temporary private file.');
        }
        try {
            if (file_put_contents($temporary, $contents, LOCK_EX) === false || !chmod($temporary, 0600) || !rename($temporary, $target)) {
                throw new RuntimeException('Could not persist private artefact.');
            }
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }

        return $target;
    }

    public function read(string $relativePath): string
    {
        $path = $this->path($relativePath);
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException('Private artefact is unavailable.');
        }
        return $contents;
    }

    public function path(string $relativePath): string
    {
        if ($relativePath === '' || str_contains($relativePath, "\0") || str_starts_with($relativePath, '/') || str_contains($relativePath, '..')) {
            throw new InvalidArgumentException('Invalid private storage path.');
        }
        if (!preg_match('#\A[a-zA-Z0-9][a-zA-Z0-9._/-]*\z#', $relativePath)) {
            throw new InvalidArgumentException('Private storage paths may contain only safe filename characters.');
        }

        return $this->baseDirectory . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    }

    private function normalise(string $path): string
    {
        $real = realpath($path);
        return $real === false ? rtrim($path, DIRECTORY_SEPARATOR) : $real;
    }
}
