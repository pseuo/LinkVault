<?php

declare(strict_types=1);

/** @return Router */
return (static function (bool $customDomain): Router {
    $router = new Router();
    $router->add('operations', '#^/(?:livez|readyz|healthz|metrics)$#D', ['GET', 'HEAD']);

    foreach (fixed_routes() as $path => $definition) {
        if ($definition['scope'] !== 'api') {
            continue;
        }
        $router->add('api', '#^' . preg_quote($path, '#') . '$#D', $definition['methods'], ['secure', 'database', 'api-auth']);
    }
    $router->add('api', '#^/api/links/[1-9][0-9]*$#D', ['GET', 'PATCH', 'DELETE'], ['secure', 'database', 'api-auth']);
    $router->add('api', '#^/api/links/[1-9][0-9]*/disable$#D', ['POST'], ['secure', 'database', 'api-auth']);
    $router->add('api', '#^/api(?:/.*)?$#D', ['GET', 'POST', 'PATCH', 'DELETE'], ['secure', 'database', 'api-auth']);

    $router->add('public-confirm', '#^/(?<slug>[A-Za-z0-9_-]{3,64})/confirm$#D', ['POST'], ['secure', 'database', 'session']);
    $router->add('public-unlock', '#^/(?<slug>[A-Za-z0-9_-]{3,64})/unlock$#D', ['POST'], ['secure', 'database', 'session']);

    if (!$customDomain) {
        $router->add('public-report', '#^/report$#D', ['GET', 'POST'], ['secure', 'database', 'session']);
        $router->add('public-privacy', '#^/privacy$#D', ['GET'], ['secure', 'database', 'session']);
        $router->add('browser-extension-privacy', '#^/browser-extension-privacy$#D', ['GET'], ['secure', 'database', 'session']);
    }

    if (!$customDomain) {
        foreach (fixed_routes() as $path => $definition) {
            if ($definition['scope'] !== 'admin') {
                continue;
            }
            $middleware = ['secure', 'database', 'session'];
            if (!in_array($path, ['/', '/login'], true)) {
                $middleware[] = 'admin-auth';
            }
            $router->add('admin', '#^' . preg_quote($path, '#') . '$#D', $definition['methods'], $middleware);
        }
    }

    $router->add('public-redirect', '#^/.*$#D', ['GET', 'HEAD'], ['secure', 'database']);
    return $router;
})(current_short_domain() !== null);
