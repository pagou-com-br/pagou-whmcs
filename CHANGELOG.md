# Histórico de versões

Este histórico descreve as versões da distribuição pública do Pagou para WHMCS.

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
