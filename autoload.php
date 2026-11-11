<?php

declare(strict_types=1);

/**
 * Per chi non usa Composer: `require '/percorso/php-titulus-utility/autoload.php';` e le classi del pacchetto (namespace Unical\Titulus)
 * si caricano da sole. Con Composer questo file non serve.
 */
spl_autoload_register(static function (string $classe): void {
    $prefisso = 'Unical\\Titulus\\';
    if (!str_starts_with($classe, $prefisso)) {
        return;
    }
    $file = __DIR__ . '/src/' . str_replace('\\', '/', substr($classe, strlen($prefisso))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});
