# Regressões de pagamentos em MySQL nativo

`run.sh` cria uma instância MariaDB descartável, acessível somente por Unix
socket, aplica as migrations do pacote e remove a instância ao concluir.
Não usa configuração, credencial nem dados da instalação WHMCS.

```sh
PHP_BIN=php MARIADB_BIN=/caminho/bin bash tests/NativePayments/run.sh
```

Cobre binds nativos do portal, isolamento do cliente, aquisição e renovação de
lease, reserva do journal entre conexões e agendamento periódico limitado.
A suíte PHP complementar cobre replays de criação/cancelamento/estorno,
resultado incerto, retenção, filtros e ausência de webhook. A suíte NativeCard
permanece independente para os modelos nativos do WHMCS.
