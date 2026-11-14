<?php

declare(strict_types=1);

namespace Unical\Titulus;

use RuntimeException;
use Throwable;

/**
 * Il punto d'ingresso del pacchetto: manda un documento a Titulus (porta di services.py del connettore Python: protocolla_arrivo,
 * protocolla_partenza, protocolla_interno e le versioni «avvia iter»).
 *
 * I metodi che inviano non lanciano mai eccezioni: restituiscono un EsitoProtocollo con il motivo già scritto in italiano, e se c'è un
 * RegistroInvii ogni invio, riuscito o no, vi viene annotato.
 *
 *   $titulus = new ServizioTitulus(ConfigTitulus::daAmbiente(), getenv('TITULUS_PASSWORD'));
 *   $esito = $titulus->protocollaArrivo($documento, $parametri);
 */
final class ServizioTitulus
{
    private FabbricaClienteTitulus $fabbrica;
    private GeneratoreXml $xml;
    private ?ClienteTitulus $cliente = null;

    public function __construct(
        private ConfigTitulus $config,
        private string $password,
        ?FabbricaClienteTitulus $fabbrica = null,
        private ?RegistroInvii $registro = null,
        ?GeneratoreXml $xml = null,
    ) {
        $this->fabbrica = $fabbrica ?? new FabbricaClienteSoap();
        $this->xml = $xml ?? new GeneratoreXml();
    }

    public function protocollaArrivo(DocumentoDaProtocollare $d, ParametriProtocollo $p): EsitoProtocollo
    {
        return $this->protocolla($d, $p->con('arrivo', 'protocolla'));
    }

    public function protocollaPartenza(DocumentoDaProtocollare $d, ParametriProtocollo $p): EsitoProtocollo
    {
        return $this->protocolla($d, $p->con('partenza', 'protocolla'));
    }

    public function protocollaInterno(DocumentoDaProtocollare $d, ParametriProtocollo $p): EsitoProtocollo
    {
        return $this->protocolla($d, $p->con('interno', 'protocolla'));
    }

    /** Salva la bozza e fa partire l'iter di approvazione (il verso resta quello dei parametri; serve la voce d'indice). */
    public function avviaIter(DocumentoDaProtocollare $d, ParametriProtocollo $p): EsitoProtocollo
    {
        return $this->protocolla($d, $p->con($p->tipo, 'iter'));
    }

    /** Il collegamento è completo? (testo del problema, null se si può inviare) */
    public function nonPronto(): ?string
    {
        if (!$this->config->attivo) {
            return 'Il collegamento a Titulus non è attivo.';
        }
        if ($this->password === '') {
            return 'Manca la password di Titulus.';
        }

        return $this->config->incompleta();
    }

    /**
     * Invia il documento come dicono i parametri (verso e azione compresi).
     */
    public function protocolla(DocumentoDaProtocollare $d, ParametriProtocollo $p): EsitoProtocollo
    {
        try {
            // Documento tra uffici senza mittente indicato: si usa l'ufficio dei parametri (e il suo RPA)
            if ($p->tipo === 'interno' && $d->mittenteInterno === null && trim($p->uo) !== '') {
                $d = $d->conMittenteInterno([
                    'nome' => trim($p->rpaNome) !== '' ? $p->rpaNome : $this->autore(),
                    'uff_nome' => $p->nomeUfficio(),
                    'uff_cod' => $p->uo,
                ]);
            }
            $errore = $this->nonPronto() ?? $p->controlla($d);
            if ($errore !== null) {
                return $this->fallito($d, $p, $errore);
            }

            $bozza = $p->azione === 'iter';
            $allegati = [$d->principale, ...$d->allegati];
            $esterno = $d->esterno + ['nome' => '', 'cognome' => '', 'codice_fiscale' => '', 'email' => '', 'telefono' => '', 'fax' => '', 'indirizzo' => ''];

            $xml = $this->xml->documento([
                'tipo' => $p->tipo,
                'bozza' => $bozza,
                'aoo' => $this->config->aoo,
                'autore' => $this->autore(),
                // Come nel connettore: oggetto in maiuscolo e lungo almeno 20 caratteri
                'oggetto' => sprintf('%-20s', mb_strtoupper(trim($d->oggetto))),
                'num_allegati' => count($allegati),
                'cod_repertorio' => $p->codRepertorio !== '' ? $p->codRepertorio : null,
                'voce_indice' => $p->voceIndice !== '' ? $p->voceIndice : null,
                'cod_titolario' => $p->codTitolario,
                'classif' => '',
                'rpa_nome' => $p->rpaNome,
                'rpa_matricola' => $p->rpaMatricola,
                'uo' => $p->uo,
                'uo_nome' => $p->nomeUfficio(),
                'op' => $p->op !== null && ($p->op['uo'] ?? '') !== '' ? $this->conNomeUfficio($p->op) : null,
                'cc' => array_map(fn (array $c): array => $this->conNomeUfficio($c), $p->cc),
                'esterno' => [
                    'nome' => trim($esterno['nome'] . ' ' . $esterno['cognome']),
                    'codice_fiscale' => $esterno['codice_fiscale'], 'email' => $esterno['email'], 'telefono' => $esterno['telefono'],
                    'fax' => $esterno['fax'], 'indirizzo' => $esterno['indirizzo'],
                ],
                'mittente_interno' => $d->mittenteInterno,
                'notifica' => $p->notifica,
                'collegato_a' => $p->collegatoA !== '' ? $p->collegatoA : null,
            ]);

            $cliente = $this->cliente();
            $salvato = $cliente->salvaDocumento($xml, $allegati, $p->inviaEmailRpa);
            if ($bozza && $salvato['nrecord'] === '') {
                return $this->fallito($d, $p, 'Titulus ha salvato la bozza ma non ha restituito il numero di record: controllala in Titulus.');
            }
            if (!$bozza && $salvato['num_prot'] === '') {
                return $this->fallito($d, $p, 'Titulus non ha assegnato il numero di protocollo: controlla in Titulus se il documento è stato registrato.');
            }

            // Fascicolazione separata: un eventuale rifiuto non annulla il protocollo già assegnato, ma si segnala
            $nota = '';
            if ($this->config->fascicolazioneSeparata && $p->fascicoloNumero !== '') {
                try {
                    $cliente->fascicola($this->xml->fascicolo($p->fascicoloNumero, $salvato['nrecord'], $salvato['num_prot']), $p->rpaMatricola);
                } catch (Throwable $e) {
                    $nota = ' Il documento NON è stato inserito nel fascicolo ' . $p->fascicoloNumero . ': ' . $e->getMessage();
                }
            }

            $msg = ($bozza ? 'Bozza salvata in Titulus (record ' . $salvato['nrecord'] . '): l\'iter di approvazione è partito.' : 'Protocollato in Titulus: ' . $salvato['num_prot'] . '.') . $nota;
            $id = $this->annota($d, $p, Costanti::ESITO_OK, $salvato['nrecord'], $salvato['num_prot'], $salvato['url'], $nota !== '' ? trim($nota) : null);

            return new EsitoProtocollo(true, $msg, $salvato['nrecord'], $salvato['num_prot'], $salvato['url'], $id);
        } catch (Throwable $e) {
            return $this->fallito($d, $p, $e->getMessage());
        }
    }

    /**
     * Il numero di protocollo di un documento, senza mai protocollare due volte: se per quel riferimento (rifTipo, rifId) il registro ha già
     * un numero lo restituisce, altrimenti protocolla. Per l'iter il numero non c'è subito: l'esito lo spiega.
     *
     * @return array{numero: string, errore: string|null}
     */
    public function numero(DocumentoDaProtocollare $d, ParametriProtocollo $p): array
    {
        if ($this->registro !== null && $d->rifTipo !== '') {
            $gia = $this->registro->numeroAssegnato($d->rifTipo, $d->rifId);
            if ($gia !== null) {
                return ['numero' => $gia, 'errore' => null];
            }
        }
        $esito = $this->protocolla($d, $p);
        if (!$esito->ok) {
            return ['numero' => '', 'errore' => 'Titulus: ' . $esito->messaggio];
        }
        if ($esito->numProt === '') {
            return ['numero' => '', 'errore' => 'È stato avviato l\'iter di approvazione: il numero di protocollo non c\'è ancora (' . $esito->messaggio . ')'];
        }

        return ['numero' => $esito->numProt, 'errore' => null];
    }

    /**
     * Controlla il collegamento senza creare nulla in Titulus (carica il WSDL, verifica le operazioni e le credenziali).
     *
     * @return array{ok: bool, messaggio: string}
     */
    public function provaConnessione(): array
    {
        try {
            if (($manca = $this->nonPronto()) !== null) {
                throw new RuntimeException($manca);
            }

            return ['ok' => true, 'messaggio' => $this->cliente()->prova()];
        } catch (Throwable $e) {
            return ['ok' => false, 'messaggio' => $e->getMessage()];
        }
    }

    /**
     * Un'iter ha dato solo il record: quando in Titulus è concluso, qui si legge il numero di protocollo.
     *
     * @return array{ok: bool, messaggio: string, num_prot: string, url: string}
     */
    public function leggiNumero(string $nrecord): array
    {
        try {
            if (trim($nrecord) === '') {
                throw new RuntimeException('Manca il numero di record.');
            }
            if (($manca = $this->nonPronto()) !== null) {
                throw new RuntimeException($manca);
            }
            $cliente = $this->cliente();
            $num = (string) ($cliente->infoRecord($nrecord)['num_prot'] ?? '');
            if ($num === '') {
                return ['ok' => false, 'messaggio' => 'L\'iter non è ancora concluso: il documento non ha il numero di protocollo.', 'num_prot' => '', 'url' => ''];
            }

            return ['ok' => true, 'messaggio' => "Protocollo assegnato: $num.", 'num_prot' => $num, 'url' => (string) ($cliente->urlDocumento($num) ?? '')];
        } catch (Throwable $e) {
            return ['ok' => false, 'messaggio' => $e->getMessage(), 'num_prot' => '', 'url' => ''];
        }
    }

    private function cliente(): ClienteTitulus
    {
        return $this->cliente ??= $this->fabbrica->crea($this->config, $this->password);
    }

    private function autore(): string
    {
        return $this->config->autore !== '' ? $this->config->autore : 'Titulus PHP Utility';
    }

    /**
     * @param array{uo: string, nome: string, matricola: string, uo_nome?: string} $r
     * @return array{uo: string, nome: string, matricola: string, uo_nome: string}
     */
    private function conNomeUfficio(array $r): array
    {
        return [
            'uo' => (string) $r['uo'], 'nome' => (string) $r['nome'], 'matricola' => (string) ($r['matricola'] ?? ''),
            'uo_nome' => (string) (($r['uo_nome'] ?? '') !== '' ? $r['uo_nome'] : (CatalogoTitulus::UFFICI[(string) $r['uo']] ?? (string) $r['uo'])),
        ];
    }

    private function fallito(DocumentoDaProtocollare $d, ParametriProtocollo $p, string $motivo): EsitoProtocollo
    {
        try {
            $id = $this->annota($d, $p, Costanti::ESITO_ERRORE, '', '', '', mb_substr($motivo, 0, 2000));
        } catch (Throwable) {
            $id = 0;
        }

        return new EsitoProtocollo(false, $motivo, invioId: $id);
    }

    private function annota(DocumentoDaProtocollare $d, ParametriProtocollo $p, string $esito, string $nrecord, string $numProt, string $url, ?string $errore): int
    {
        if ($this->registro === null) {
            return 0;
        }

        return $this->registro->registra([
            'esito' => $esito, 'oggetto' => $d->oggetto, 'tipo_documento' => $p->tipo, 'azione' => $p->azione, 'nrecord' => $nrecord,
            'num_prot' => $numProt, 'url' => $url, 'errore' => $errore, 'rif_tipo' => $d->rifTipo, 'rif_id' => $d->rifId,
        ]);
    }
}
