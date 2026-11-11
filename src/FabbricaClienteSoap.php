<?php

declare(strict_types=1);

namespace Unical\Titulus;

final class FabbricaClienteSoap implements FabbricaClienteTitulus
{
    public function crea(ConfigTitulus $config, string $password): ClienteTitulus
    {
        return new ClienteSoapTitulus($config, $password);
    }
}
