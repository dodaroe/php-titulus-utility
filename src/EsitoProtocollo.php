<?php

declare(strict_types=1);

namespace Unical\Titulus;

/** Risultato di un invio a Titulus. In caso di errore il motivo è già scritto per chi lo legge (e registrato nel registro degli invii). */
final class EsitoProtocollo
{
    public function __construct(
        public readonly bool $ok,
        public readonly string $messaggio,
        public readonly string $nrecord = '',
        public readonly string $numProt = '',
        public readonly string $url = '',
        public readonly int $invioId = 0,
    ) {
    }
}
