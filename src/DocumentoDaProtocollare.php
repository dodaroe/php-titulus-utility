<?php

declare(strict_types=1);

namespace Unical\Titulus;

/** Il documento da mandare a Titulus: oggetto, file (il principale e gli altri) e le persone coinvolte. Come lo si classifica lo dicono i ParametriProtocollo. */
final class DocumentoDaProtocollare
{
    /**
     * @param list<AllegatoTitulus> $allegati file oltre al principale
     * @param array{nome?: string, cognome?: string, codice_fiscale?: string, email?: string, telefono?: string, fax?: string, indirizzo?: string} $esterno
     *        chi scrive (arrivo) o a chi si scrive (partenza)
     * @param array{nome: string, uff_nome: string, uff_cod: string}|null $mittenteInterno per i documenti tra uffici
     * @param string $rifTipo tipo e id di ciò a cui il documento appartiene nel tuo programma (es. «pratica», 12): servono solo al registro
     *        degli invii, per non protocollare due volte lo stesso documento
     */
    public function __construct(
        public readonly string $oggetto,
        public readonly AllegatoTitulus $principale,
        public readonly array $allegati = [],
        public readonly array $esterno = [],
        public readonly ?array $mittenteInterno = null,
        public readonly string $rifTipo = '',
        public readonly ?int $rifId = null,
    ) {
    }

    /** Stesso documento con il mittente interno indicato (per i documenti tra uffici). @param array{nome: string, uff_nome: string, uff_cod: string} $mittente */
    public function conMittenteInterno(array $mittente): self
    {
        return new self($this->oggetto, $this->principale, $this->allegati, $this->esterno, $mittente, $this->rifTipo, $this->rifId);
    }
}
