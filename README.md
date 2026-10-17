# Router — continuação independente para PHP 8.5

Fork de [CoffeeCode Router, por Robson V. Leite e UpInside](https://github.com/robsonvleite/router).
Mantém a licença MIT e os créditos originais. **Esta branch é uma proposta de nova major em revisão: não foi publicada no Packagist e não é uma release oficial CoffeeCode.**

Uma biblioteca pequena para cadastrar, nomear, agrupar e executar rotas. Sem container, descoberta por attributes ou dependências de runtime além de PHP e suas extensões filter/json. Testada em PHP 8.5.11; política de suporte: **somente PHP 8.5.x** (`~8.5.0`).

## Revisar localmente

As alterações podem estar apenas no ZIP/bundle de revisão, não no fork remoto. Entre no diretório extraído:

```sh
composer install
composer check
php -S 127.0.0.1:8080 examples/basic/index.php
curl 'http://127.0.0.1:8080/?route=/hello/Ana'
```

`composer check` executa lint, PHPUnit (unitário e HTTP), PHPStan nível 6 e PSR-12.
As extensões exigidas pelas ferramentas de desenvolvimento incluem ctype, DOM, libxml, mbstring, SimpleXML, tokenizer, XML e XMLWriter. Os testes HTTP usam sockets e `proc_open` para iniciar um servidor PHP local, encerrado ao fim da suite.

## Uso

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use CoffeeCode\Router\Router;

$router = new Router('https://example.com'); // URL configurada, não HTTP_HOST não confiável
$router->get('/hello/{name}', function (array $data, Router $router): void {
    header('Content-Type: application/json');
    echo json_encode(['hello' => $data['name']], JSON_THROW_ON_ERROR);
}, 'hello');

$router->dispatch();
if ($router->error() !== null) {
    http_response_code($router->error());
    if ($router->error() === 405) {
        header('Allow: ' . implode(', ', $router->allowedMethods()));
    }
}
```

## Caminho e servidor

Por compatibilidade, o adaptador padrão captura `$_GET['route']`, ou `/` quando ausente. Ele **não passa a usar `REQUEST_URI` silenciosamente**. Método, body e formulário são capturados uma única vez, quando o Router é construído. Crie uma instância por requisição; não a compartilhe em workers persistentes.

Apache, no diretório do front controller:

```apache
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^(.*)$ index.php?route=/$1 [L,QSA,B]
```

Nginx, exemplo para uma aplicação na raiz:

```nginx
location / {
    try_files $uri $uri/ @router;
}
location @router {
    rewrite ^(.*)$ /index.php?route=$1 last;
}
```

Configure separadamente o bloco PHP/FastCGI e a política de query string, assets, HTTPS, host e proxy. Esses exemplos não substituem configuração de produção e não foram executados no Nginx/Apache durante esta revisão. Em subdiretórios, ajuste prefixos/rewrite e a URL base. `route` é uma chave reservada da integração: evite permitir que parâmetros externos sobrescrevam o caminho reescrito.

Para testes ou um adaptador próprio, injete uma requisição:

```php
use CoffeeCode\Router\Request;

$request = new Request('GET', '/hello/Ana');
$router = new Router('https://example.com/base', request: $request);
```

O caminho deve começar com `/`, sem controles, query ou fragmento; trailing slash é normalizado. Literais são case-sensitive. Capturas de segmentos são preservadas **como recebidas pelo adaptador**: não há `rawurldecode` interno. Isso evita decodificar separadores duas vezes, mas não garante que Apache/Nginx preservem `%2F`. Se precisar interpretar valores encoded, decodifique uma vez na fronteira da aplicação e valide o resultado.

## Rotas e controllers

Disponíveis: `get()`, `post()`, `put()`, `patch()`, `delete()`, `head()` e `options()`, todos com `(string $route, callable|string $handler, ?string $name = null, array|string|null $middleware = null)` e retorno `void`.

```php
$router->namespace('App\\Controller');
$router->get('/users/{id}', 'User:show', 'user.show');
$router->group('admin');
$router->get('/users/{id}', 'User:show');
$router->group(null)->namespace(null);
```

Controllers são instanciados com `$router`; a action recebe o array de dados. Actions devem ser públicas, não estáticas e não mágicas. Nome com `\` inicial é absoluto; os demais recebem o namespace configurado. Separador customizado continua disponível no segundo argumento do construtor.

Closures e outros callables recebem `($data, $router)`. Configurações inválidas lançam `ConfigurationException`; exceções da aplicação não são mascaradas como 404. Uma action ausente é erro de configuração, não método HTTP inválido.

**Prioridade:** rotas inteiramente estáticas antes das dinâmicas; entre rotas dinâmicas concorrentes, vence a primeira cadastrada. Não há prioridade por quantidade de segmentos estáticos. Rotas iguais com o mesmo verbo e nomes repetidos são rejeitados, não sobrescritos. Placeholders aceitam `{id}` e nomes com letras, números, `_` ou `-`, iniciando por letra/`_`; nomes devem ser únicos na rota. Não há regex customizada, parâmetros opcionais ou catch-all nesta major proposta.

## Dados e body

`data()` e `current()->data` refletem a rota selecionada e são iguais aos dados do handler. Antes de selecionar uma rota, `data()` e `current()` retornam `null`. Metadados de grupo/namespace são capturados no cadastro, não no último grupo configurado.

Dados de body são combinados com parâmetros de caminho; **o caminho vence em colisões**. Query string não é mesclada automaticamente. `request()` oferece o snapshot original.

| Content-Type | Comportamento |
|---|---|
| `application/json` / `application/*+json` | Objeto JSON; arrays/scalars na raiz e JSON inválido produzem 400 |
| `application/x-www-form-urlencoded` | Formulário POST ou parse do body PUT/PATCH/DELETE |
| `multipart/form-data` | Somente POST, usando dados do SAPI; arquivos permanecem em `$_FILES` |
| Body não vazio com tipo não suportado/ausente | 415, salvo formulário POST já reconhecido pelo SAPI |
| Body vazio | Array vazio; multipart não POST continua não suportado |

Body limitado a **1 MiB** nesta implementação; acima disso, 413. Configure também limites de servidor/PHP (`post_max_size`, `upload_max_filesize`, `max_input_vars`) para coerência, especialmente em multipart. A biblioteca não é um gerenciador de uploads. O parser de JSON/formulário é lazy e memoizado, não repetido no cadastro de rotas.

`_method=PUT|PATCH|DELETE` funciona **somente em POST de formulário**, em maiúsculas; é removido dos dados do handler nesses formulários. `_method` em JSON é dado comum e não altera o verbo. CSRF, autenticação e validação são responsabilidade da aplicação.

## Middlewares

```php
class Auth
{
    public function handle(Router $router): bool
    {
        if (!isset($_SESSION['user'])) {
            http_response_code(403);
            return false;
        }
        return true;
    }
}

$router->group('admin', Auth::class);
$router->get('/users/{id}', 'User:show', middleware: Audit::class);
```

São instanciados sem argumentos e devem expor `handle(Router): bool`. Executam **grupo primeiro, depois rota**, na ordem fornecida, sem deduplicação. `false` interrompe a cadeia e o handler, mas não preenche `error()`: o middleware define sua resposta. Middleware da rota não remove a proteção do grupo. Não há middleware global, herança automática de subgrupos nem pipeline PSR-15. Chamar `group()` redefine explicitamente prefixo e middlewares do grupo corrente.

## HTTP e redirects

- `dispatch(): bool`: executou/resolveu a requisição ou não. `false` também pode significar negação do middleware, com `error() === null`.
- Nenhum caminho encontrado: 404 em `error()`. Caminho encontrado, verbo não registrado: 405 e `allowedMethods()`.
- Esses erros **não emitem automaticamente cabeçalhos/status**; o front controller deve aplicá-los.
- HEAD usa rota HEAD explícita ou fallback GET e descarta output do handler/middleware. HEAD não deve executar operações com efeito colateral; respostas que terminam com `exit` ou manipulam buffers exigem disciplina da aplicação.
- OPTIONS explícito executa a rota cadastrada. Sem rota OPTIONS e com caminho conhecido, emite `204` e `Allow`, sem executar middleware/handler. Não equivale a autorização nem habilita CORS.
- `redirect($route, $data = null, $status = 302): never`: resolve nome, caminho interno ou URL HTTP(S), envia Location e encerra. Aceita 301/302/303/307/308; rejeita controles, barra invertida, URL de esquema não HTTP e target `//...`. URLs externas são permitidas deliberadamente: não passe entrada do usuário sem allowlist.

## URLs nomeadas

```php
$router->get('/users/{id}', 'User:show', 'user.show');
$url = $router->route('user.show', ['id' => 'a/b', 'q' => 'hello world']);
// https://example.com/users/a%2Fb?q=hello%20world
```

Nome inexistente retorna `null`. Parâmetros obrigatórios ausentes, não escalares, vazios ou `.`/`..` lançam `ConfigurationException`. Segmentos usam `rawurlencode`; extras viram query RFC3986. Não envie valores previamente codificados se não quiser dupla codificação.

## Migração e manutenção

Leia [MIGRATION.md](MIGRATION.md), [CHANGELOG.md](CHANGELOG.md) e [CONTRIBUTING.md](CONTRIBUTING.md). O nome proposto no Composer é `fausantosdev/router`; o namespace continua `CoffeeCode\Router`. Não existe `replace` do pacote original; **não instale os dois simultaneamente**, pois exportam as mesmas classes.

O workflow verifica PHP 8.5.0 e o último patch 8.5 disponível. Essa matriz é configuração para execução futura; a verificação local desta revisão foi no PHP 8.5.11. Nenhuma tag/release foi criada. MIT original preservada; não há garantia de compatibilidade com PHP 8.6 nem com projetos que dependem da semântica antiga.