<?php

declare(strict_types=1);

namespace Unical\Titulus;

/** Crea il client di Titulus per una configurazione: il vero è SOAP, nelle prove se ne passa uno finto. */
interface FabbricaClienteTitulus
{
    public function crea(ConfigTitulus $config, string $password): ClienteTitulus;
}
