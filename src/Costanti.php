<?php

declare(strict_types=1);

namespace Unical\Titulus;

/** Valori fissi del collegamento a Titulus (protocollo di Ateneo). */
final class Costanti
{
    /** Verso del documento: come nel connettore dell'Ateneo (arrivo, partenza, tra uffici). */
    public const TIPI_DOCUMENTO = [
        'arrivo' => 'Documento in arrivo',
        'partenza' => 'Documento in partenza',
        'interno' => 'Documento tra uffici',
    ];

    /** Cosa fa Titulus: registra subito il protocollo, oppure salva una bozza e fa partire l'iter di approvazione (voce d'indice). */
    public const AZIONI = [
        'protocolla' => 'Protocolla subito',
        'iter' => 'Salva in bozza e avvia l\'iter di approvazione',
    ];

    /** Esito di un invio, come si legge nel registro. */
    public const ESITO_OK = 'ok';
    public const ESITO_ERRORE = 'errore';
}
