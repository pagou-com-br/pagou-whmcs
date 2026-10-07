# PDFs e e-mails


No Addon, abra **Diagnóstico > PDFs de Pix e boleto** e clique em
**Integrar PDFs ao tema**. Confira a alteração apresentada antes de confirmar.
O instalador identifica o tema ativo e acrescenta somente uma chamada ao módulo.
Ele não distribui nem substitui o `invoicepdf.tpl` inteiro. Nenhum gateway é
ativado ou trocado por essa ação.

O conteúdo anterior é preservado e uma cópia é gravada no armazenamento privado
do módulo, em `pdf-template-backups/`, fora da pasta pública do WHMCS.
**Desfazer integração neste tema** remove apenas o bloco da Pagou, preservando
outras personalizações, inclusive as feitas depois da instalação. Se o bloco
for modificado, será necessária revisão manual. Não restaure uma cópia antiga
por cima de personalizações recentes.

Para templates sem permissão de escrita, links simbólicos, temas herdados sem
PDF próprio ou estruturas não reconhecidas, o Addon oferece o trecho e as
instruções para integração manual. Não amplie permissões indiscriminadamente.
Depois de trocar ou atualizar o tema, confira novamente o diagnóstico. A
ativação, atualização e remoção do módulo não alteram templates automaticamente.

A integração atua somente em faturas não pagas de `pagou_pix` e `pagou_boleto`,
com cobrança local atual, valor e cliente correspondentes. Os gateways de outros
módulos seguem o código existente. Faturas antigas devem manter o meio de
pagamento original quando esse fluxo ainda for necessário; não há migração
automática de faturas.

- Pix: PDF com QR Code, código copia e cola completo, valor e validade. Cobranças
  antigas sem validade local verificável usam o PDF do tema até a atualização
  normal dos dados da cobrança.
- Boleto: usa o PDF previamente armazenado pelo módulo, preservando todas as páginas
  e dimensões, sem buscar arquivos na API durante a renderização. PDFs protegidos,
  inválidos ou incompatíveis com o importador mantêm o PDF do tema.
- E-mail: quando o WHMCS está configurado para anexar o PDF nativo, o documento
  gerado pelo tema ocupa esse lugar. O módulo não acrescenta um segundo boleto
  nesse modo. E-mails personalizados que não utilizam o PDF nativo exigem
  homologação própria. Integrar o tema não ativa a configuração global de anexos.
- Preferências: informações de e-mail desativadas, ou boleto em modo somente link/sem PDF, mantêm o PDF original da fatura. Como o WHMCS compartilha o template, isso vale também para o download nativo. O download direto do boleto na área de pagamento continua seguindo sua configuração própria.
- Sem integração: permanece o comportamento de anexos adicionais já oferecido
  pelo módulo. Sem cobrança utilizável ou diante de falha na geração, a integração
  preserva o gerador anterior do tema e registra a falha sem dados sensíveis.

O conteúdo do e-mail usa HTML próprio, com estilos inline, códigos completos
e links. Templates com `{$invoice_payment_link}` recebem essa apresentação
automaticamente nos gateways oficiais. Quando há uma URL HTTPS da página de boleto Pagou, o botão **Ver boleto**
abre essa página sem exigir login no WHMCS. Na ausência dessa URL, o cliente
acessa a fatura, com autenticação conforme as regras do WHMCS. Não há campos,
botões de copiar ou atualização automática dentro da mensagem.

Homologue em uma instalação de teste um download e um e-mail de Pix e de boleto,
além de uma fatura paga e uma fatura de outro gateway. Confira anexos, valores,
QR Code e linha digitável antes de disponibilizar os novos meios aos clientes.
O ZIP inclui o importador FPDI, com licença MIT e namespace isolado; o TCPDF é
fornecido pelo WHMCS. **Não é necessário instalar Composer no servidor.**


[Voltar ao início](../README.md)
