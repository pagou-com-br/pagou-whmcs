# Multa, juros e acréscimos


- Boleto: multa percentual única e juros percentuais mensais, proporcionais aos dias de atraso (base 30 dias). Zero desativa; o contrato aceita de 0,10 a 100 para valores não nulos.
- Pix com vencimento: multa em reais ou percentual; juros em reais por dia corrido, percentual ao dia ou percentual ao mês, conforme a opção escolhida. Pix imediato não envia esses encargos.
- O módulo envia as instruções; a API e o banco calculam os encargos. Não há cálculo local de juros por dias de atraso. O contrato atual também exige que o valor numérico de cada encargo seja inferior ao valor da cobrança, inclusive nos percentuais. Essa condição é conferida antes da emissão.
- Acréscimo comercial é separado: percentual sobre o saldo sem o acréscimo anterior, arredondado para centavos, mais o valor fixo. O item é atualizado sem duplicação e integra o principal enviado à API.
- Na atualização, números já cadastrados são preservados. Juros antigos de Pix e encargos de boleto não nulos exigem revisão na respectiva seção, confirmação das unidades e novo salvamento. A antiga opção genérica de juros percentuais do Pix exige escolher ao dia ou ao mês. Configurações zeradas não exigem confirmação. Salvar outra seção não confirma encargos antigos.
- Cobranças já emitidas não são alteradas pela revisão. Novas emissões com configuração pendente ou inválida são interrompidas antes de consultar o provedor, com motivo na área de pendências. Depois da correção, solicite a emissão novamente.
- Escolha somente uma origem de multa por atraso. Uma nova cobrança com multa Pagou é bloqueada se a multa nativa estiver configurada e aplicável ao cliente, ou se a fatura já contiver um item nativo de multa. O módulo não remove itens nem desativa a multa do WHMCS. A opção de respeitar a isenção do cliente continua disponível. Juros, isoladamente, não são tratados como uma segunda multa.
- Confira manualmente cobranças e pagamentos após vencimento, fins de semana e feriados antes de liberar o uso geral. A modalidade de dias corridos não substitui regras bancárias de prorrogação de vencimento.


[Voltar ao início](../README.md)
