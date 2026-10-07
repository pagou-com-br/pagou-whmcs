# Instalação e atualização

## Antes de começar

Use WHMCS 8.6 a 8.x e PHP 8.1 ou superior, em uma combinação aceita pelo WHMCS.
Tenha uma conta Pagou com os meios pretendidos habilitados, HTTPS e acesso de
saída à API. Use primeiro uma instalação de teste e faça backup dos arquivos,
do banco e das personalizações do tema.

## Obter o pacote

Baixe o ZIP `pagou-whmcs-VERSAO.zip` e `SHA256SUMS` da mesma
[release oficial](https://github.com/pagou-com-br/pagou-whmcs/releases).
Na pasta dos dois arquivos, verifique:

```sh
shasum -a 256 -c SHA256SUMS
```

O resultado esperado é `OK`. O ZIP contém somente `modules/`, com o addon e os
gateways Pagou. Extraia esse diretório na raiz do WHMCS, preservando os demais
módulos. Os arquivos **Source code** do GitHub são destinados ao desenvolvimento.
O servidor não precisa de Composer nem de `vendor/` deste repositório.

## Configuração inicial

1. No WHMCS, ative o addon **Pagou Payments** e conceda acesso somente aos
   grupos administrativos responsáveis pela operação.
2. Abra o addon e configure a credencial Pagou, os IDs dos campos personalizados
   de CPF/CNPJ e as opções gerais. Use os campos existentes na instalação.
3. Nas configurações nativas de gateways do WHMCS, ative **Pagou - Pix** e/ou
   **Pagou - Boleto**, conforme sua habilitação. Nome e visibilidade ficam no
   gateway; as opções funcionais ficam no addon.
4. Revise as seções Pix e Boleto no addon. Confira vencimento, limites,
   acréscimos, informações de e-mail e PDF. Leia as [regras de encargos](encargos.md)
   antes de configurar multa ou juros.
5. Configure as notificações Pagou usando os endpoints e os mecanismos de
   autenticação indicados pelo diagnóstico do addon. Use a URL pública HTTPS
   correta da instalação e confira o recebimento das notificações.
6. Configure o worker e confira todos os itens da tela **Diagnóstico**.

O gateway de cartão exige habilitação técnica e homologação próprias. Sua
presença no pacote não autoriza disponibilizá-lo aos clientes. Consulte
[recursos e limites](recursos.md).

## Worker

Agende o comando abaixo a cada minuto, substituindo os caminhos pelo PHP CLI
e pela raiz reais da instalação. Execute com o usuário apropriado do WHMCS.

```cron
* * * * * php /caminho/do/whmcs/modules/addons/pagou_payments/cron.php
```

Mantenha também o cron nativo do WHMCS. O cron diário agenda a emissão em lote;
o worker processa operações pendentes, PDFs, conciliação e entrega de e-mails.
Ações interativas avançam a fatura selecionada imediatamente, compartilhando
as mesmas proteções contra duplicidade. A tela acompanha o processamento mesmo
quando o provedor ainda está preparando a cobrança.

Confirme a execução recente do worker no diagnóstico e utilize uma versão PHP
CLI compatível com a versão web.

## Validar antes de disponibilizar

Confira emissão, valores, vencimento, pagamento confirmado, lançamento nativo
no WHMCS, e-mail e PDF dos meios habilitados. Teste uma fatura paga e outra de
um gateway diferente. Se for utilizar devolução de Pix, valide também esse
fluxo e seu registro nativo. A [integração de PDFs ao tema](pdfs-e-emails.md) é
uma ação separada, apresentada para revisão no addon.

## Atualizar e recuperar

Leia o [changelog](../CHANGELOG.md), mantenha um backup verificável e valide a
nova versão em teste. Substitua somente os arquivos e diretórios da Pagou pelo
conteúdo da nova release, sem excluir pastas compartilhadas do WHMCS. Não copie
arquivos de configuração de outra instalação. Abra o addon e confira o
diagnóstico, o worker, os webhooks e a integração de PDF do tema.

Uma atualização não migra automaticamente faturas de outros gateways. Preserve
o meio original enquanto houver cobranças antigas a acompanhar.

Se houver falha, interrompa a liberação de novas cobranças pelo meio afetado e
investigue os registros. Não apague tabelas, operações pendentes ou evidências
de pagamento. Uma restauração exige avaliar arquivos, banco e operações remotas
em conjunto; restaurar apenas arquivos não desfaz pagamentos nem mudanças no
banco. Consulte o [suporte](suporte.md) se o estado financeiro for incerto.

[Voltar ao início](../README.md)
