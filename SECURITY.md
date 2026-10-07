# Segurança

## Relate de forma privada

Envie suspeitas de vulnerabilidade para
[suporte@pagou.com.br](mailto:suporte@pagou.com.br), com o assunto
**Segurança: módulo Pagou para WHMCS**. Não abra uma issue pública contendo
detalhes de exploração antes da avaliação do relato.

Inclua a versão do módulo, PHP e WHMCS, o comportamento observado, o impacto e
passos de reprodução com dados fictícios. Não envie credenciais, arquivos de
configuração, dados pessoais, número de cartão ou CVV. Se forem necessárias
informações adicionais, combine o envio pelo atendimento privado.

## Versões

A distribuição pública começa em `0.3.0-rc.1`. Durante a fase de candidatas,
atualize para a candidata mais recente antes de verificar se um problema
persiste. Correções são comunicadas no [histórico de versões](CHANGELOG.md).

## Cuidados na instalação

- Obtenha o pacote no [repositório oficial](https://github.com/pagou-com-br/pagou-whmcs/releases)
  e compare o SHA-256 com o arquivo publicado na mesma release.
- Use HTTPS, mantenha WHMCS e PHP atualizados dentro da faixa compatível e
  limite as permissões administrativas do addon.
- Configure os mecanismos de autenticação das notificações exigidos pelo
  módulo e mantenha o armazenamento privado fora da raiz pública.
- Proteja credenciais e backups. Nunca armazene número completo de cartão ou CVV.

[Voltar ao início](README.md)
