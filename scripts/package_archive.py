#!/usr/bin/env python3
"""Deterministic install archives shared by development and public releases."""
from __future__ import annotations

import argparse
import hashlib
import pathlib
import stat
import zipfile


def module_files(source: pathlib.Path) -> dict[str, pathlib.Path]:
    if source.is_symlink() or not source.is_dir():
        raise ValueError('modules precisa ser um diretório real')
    files = {}
    for path in sorted(source.rglob('*')):
        if path.is_symlink():
            raise ValueError('link simbólico no pacote: ' + str(path.relative_to(source)))
        if path.is_dir():
            continue
        if not path.is_file():
            raise ValueError('entrada não regular no pacote')
        if path.name == '.DS_Store':
            continue
        files['modules/' + path.relative_to(source).as_posix()] = path
    if not files:
        raise ValueError('pacote vazio')
    return files


def create(source: pathlib.Path, archive: pathlib.Path) -> None:
    files = module_files(source)
    archive.parent.mkdir(parents=True, exist_ok=True)
    with zipfile.ZipFile(archive, 'w', zipfile.ZIP_DEFLATED, compresslevel=9) as result:
        for name, path in files.items():
            info = zipfile.ZipInfo(name, (1980, 1, 1, 0, 0, 0))
            info.create_system = 3
            info.external_attr = (stat.S_IFREG | 0o644) << 16
            result.writestr(info, path.read_bytes(), compress_type=zipfile.ZIP_DEFLATED, compresslevel=9)
    verify(source, archive)


def verify(source: pathlib.Path, archive: pathlib.Path) -> None:
    files = module_files(source)
    with zipfile.ZipFile(archive) as result:
        names = result.namelist()
        if len(names) != len(set(names)) or set(names) != set(files):
            raise ValueError('entradas do ZIP divergem de modules/')
        for name, path in files.items():
            if result.read(name) != path.read_bytes():
                raise ValueError('conteúdo do ZIP diverge: ' + name)


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('mode', choices=['create', 'verify'])
    parser.add_argument('source', type=pathlib.Path)
    parser.add_argument('archive', type=pathlib.Path)
    args = parser.parse_args()
    try:
        (create if args.mode == 'create' else verify)(args.source, args.archive)
        print(hashlib.sha256(args.archive.read_bytes()).hexdigest())
    except (ValueError, OSError, zipfile.BadZipFile) as error:
        parser.exit(1, str(error) + '\n')
