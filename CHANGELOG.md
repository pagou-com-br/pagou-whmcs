# Histórico de versões

Este histórico descreve as versões da distribuição pública do Pagou para WHMCS.

## 0.3.0-rc.3 (2026-10-29)

Terceira candidata da distribuição pública. Conclui a correção do cron
diário do WHMCS sobre Pix e boleto e simplifica o reembolso de Pix.

- Corrige o tipo do Pix e do boleto que o WHMCS gravou na ativação. A
  mudança da 0.3.0-rc.2 não alterava esse registro, e o cron diário seguia
  tentando cobrar como cartão cada fatura em aberto nesses meios, com o
  e-mail "Credit Card Payment Due". A correção é aplicada sozinha depois
  da atualização e fica registrada no log de atividades.
- Registra o reembolso de Pix no primeiro envio da aba Refund, assim que a
  Pagou aceita o pedido, sem "Refund Declined" e sem um segundo envio.

## 0.3.0-rc.2 (2026-10-14)

Segunda candidata da distribuição pública. Corrige a emissão e o
acompanhamento de Pix e boleto, os avisos ao cliente e a ativação dos gateways.

- Emite o Pix ou o boleto só quando a fatura é publicada, sem criar e
  cancelar cobranças enquanto ela ainda é rascunho.
- Mostra o motivo quando um gateway ou o cartão não pode ser ativado, e
  explica onde exibir aos clientes um gateway ativado oculto.
- Deixa de declarar Pix e boleto como débito em conta, o que fazia o cron do
  WHMCS tentar cobrá-los todo dia e avisar o cliente de cobrança no cartão.
- Gera de novo as cobranças recusadas de um cliente assim que ele ou o admin
  corrige o cadastro, sem esperar que cada fatura seja aberta.
- Mostra no card do admin a orientação de um boleto em conferência e libera
  "Gerar novo boleto", em vez de um carregamento sem fim.
- Envia nome e endereço do pagador do boleto só com caracteres que o banco
  registra, como "e" no lugar de "&", e decodifica entidades HTML do WHMCS.
- Recusa no cadastro do cliente no Brasil um CPF ou CNPJ com dígitos
  verificadores errados, nos campos configurados no módulo.
- Lista em Pendências a cobrança que não pôde ser emitida, encerrada
  sozinha quando a fatura segue adiante.
- Pode avisar o cliente por e-mail, uma vez, quando a cobrança não puder ser
  emitida pelos dados do cadastro. Desligado por padrão.
- Mostra no card da fatura no admin o motivo da falha de emissão e o que
  fazer, em vez de pedir para consultar o diagnóstico.
- Pede o CPF ou CNPJ na própria fatura quando o Pix ou o boleto é recusado
  pelo documento, salva no campo configurado do cadastro e emite de novo.
- Mostra ao cliente o motivo do Pix recusado e só o emite de novo quando o
  cadastro, o valor ou o vencimento mudam.
- Confere os dígitos verificadores do CPF e do CNPJ antes de emitir.
- Mostra a mensagem de uma cobrança encerrada num bloco com ícone, como o
  aviso de preparo do boleto, com tom de alerta na falha de emissão.
- Mostra como falha, com orientação ao cliente, o boleto recusado pela
  Pagou ou pelo cadastro, em vez de "Preparando seu boleto" para sempre, e
  só o emite de novo quando o cadastro, o valor ou o vencimento mudam.
- Aplica a paleta escura do módulo também nos modos escuros baseados no
  DarkReader, sem o brilho claro que aparecia no card da fatura.
- Encerra sozinha a pendência de emissão de boleto quando a cobrança é
  substituída, paga ou cancelada, salvo quando a Pagou listou mais de um
  boleto, e mostra em português o tipo e o estado de cada pendência.
- Resolve uma emissão de boleto sem resposta conferindo na Pagou os boletos
  criados no intervalo do pedido: adota o boleto encontrado, emite de novo
  quando nenhum existe e abre pendência quando há mais de um candidato.
- Trata a recusa do banco emissor como definitiva e limita a três as
  novas emissões automáticas de um boleto que a Pagou não confirma.
- Mostra em português o motivo de uma falha de emissão de Pix ou boleto, na
  fila e no histórico da fatura, sem exibir o texto recebido da API.
- Pede uma única vez, com a fatura de referência, o cancelamento das outras
  cobranças de uma fatura paga, sem nunca incluir a cobrança que recebeu o
  pagamento.
- Atualiza a situação do boleto quando o cancelamento é recusado por ele já
  estar cancelado ou pago, e encerra sozinho operações incertas de cobranças
  já resolvidas.
- Equilibra o painel inicial: cinco recebimentos recentes, como as faturas
  em aberto, e o gráfico diário ocupando a altura da coluna ao lado.
- Registra a data de pagamento do boleto no horário de Brasília quando o
  banco a informa sem fuso horário. Datas com fuso explícito não mudam.
- Conclui sem alerta o cancelamento recusado de um boleto que já foi pago e
  consulta a cobrança imediatamente para registrar o pagamento.
- Calcula a partir da data da execução a validade do Pix usada no teste do
  cancelamento, que vencia em uma data fixa.
- Ajusta a fixture dos endpoints administrativos para utilizar o autoloader
  nativo também no Linux, sem alteração no pacote instalável.

Esta é uma pré-release. Valide em uma instalação de teste antes de usar em
produção. Quem usa a 0.3.0-rc.1 deve atualizar: nela, o cron diário do WHMCS
tenta cobrar as faturas em Pix e boleto e avisa o cliente de uma cobrança no
cartão. Consulte os
[recursos e limites desta versão](https://github.com/pagou-com-br/pagou-whmcs/blob/v0.3.0-rc.2/docs/recursos.md).

## 0.3.0-rc.1 (2026-10-07)

Primeira candidata da distribuição pública, sob licença MIT.

- Integração de Pix imediato e com vencimento, QR Code e código copia e cola.
- Emissão de boleto, acesso à página de pagamento, linha digitável e PDF.
- Addon com visão geral, pagamentos por fatura, relatórios, pendências e diagnóstico.
- Configuração centralizada de encargos, apresentação, e-mails e PDFs.
- Processamento assíncrono, acompanhamento de notificações e conciliação.
- Devolução de Pix integrada ao fluxo nativo de reembolso do WHMCS.
- Integração de cartão com tokenização no navegador, condicionada à habilitação
  e à homologação, limitada a uma parcela com captura automática.
- Consulta dos dados de split retornados pela API, sem criação ou edição de
  regras de divisão e sem split de cartão.
- Código-fonte, guias de instalação, testes automatizados e pacote com checksum.
- README com identidade Pagou, downloads da versão, badges e diagrama de arquitetura vetorial.

Esta é uma pré-release. Valide em uma instalação de teste antes de usar em
produção. Os testes automatizados não substituem a homologação financeira dos
meios de pagamento. Consulte os
[recursos e limites desta versão](https://github.com/pagou-com-br/pagou-whmcs/blob/v0.3.0-rc.1/docs/recursos.md).
