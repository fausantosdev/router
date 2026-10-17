# Contribuições

Continuação independente de CoffeeCode Router: https://github.com/fausantosdev/router.
Enquanto a implementação estiver apenas no pacote de revisão local, não abra PR supondo que o remoto já contém esta major.

## Ambiente

- PHP 8.5.x com extensões de desenvolvimento indicadas no README.
- Composer 2, `proc_open` e sockets disponíveis para testes HTTP.
- `composer install`, depois `composer check` e `composer audit`.

Mantenha PSR-12 (`vendor/bin/phpcbf --standard=PSR12 src tests tools examples` pode corrigir formatação), testes e análise estática sem warnings. Não adicione baselines só para esconder problemas. O lock é versionado para reproduzir as ferramentas de desenvolvimento; consumidores não herdam as dependências dev.

Uma branch e um PR por mudança coerente. Inclua teste de regressão, documentação, impacto na migração e entrada no CHANGELOG. Preserve a licença e os créditos originais. Não introduza dependências pesadas de runtime, container obrigatório ou descoberta de rotas sem discutir o escopo.

## Segurança

Não publique credenciais nem dados privados em issues ou fixtures. Para um problema sensível, use o recurso de relato privado de vulnerabilidades do GitHub **se habilitado**; caso não esteja, combine um canal privado com o mantenedor antes de divulgar detalhes exploráveis. Este fork ainda não declara um programa formal de segurança.

Nunca remova a proteção do grupo implicitamente. Autorização, CSRF, CORS, sanitização de saída e SQL são responsabilidades da aplicação; a biblioteca deve documentar suas fronteiras sem prometer segurança que não implementa.
