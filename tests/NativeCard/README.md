# Testes isolados do armazenamento de cartão

Esta suíte usa o PHP e os modelos reais do WHMCS, incluindo eventos do Eloquent,
criptografia nativa das referências e transações do Capsule. O banco é um
MariaDB novo e descartável, acessível somente por socket Unix. Os dados são
sintéticos. Não são carregados `init.php`, `configuration.php`, hooks da
instalação ou credenciais Pagou. As funções usuais de acesso HTTP ficam
desabilitadas no processo PHP.

Pré-requisitos: cópia local compatível do WHMCS com seu `vendor`, PHP com
ionCube e PDO MySQL e binários MariaDB (`mariadb-install-db`, `mariadbd`,
`mariadb`, `mariadb-admin`). Não execute como root. Informe os caminhos locais
por variáveis, sem versioná-los:

```bash
PAGOU_WHMCS_ROOT="$WHMCS_SOURCE" \
MARIADB_BIN="$MARIADB_TOOLS" \
PHP_BIN="$PHP_EXECUTABLE" \
bash tests/NativeCard/run.sh
```

O runner não aceita DSN nem banco existente. Cria um diretório privado em
`/tmp`, inicia o servidor sem rede e o encerra ao terminar, inclusive em caso de
falha. Apaga a base após sucesso; preserva somente os arquivos sintéticos da
execução em caso de falha para diagnóstico. O timeout dos locks é limitado.

Cobertura: cadastro/substituição com os modelos nativos, sessão de uso único,
replay, rollback após gravação e após falha SQL na conclusão da sessão,
transações aninhadas, preservação de confirmação/estorno e concorrência real
entre processos PHP independentes. Os testes de concorrência verificam que
ambos os processos chegaram a um bloqueio de linha do InnoDB antes de liberá-los.
O driver usa prepared statements nativos, sem emulação.

Limite: o bootstrap é mínimo e sintético. Não executa os helpers globais
`createCardPayMethod`/`updateCardPayMethod`, o ciclo HTTP completo do WHMCS,
licenciamento, tokenização, adquirente, baixa real, recorrência ou 3DS. Esses
helpers continuam cobertos por doubles na suíte de callback; sua integração
completa exige homologação controlada em instalação licenciada. Nenhuma licença
é simulada ou contornada. Aprovar esta suíte não autoriza ativar cartão.

Execução verificada: WHMCS 8.13.3-release.2, PHP 8.2.31 com ionCube e MariaDB
10.11.19. Isso não atesta a matriz inteira de versões do WHMCS/MySQL.
A suíte é separada de `scripts/check.sh`, pois requer componentes proprietários
locais que não são distribuídos com o módulo.
