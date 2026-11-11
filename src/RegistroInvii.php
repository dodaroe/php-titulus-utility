<?php

declare(strict_types=1);

namespace Unical\Titulus;

/**
 * Dove si ricorda ogni invio a Titulus (riuscito o no). Il pacchetto ne ha due semplici (RegistroInMemoria, RegistroSuFile);
 * chi ha un database scrive la propria implementazione di queste due funzioni.
 */
interface RegistroInvii
{
    /**
     * Registra un invio.
     *
     * @param array{esito: string, oggetto: string, tipo_documento: string, azione: string, nrecord: string, num_prot: string, url: string,
     *              errore: string|null, rif_tipo: string, rif_id: int|null} $riga esito è Costanti::ESITO_OK o ESITO_ERRORE
     * @return int identificativo dell'invio
     */
    public function registra(array $riga): int;

    /** Numero di protocollo già assegnato (con esito ok) a quel documento del tuo programma, null se non ce n'è. */
    public function numeroAssegnato(string $rifTipo, ?int $rifId): ?string;
}
