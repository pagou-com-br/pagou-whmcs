# Reembolso de Pix


Use a aba administrativa **Refund** da fatura no próprio WHMCS. Selecione a
transação Pix e a opção **Refund through Gateway**. Deixe o valor vazio para
reembolso total ou informe o valor parcial. As opções **Send Email** e
**Reverse Payment** continuam sob controle do WHMCS.

Cada Pix admite uma solicitação de devolução, total ou parcial, conforme o
contrato atual da Pagou. O reembolso é registrado no mesmo envio: assim que a
Pagou aceita o pedido e o envia ao banco, a integração devolve sucesso ao fluxo
nativo. O próprio WHMCS registra a saída, vincula a transação original e aplica
suas regras de estado da fatura, e-mail e reversão. O banco conclui a devolução
ao pagador em seguida, normalmente em poucos minutos, e a integração acompanha
essa conclusão sem outra ação. O card Pagou apenas apresenta o acompanhamento;
ele não contém outro formulário de reembolso.

Se não for possível confirmar o pedido na Pagou, o WHMCS mostra o reembolso
como recusado e a aba **Refund** explica a situação. Envie de novo a mesma
transação e o mesmo valor em alguns minutos: o pedido existente é conferido na
Pagou e nada é devolvido duas vezes. Uma divergência entre a devolução e o
registro do WHMCS abre uma pendência para conferência, sem repetição
automática. Devoluções iniciadas fora do módulo também exigem conferência.

[Voltar ao início](../README.md)
