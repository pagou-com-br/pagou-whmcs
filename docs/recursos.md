# Recursos e limites

## Pix

O módulo inclui Pix imediato e Pix com vencimento, QR Code, código copia e cola,
acompanhamento na fatura e confirmação integrada ao WHMCS. Multa e juros são
opções do Pix com vencimento, conforme as [regras de encargos](encargos.md).
A devolução usa o [fluxo nativo de reembolso](reembolsos.md).

## Boleto

Inclui emissão, linha digitável, página de pagamento Pagou, obtenção do PDF,
acompanhamento e confirmação. O registro do boleto, a preparação do PDF e a
entrega de e-mail podem terminar em momentos distintos. O módulo acompanha essas
etapas e apresenta o estado disponível na fatura.

Quando a API associa um Pix ao boleto, a confirmação é deduplicada para impedir
que o mesmo recebimento seja lançado duas vezes. O PDF e o e-mail têm
[configuração própria](pdfs-e-emails.md).

## Cartão de crédito

A integração utiliza tokenização no navegador e referências remotas no servidor.
O pacote inclui o gateway e as telas relacionadas, mas seu uso depende de
habilitação da conta, configuração técnica e homologação do fluxo completo.
Os bloqueios de capacidade permanecem ativos quando esses requisitos não estão
atendidos.

O fluxo de checkout implementado aceita **uma parcela e captura automática**.
Não considere parcelamento, captura posterior, 3DS ou recorrência homologados
pela presença de campos, classes ou recursos na API. Não habilite opções
incompatíveis com o contrato efetivamente suportado pelo módulo.

Número completo de cartão e CVV não devem passar pelo backend do módulo, ser
persistidos ou aparecer em logs. As sessões de retorno e as referências de
pagamento possuem validação própria.

## Split

O módulo interpreta e apresenta informações de divisão retornadas pela Pagou
quando esses dados estão disponíveis para a cobrança. Essa consulta não define
recebedores, cria regras de rateio nem executa repasses adicionais.
O split de cartão permanece indisponível. Não presuma que uma capacidade
divulgada pela API esteja habilitada neste checkout.

## Confirmação e operação

Notificações e conciliação conferem identidade, valor e relação com a fatura
antes de registrar o recebimento pelos mecanismos nativos do WHMCS. Um estado
remoto `paid`, isoladamente, não é suficiente.

Em resultados incertos, a operação original precisa ser consultada e conciliada.
Repetir a criação, o cancelamento ou a devolução pode produzir efeitos
financeiros duplicados. Utilize o histórico e o diagnóstico para acompanhar
a operação já registrada.

Os recebimentos de toda a conta Pagou e os registros locais do módulo possuem
fontes e abrangências diferentes. Uma consulta indisponível não equivale a
saldo ou recebimento zero.

[Voltar ao início](../README.md)
