# Migração de CoffeeCode Router 2.0.2

Esta é uma proposta de nova major, não um patch compatível em todos os comportamentos. A branch de revisão não foi enviada ao GitHub.

## Checklist

1. Use PHP **8.5.x**. PHP 8.2–8.4 e PHP 8.6 não estão no contrato desta continuação.
2. Remova `coffeecode/router` antes de instalar esta implementação. O nome proposto é `fausantosdev/router`, ainda não publicado; ambos expõem `CoffeeCode\Router` e não devem coexistir.
3. Preserve o rewrite para `?route=` ou injete `Request`. Não houve migração automática para `REQUEST_URI`.
4. Revise as rotas concorrentes: estáticas vencem; entre dinâmicas, vence a **primeira** cadastrada, não a última.
5. Elimine rotas/verbo e nomes duplicados, placeholders inválidos ou repetidos. Agora lançam `ConfigurationException`.
6. Revise permissões: middlewares do grupo executam **antes e junto** dos middlewares da rota. A rota não pode remover a guarda do grupo. `group()` ainda redefine o grupo corrente, sem herança automática de subgrupos.
7. Não use `data()` antes de despachar para ler o body: agora retorna `null` até a seleção. Para o snapshot, use `request()->payload()`. Depois do match, `data()` e o handler recebem o mesmo array; parâmetros de caminho vencem colisões com body.
8. Envie Content-Type correto. JSON deve ter objeto na raiz. JSON inválido vira 400; tipo não suportado vira 415; body acima de 1 MiB vira 413. Multipart é suportado apenas em POST pelo SAPI.
9. `_method` altera somente POST de formulário para PUT/PATCH/DELETE, em maiúsculas. Em JSON é dado comum; em outros verbos não altera o método.
10. Classes/actions/middlewares inválidos lançam `ConfigurationException`, não 400/405/501. A aplicação deve registrar a falha e emitir uma resposta 500 sem expor detalhes ao cliente. Exceções da aplicação continuam propagando.
11. `current()` antes de selecionar uma rota retorna `null`; os metadados refletem a rota selecionada, não o último grupo cadastrado.
12. URLs nomeadas exigem todos os parâmetros **obrigatórios**, escalares não vazios. Parâmetros `{name?}` ausentes ou `null` omitem seu segmento; opcionais posteriores não podem ser fornecidos sem os anteriores. Valores fornecidos são codificados por segmento e query RFC3986. Não pré-codifique valores; `.`/`..` não são aceitos como parâmetro inteiro.
13. Declare propriedades nos controllers (incluindo `$router`); actions devem ser públicas, de instância e não mágicas. Callables continuam aceitos.
14. Revise HEAD/OPTIONS. HEAD explícito ou fallback GET descarta output. OPTIONS automático emite 204/Allow sem executar middleware, para caminhos conhecidos; não é autorização nem implementação de CORS.
15. Revise o front controller: aplique `error()` como status, emita `Allow` para 405 e não transforme negação de middleware (`false` sem erro) em 404.

## Tratamento de erros

```php
try {
    $executed = $router->dispatch();
    if (!$executed && $router->error() !== null) {
        http_response_code($router->error());
        if ($router->error() === 405) {
            header('Allow: ' . implode(', ', $router->allowedMethods()));
        }
    }
} catch (\CoffeeCode\Router\ConfigurationException $exception) {
    error_log($exception->getMessage());
    http_response_code(500);
}
```

O middleware que nega acesso deve definir o status/corpo apropriado. A biblioteca não inventa uma política 401/403.

## Redirects e entradas

`redirect()` mantém resolução de nome/caminho/URL e encerramento da execução; aceita status explícito (default 302). Use 303 depois de POST quando apropriado. Targets com controles, barra invertida ou `//` e esquemas não HTTP(S) são rejeitados. URLs externas continuam permitidas: use allowlist se o target vier do usuário.

Capturas não são decodificadas internamente; preserve/valide o contrato de encoding do seu servidor. A URL base deve ser configurada e usar HTTP(S), sem credenciais, query ou fragmento. Não a derive de Host não confiável em produção.

## Antes de aprovar

### Atualização incremental: opcionais

É possível trocar `/users/{id}` por `/users/{id?}` para aceitar também `/users`. O callback recebe `['id' => null]` quando ausente. Não é necessário alterar rotas obrigatórias existentes. Opcionais são segmentos completos consecutivos no fim da rota; configurações ambíguas ou no meio são rejeitadas. Rotas estáticas continuam tendo prioridade e dinâmicas mantêm a ordem de cadastro. Um parâmetro opcional ausente também prevalece como `null` sobre um campo homônimo no body.

Execute `composer install && composer check`, rode um projeto real em staging e revise o diff do bundle. Apache, Nginx/FPM, workers persistentes e uploads reais precisam de validação no seu ambiente. Não foi criado release, tag ou entrada Packagist.
