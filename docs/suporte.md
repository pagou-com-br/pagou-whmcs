# Diagnóstico e suporte

Comece pela tela **Diagnóstico** do addon. Confira credencial, armazenamento
privado, worker, notificações e requisitos do meio de pagamento. Em
**Pagamentos**, localize a fatura e consulte o histórico; em **Pendências**,
verifique os itens que precisam de intervenção.

| Sintoma | O que conferir |
| --- | --- |
| Cobrança ainda em preparação | Estado da operação, execução do worker e resposta do provedor |
| Boleto sem PDF | Etapa de obtenção do documento e configuração de PDF do boleto |
| Pagamento ainda não refletido na fatura | Notificação recebida, conciliação, identidade e valor |
| E-mail pendente | Processamento da cobrança, entrega agendada e configuração de e-mail |
| PDF nativo sem dados Pagou | Integração no tema ativo e condições descritas no guia de PDFs |
| Cartão indisponível | Habilitação, bloqueios de capacidade e homologação do fluxo |

Não apague a operação nem gere outra cobrança para resolver um resultado remoto
incerto. Preserve o histórico para que a operação original possa ser conciliada.

## Canais

- **Falha reproduzível no módulo:** abra uma
  [issue](https://github.com/pagou-com-br/pagou-whmcs/issues) com versões do módulo,
  PHP e WHMCS, passos usando dados fictícios e resultado esperado/observado.
- **Conta, credencial ou operação financeira:** contate
  [suporte@pagou.com.br](mailto:suporte@pagou.com.br) pelo atendimento privado.
- **Vulnerabilidade:** siga a [política de segurança](../SECURITY.md).

Não publique tokens, cabeçalhos de autenticação, dados de clientes, documentos,
IDs de cobranças reais ou capturas sem revisão. Reproduza os exemplos com dados
fictícios e informe quais verificações já realizou.

[Voltar ao início](../README.md)
