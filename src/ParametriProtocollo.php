<?php

declare(strict_types=1);

namespace Unical\Titulus;

/**
 * Come Titulus deve registrare il documento: verso, ufficio, classificazione, responsabile (RPA), eventuale fascicolo, voce d'indice per
 * l'iter. Sono le stesse informazioni che il connettore Python legge dalla sua configurazione (ConfigurationWSProtocollo*).
 * I codici di ufficio e titolario validi sono in CatalogoTitulus::UFFICI e CatalogoTitulus::TITOLARIO.
 */
final class ParametriProtocollo
{
    /**
     * @param string $tipo arrivo | partenza | interno (Costanti::TIPI_DOCUMENTO)
     * @param string $azione protocolla (numero subito) | iter (bozza e avvio dell'iter, serve $voceIndice) (Costanti::AZIONI)
     * @param string $uo codice dell'ufficio dell'RPA (es. «2014.2»)
     * @param string $uoNome nome dell'ufficio; se vuoto si cerca in CatalogoTitulus::UFFICI
     * @param string $codTitolario codice del titolario (es. «5/1»)
     * @param array{uo: string, nome: string, matricola: string, uo_nome?: string}|null $op operatore di protocollo
     * @param list<array{uo: string, nome: string, matricola: string, uo_nome?: string}> $cc destinatari per conoscenza
     * @param array{url: string, label: string, metodo: string, auth: string}|null $notifica webhook che Titulus chiama alla protocollazione
     * @param string $collegatoA href di un documento già in Titulus a cui collegare questo
     */
    public function __construct(
        public readonly string $tipo,
        public readonly string $uo,
        public readonly string $codTitolario,
        public readonly string $rpaNome,
        public readonly string $azione = 'protocolla',
        public readonly string $uoNome = '',
        public readonly string $rpaMatricola = '',
        public readonly string $voceIndice = '',
        public readonly string $codRepertorio = '',
        public readonly string $fascicoloNumero = '',
        public readonly bool $inviaEmailRpa = false,
        public readonly ?array $op = null,
        public readonly array $cc = [],
        public readonly ?array $notifica = null,
        public readonly string $collegatoA = '',
    ) {
    }

    /** Stessi parametri con un altro verso e/o un'altra azione (usato da protocollaArrivo, protocollaPartenza, avviaIter…). */
    public function con(string $tipo, string $azione): self
    {
        return new self($tipo, $this->uo, $this->codTitolario, $this->rpaNome, $azione, $this->uoNome, $this->rpaMatricola, $this->voceIndice,
            $this->codRepertorio, $this->fascicoloNumero, $this->inviaEmailRpa, $this->op, $this->cc, $this->notifica, $this->collegatoA);
    }

    /** Il nome dell'ufficio: quello indicato, oppure quello del catalogo, oppure il codice. */
    public function nomeUfficio(): string
    {
        return $this->uoNome !== '' ? $this->uoNome : (CatalogoTitulus::UFFICI[$this->uo] ?? $this->uo);
    }

    /**
     * I controlli che il connettore fa prima di inviare. Testo del primo problema, null se va bene.
     * Non controlla gli elenchi del catalogo (l'Ateneo può aggiungere voci prima che il catalogo si aggiorni).
     */
    public function controlla(DocumentoDaProtocollare $d): ?string
    {
        if (!isset(Costanti::TIPI_DOCUMENTO[$this->tipo])) {
            return 'Tipo di documento non valido: «' . $this->tipo . '» (usa arrivo, partenza o interno).';
        }
        if (!isset(Costanti::AZIONI[$this->azione])) {
            return 'Azione non valida: «' . $this->azione . '» (usa protocolla o iter).';
        }
        if (trim($d->oggetto) === '') {
            return 'Manca l\'oggetto del documento.';
        }
        if (trim($this->uo) === '' || trim($this->codTitolario) === '') {
            return 'Mancano l\'UO o il codice del titolario.';
        }
        if ($this->fascicoloNumero !== '' && trim($this->rpaMatricola) === '') {
            return 'È indicato un fascicolo ma non la matricola dell\'RPA, che serve per inserire il documento.';
        }
        if ($this->azione === 'iter' && trim($this->voceIndice) === '') {
            return 'Per avviare l\'iter serve la voce d\'indice.';
        }
        $esterno = $d->esterno + ['nome' => '', 'cognome' => '', 'codice_fiscale' => ''];
        if ($this->tipo === 'interno') {
            $m = $d->mittenteInterno;
            if ($m === null || trim($m['nome']) === '' || trim($m['uff_nome']) === '' || trim($m['uff_cod']) === '') {
                return 'Per un documento tra uffici servono nome, ufficio e codice dell\'ufficio del mittente.';
            }
        } elseif (trim($esterno['nome'] . $esterno['cognome']) === '') {
            return $this->tipo === 'arrivo' ? 'Manca il nome di chi scrive.' : 'Manca il nome del destinatario.';
        } elseif ($this->tipo === 'partenza' && trim($esterno['codice_fiscale']) === '') {
            return 'Per un documento in partenza serve il codice fiscale del destinatario.';
        }

        return null;
    }
}
