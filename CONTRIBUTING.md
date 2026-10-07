# Contribuindo

Contribuições para o módulo oficial Pagou são bem-vindas. Procure primeiro uma
issue relacionada. Para alterações extensas, descreva a proposta antes de
enviar o código, incluindo o comportamento esperado e as versões afetadas.

## Preparar o ambiente

Use PHP 8.1+, Composer 2, Python 3.10+, Node.js 22+, Bash, `unzip` e `shasum`.
Instale as dependências e execute as verificações:

```sh
composer install
composer check
```

O pacote instalável está em `package/modules`. Edite as fontes e acrescente
testes para comportamentos novos ou corrigidos. `vendor/` e `build/` são saídas
locais. O módulo instalado usa seu autoloader nativo e não depende do Composer.

`composer check` executa compatibilidade PHP, PHPCS, PHPUnit, PHPStan, testes
JavaScript, verificação de conteúdo público, autoload e construção e validação
do ZIP. As ferramentas obrigatórias ausentes fazem o comando falhar.

## Testes com WHMCS e banco

Os testes que precisam da distribuição licenciada do WHMCS e de banco
descartável são executados separadamente:

- [Persistência e pagamentos nativos](tests/NativePayments/README.md).
- [PayMethods e cartão nativo](tests/NativeCard/README.md).

Siga os requisitos de cada suíte. Não utilize banco ou configuração de produção.
Ao enviar uma alteração, informe os testes executados e quaisquer limitações.
Uma suíte local aprovada não comprova homologação com a API nem habilita cartão.

## Preparar o pull request

Descreva o problema, o comportamento resultante e a validação. Mantenha o escopo
focado e use dados fictícios. Não inclua credenciais, logs de clientes, backups,
capturas de contas reais ou conteúdo de uma instalação licenciada do WHMCS.

Preserve identidade e valor nas confirmações de pagamento, deduplicação,
conciliação de operações incertas e tokenização do cartão no navegador.
Mudanças financeiras precisam de testes de contrato e revisão das unidades
monetárias e das regras do meio afetado.

As contribuições são revisadas antes de integrar uma versão oficial. Os
releases contêm um ZIP de instalação, `SHA256SUMS` e notas da versão. O código
público permite reproduzir o ZIP com `composer build`.

Ao contribuir, você concorda em disponibilizar sua contribuição sob a
[licença MIT](LICENSE). Relate vulnerabilidades pelo [canal de segurança](SECURITY.md).
