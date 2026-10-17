# Changelog

## Unreleased — proposta de nova major (PHP 8.5)

### Corrigido
- Nullable explícito e inicialização segura de rotas.
- Capturas extraídas no dispatch; dados consistentes entre handler, middleware e `current()`.
- Remoção de substituição textual de grupos nos parâmetros.
- Escape de literais na regex e prioridade determinística de rotas.
- Composição de middleware grupo + rota, sem substituição silenciosa.
- Distinção entre rota inexistente (404), verbo não permitido (405) e configuração inválida (exceção).
- Encoding de URL nomeada, validação de parâmetros e redirects.

### Adicionado
- Snapshot `Request` injetável e parser memoizado de JSON/formulário.
- Limite de body 1 MiB, erros 400/413/415 e override POST de formulário restrito.
- Métodos HEAD/OPTIONS, fallback HEAD e `allowedMethods()`.
- Exceções específicas, rejeição de duplicatas e validação de actions públicas.
- PHPUnit unitário/HTTP, PHPStan nível 6, lint, PSR-12, lock de ferramentas e CI PHP 8.5.
- README e guia de migração com contratos e limitações explícitos.

### Alterado
- Nome proposto `fausantosdev/router`, namespace original preservado; sem `replace`.
- PHP limitado à linha 8.5.x. Sem dependências de pacote em produção.
- Rotas estáticas primeiro; primeira dinâmica vence; duplicatas não sobrescrevem.
- Parâmetros de caminho vencem o body. `data()/current()` são nulos antes de seleção.

Nenhuma versão nova foi publicada. O histórico anterior permanece no Git upstream, incluindo a tag 2.0.2 de 23/04/2022.
