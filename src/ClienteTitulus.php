<?php

declare(strict_types=1);

namespace Unical\Titulus;

use RuntimeException;

/**
 * Le operazioni di Titulus che servono al pacchetto (web service SOAP «Titulus4», come nel connettore Python dell'Ateneo).
 * L'implementazione vera è ClienteSoapTitulus; nelle prove automatiche se ne scrive uno finto che non esce in rete.
 */
interface ClienteTitulus
{
    /**
     * Salva il documento (con i suoi allegati, il primo è il principale): protocollato o in bozza.
     *
     * @param list<AllegatoTitulus> $allegati
     * @return array{nrecord: string, num_prot: string, url: string}
     * @throws RuntimeException se Titulus non risponde o rifiuta il documento
     */
    public function salvaDocumento(string $xmlDocumento, array $allegati, bool $inviaEmail): array;

    /**
     * Mette il documento in un fascicolo. Con $matricolaRpa Titulus opera a nome dell'RPA (setWSUser): serve a chi non è già nel fascicolo.
     *
     * @throws RuntimeException
     */
    public function fascicola(string $xmlFascicolo, string $matricolaRpa): void;

    /**
     * Attributi del documento (num_prot, nrecord, …) dato l'nrecord: serve a leggere il numero di un'iter finito.
     *
     * @return array<string, string>
     * @throws RuntimeException
     */
    public function infoRecord(string $nrecord): array;

    /** Indirizzo per aprire il documento in Titulus (nrecord, numero di protocollo o di repertorio). */
    public function urlDocumento(string $id): ?string;

    /**
     * Controlla che ci si colleghi e che il servizio abbia le operazioni che servono. Non crea nulla in Titulus.
     *
     * @return string cosa si è verificato (una riga)
     * @throws RuntimeException
     */
    public function prova(): string;
}
