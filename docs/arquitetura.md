# Arquitetura do módulo

O módulo conecta o WHMCS à API Pagou por meio de gateways nativos e um addon
administrativo. A configuração funcional fica no addon; as regras de pagamento,
a comunicação com a API e os adaptadores do WHMCS ficam no núcleo compartilhado.

[![Arquitetura lógica do Pagou para WHMCS](../assets/architecture.svg)](../assets/architecture.svg)

## Componentes

| Componente | Localização no pacote | Responsabilidade |
| --- | --- | --- |
| Addon Pagou Payments | `modules/addons/pagou_payments/` | Administração, configuração, hooks, endpoints, migrations e assets |
| Entradas dos gateways | `modules/gateways/pagou_*.php` | Integração com os pontos de extensão nativos do WHMCS |
| Núcleo compartilhado | `modules/gateways/pagou/src/` | Regras de domínio, serviços, contratos e adaptadores |
| Retornos e notificações | `modules/gateways/callback/` | Recepção HTTP e encaminhamento aos serviços verificados |
| Worker | `modules/addons/pagou_payments/cron.php` | Operações pendentes, conciliação, documentos e e-mails |
| Autoloader | `modules/gateways/pagou/bootstrap.php` | Carregamento nativo das classes instaladas |

## Emissão e processamento

O cron diário do WHMCS agenda a emissão em lote. Ações interativas, como criação
manual e troca do meio de pagamento, avançam somente a fatura selecionada por
`WhmcsRuntime::advanceInvoice`, sem esperar a próxima rodada do cron.

Esses caminhos compartilham a fila persistente de operações e suas proteções de
identidade e exclusão mútua. O worker executa os handlers conforme o orçamento
de trabalho e permite acompanhar operações que precisam de processamento
posterior. Isso evita vincular a emissão em lote ao tempo de resposta de cada
chamada remota.

O registro da cobrança, a obtenção do PDF e a entrega do e-mail possuem etapas
próprias. Uma cobrança emitida pode ainda estar aguardando seu documento.
A interface apresenta o estado disponível e acompanha sua evolução.

## Notificações, consultas e confirmação

As notificações passam por verificação da assinatura, interpretação do evento
e controle de duplicidade. O evento verificado pode avançar sua fatura; a
conciliação também consulta o provedor para conferir o estado da operação.

Antes do lançamento financeiro, o módulo confere a identidade, o valor e a
relação do pagamento com a fatura. O ledger mantém a identidade econômica do
recebimento e controla sua aplicação pelos mecanismos nativos do WHMCS.
Pagamentos de um boleto e de seu Pix associado não devem gerar duas baixas.

Um resultado remoto incerto não autoriza emitir outra cobrança, cancelar ou
reembolsar novamente. A recuperação consulta a operação original e preserva
suas evidências. Aplicações financeiras interrompidas são conferidas no WHMCS
antes de qualquer conclusão sobre seu resultado.

## Persistência e documentos

O módulo utiliza tabelas próprias no banco do WHMCS para operações, tentativas,
notificações, evidências e recebimentos. Os PDFs e backups de integração ao
template utilizam armazenamento privado, fora da raiz pública.

A política de retenção preserva as evidências financeiras necessárias para
conciliação e prevenção de repetição de operações. O diagrama representa essa
persistência como uma responsabilidade compartilhada do módulo, sem detalhar
cada tabela ou relação.

## Cartão e split

O cartão utiliza tokenização no navegador e referências remotas no servidor.
Os dados completos do cartão e o CVV não pertencem à persistência ou aos logs
do módulo. Sessões e retornos possuem verificação específica. A conciliação
de cartão tem seu próprio serviço, também executado pelo worker.

O desenho é uma visão lógica, não uma sequência única obrigatória para todos
os meios: nem toda chamada de cartão passa pela fila genérica. A habilitação
exige a validação do fluxo completo. A candidata aceita uma parcela com captura
automática; não presume parcelamento, captura posterior, 3DS ou recorrência
homologados.

Split representa a leitura de informações de rateio retornadas pela Pagou.
O módulo não cria nem edita regras de divisão, e split de cartão permanece
indisponível. Consulte os [recursos e limites](recursos.md).

## Fontes e testes

Para acompanhar a implementação no código:

- [Coordenação de execução](../package/modules/gateways/pagou/src/Application/Runtime/WhmcsRuntime.php).
- [Worker de operações](../package/modules/gateways/pagou/src/Application/Async/OperationWorker.php).
- [Recepção verificada de notificações](../package/modules/gateways/pagou/src/Application/Webhook/WebhookEndpointService.php).
- [Aplicação de recebimentos](../package/modules/gateways/pagou/src/Payment/Ledger/ReceivedPaymentApplier.php).
- [Registro explícito de capacidades](../package/modules/gateways/pagou/src/Configuration/CapabilityRegistry.php).

Os testes cobrem, entre outros contratos, [agendamento de faturas](../tests/Integration/Runtime/WhmcsRuntimeSchedulingTest.php),
[aplicação de recebimentos](../tests/Integration/Ledger/ReceivedPaymentApplierTest.php)
e [segurança dos retornos de cartão](../tests/Security/Card/RemoteInputSecurityTest.php).
As suítes locais têm limites próprios e não substituem a homologação financeira.

[Voltar ao início](../README.md)
