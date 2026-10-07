# Reembolso de Pix


Use a aba administrativa **Refund** da fatura no próprio WHMCS. Selecione a
transação Pix e a opção **Refund through Gateway**. Deixe o valor vazio para
reembolso total ou informe o valor parcial. As opções **Send Email** e
**Reverse Payment** continuam sob controle do WHMCS.

Cada Pix admite uma solicitação de devolução, total ou parcial, conforme o
contrato atual da Pagou. A integração acompanha a confirmação e só devolve
sucesso ao fluxo nativo após conferir a identidade e o valor da devolução.
O próprio WHMCS registra a saída, vincula a transação original e aplica suas
regras de estado da fatura, e-mail e reversão. O card Pagou apenas apresenta o
acompanhamento; ele não contém outro formulário de reembolso.

Mantenha a aba aberta até a conclusão. Se fechar a página antes do registro
nativo, o pedido remoto continua acompanhado pelo worker. Retome a mesma
transação e o mesmo valor na aba **Refund** para concluir o pedido existente,
sem enviar outra devolução à Pagou. Um envio nativo com resultado incerto exige
conferência do registro, sem repetição automática. Devoluções iniciadas fora do
módulo também exigem conferência.

[Voltar ao início](../README.md)
