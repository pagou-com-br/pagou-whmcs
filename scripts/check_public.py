#!/usr/bin/env python3
"""Standalone content, asset and local-link checks for the public repository."""
from __future__ import annotations

import argparse
import os
import pathlib
import re
import struct
import sys
from urllib.parse import unquote, urlsplit

TEXT_SUFFIXES = {'.php', '.js', '.cjs', '.css', '.svg', '.md', '.txt', '.json', '.lock', '.sh', '.py', '.xml', '.neon', '.yml', '.yaml', '.tpl'}
LOCAL_OUTPUTS = {'.git', 'vendor', 'build', 'var', 'node_modules', '.phpunit.cache', '.phpstan.cache'}
SECRET_PATTERNS = (
    re.compile(r'-----BEGIN (?:[A-Z ]+ )?PRIVATE KEY-----'),
    re.compile(r'(?:pagou_(?:live|test|secret)|whsec)_[A-Za-z0-9_-]{16,}', re.I),
    re.compile(r'AKIA[0-9A-Z]{16}'),
    re.compile(r'Authorization\s*[:=]\s*(?:Bearer|Basic)\s+[A-Za-z0-9._+/=-]{16,}', re.I),
    re.compile(r'(?:gh[pousr]_[A-Za-z0-9]{30,}|github_pat_[A-Za-z0-9_]{30,})'),
)
EDITORIAL = re.compile(r'\b(?:TBD|FIXME|PLACEHOLDER)\b|\bTODO\s*[:(]|\bDRAFT\b', re.I)
BAD_NAMES = re.compile(r'^(?:\.env(?:\..*)?|id_(?:rsa|ed25519)|configuration\.php)$|\.(?:log|sql|pem|key|bak|zip|pdf|pyc)$', re.I)


def files(root: pathlib.Path, local_outputs: bool = False):
    if root.is_symlink() or not root.is_dir():
        raise ValueError('raiz ausente ou simbólica')

    def fail(error):
        raise error

    for directory, subdirs, names in os.walk(root, topdown=True, followlinks=False, onerror=fail):
        parent = pathlib.Path(directory)
        if local_outputs and parent == root:
            subdirs[:] = [name for name in subdirs if name not in LOCAL_OUTPUTS]
            names = [name for name in names if name not in LOCAL_OUTPUTS]
        subdirs.sort()
        for name in subdirs + sorted(names):
            path = parent / name
            relative = path.relative_to(root)
            if path.is_symlink():
                raise ValueError(relative.as_posix() + ': link simbólico proibido')
            if path.is_dir():
                if path.name == '__pycache__':
                    raise ValueError(relative.as_posix() + ': cache não distribuível')
                continue
            if not path.is_file():
                raise ValueError(relative.as_posix() + ': entrada não regular')
            yield relative.as_posix(), path


def image_check(path: pathlib.Path) -> None:
    data = path.read_bytes()
    if path.suffix.lower() == '.png':
        if not data.startswith(b'\x89PNG\r\n\x1a\n'):
            raise ValueError('PNG inválido')
        offset = 8
        while offset + 12 <= len(data):
            size = struct.unpack('>I', data[offset:offset + 4])[0]
            kind = data[offset + 4:offset + 8]
            if offset + size + 12 > len(data):
                raise ValueError('PNG truncado')
            if kind in {b'tEXt', b'zTXt', b'iTXt', b'eXIf'}:
                raise ValueError('PNG contém metadados textuais')
            offset += size + 12
            if kind == b'IEND':
                if offset != len(data):
                    raise ValueError('conteúdo após o PNG')
                return
        raise ValueError('PNG sem encerramento')
    if not data.startswith(b'\xff\xd8'):
        raise ValueError('JPEG inválido')
    offset = 2
    in_scan = False
    saw_scan = False
    while offset < len(data):
        if in_scan:
            marker_start = data.find(b'\xff', offset)
            if marker_start < 0:
                break
            offset = marker_start
        while offset + 1 < len(data) and data[offset:offset + 2] == b'\xff\xff':
            offset += 1
        if offset + 2 > len(data):
            break
        if data[offset] != 255:
            raise ValueError('JPEG inválido')
        marker = data[offset + 1]
        if in_scan and (marker == 0 or 0xD0 <= marker <= 0xD7):
            offset += 2
            continue
        if marker == 0xD9:
            if not saw_scan or offset + 2 != len(data):
                raise ValueError('JPEG sem imagem ou com conteúdo após a imagem')
            return
        in_scan = marker == 0xDA
        saw_scan = saw_scan or in_scan
        if offset + 4 > len(data):
            break
        size = int.from_bytes(data[offset + 2:offset + 4], 'big')
        if size < 2 or offset + size + 2 > len(data):
            raise ValueError('JPEG truncado')
        if marker in {0xE1, 0xED, 0xFE}:
            raise ValueError('JPEG contém EXIF, XMP, IPTC ou comentários')
        offset += size + 2
    raise ValueError('JPEG sem imagem')


def local_links(root: pathlib.Path, path: pathlib.Path, content: str) -> list[str]:
    errors = []
    links = re.findall(r'\]\(([^\s)]+)(?:\s+[^)]*)?\)', content)
    links += re.findall(r'(?:src|href)=["\']([^"\']+)["\']', content)
    for link in links:
        url = urlsplit(link)
        if url.scheme or url.netloc or not url.path:
            continue
        target = (path.parent / unquote(url.path)).resolve()
        if not target.is_relative_to(root.resolve()) or not target.is_file():
            errors.append('link local inválido: ' + link)
    return errors


def scan(root: pathlib.Path, deny_patterns=(), local_outputs: bool = False) -> list[str]:
    errors = []
    for name, path in files(root, local_outputs):
        if BAD_NAMES.search(path.name) or any(pattern.search(name) for pattern in deny_patterns):
            errors.append(name + ': caminho não publicável')
        suffix = path.suffix.lower()
        if suffix in {'.png', '.jpg', '.jpeg'}:
            try:
                image_check(path)
            except ValueError as error:
                errors.append(name + ': ' + str(error))
            continue
        if suffix not in TEXT_SUFFIXES and path.name not in {'LICENSE', 'VERSION', '.gitignore'}:
            errors.append(name + ': formato não permitido')
            continue
        try:
            content = path.read_text(encoding='utf-8')
        except UnicodeDecodeError:
            errors.append(name + ': texto não UTF-8')
            continue
        if '\x00' in content:
            errors.append(name + ': conteúdo binário em arquivo de texto')
        for line, text in enumerate(content.splitlines(), 1):
            if any(pattern.search(text) for pattern in SECRET_PATTERNS):
                errors.append(f'{name}:{line}: possível credencial')
            if any(pattern.search(text) for pattern in deny_patterns):
                errors.append(f'{name}:{line}: referência não publicável')
            if suffix == '.md' and EDITORIAL.search(text):
                errors.append(f'{name}:{line}: conteúdo editorial pendente')
        if suffix == '.md':
            errors.extend(name + ': ' + error for error in local_links(root, path, content))
    return errors


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('root', nargs='?', type=pathlib.Path, default=pathlib.Path(__file__).resolve().parents[1])
    args = parser.parse_args()
    required = ['README.md', 'LICENSE', 'CHANGELOG.md', 'SECURITY.md', 'CONTRIBUTING.md', '.gitignore',
                'package/modules/gateways/pagou/LICENSE', 'package/modules/gateways/pagou/bootstrap.php']
    errors = [name + ': arquivo obrigatório ausente' for name in required if not (args.root / name).is_file()]
    try:
        errors += scan(args.root, local_outputs=True)
    except (ValueError, OSError) as error:
        errors.append(str(error))
    if errors:
        print('\n'.join(errors), file=sys.stderr)
        return 1
    print('Estrutura, conteúdo público, imagens e links locais OK')
    return 0


if __name__ == '__main__':
    sys.exit(main())
