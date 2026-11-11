<?php

declare(strict_types=1);

namespace Unical\Titulus;

use RuntimeException;
use SimpleXMLElement;
use SoapClient;
use SoapFault;
use Throwable;

/**
 * Web service SOAP di Titulus con il SoapClient di PHP (estensione «soap»). Corrisponde a WSTitulusClient del connettore Python
 * (Zeep): stesse operazioni (saveDocument, addInFolder, setWSUser, getRecordInfos, getDocumentURL), autenticazione HTTP Basic.
 * Il WSDL è letto una volta sola e la password non compare mai nei messaggi d'errore.
 */
final class ClienteSoapTitulus implements ClienteTitulus
{
    private ?SoapClient $soap = null;

    public function __construct(private ConfigTitulus $config, private string $password, private int $timeout = 60)
    {
    }

    private function soap(): SoapClient
    {
        if ($this->soap !== null) {
            return $this->soap;
        }
        if (!extension_loaded('soap')) {
            throw new RuntimeException("Sul server manca l'estensione PHP «soap»: va attivata (extension=soap in php.ini) per collegarsi a Titulus.");
        }
        $auth = 'Basic ' . base64_encode($this->config->username . ':' . $this->password);
        $contesto = stream_context_create(['http' => ['header' => "Authorization: $auth\r\n", 'timeout' => $this->timeout]]);
        try {
            $this->soap = new SoapClient($this->config->urlWs, [
                'login' => $this->config->username,
                'password' => $this->password,
                'authentication' => SOAP_AUTHENTICATION_BASIC,
                'stream_context' => $contesto,
                'connection_timeout' => 15,
                'exceptions' => true,
                'trace' => false,
                'cache_wsdl' => WSDL_CACHE_MEMORY,
                'features' => SOAP_SINGLE_ELEMENT_ARRAYS,
            ]);
        } catch (Throwable $e) {
            throw new RuntimeException('Collegamento a Titulus non riuscito: ' . $this->senzaSegreti($e->getMessage()), 0, $e);
        }

        return $this->soap;
    }

    public function salvaDocumento(string $xmlDocumento, array $allegati, bool $inviaEmail): array
    {
        $beans = [];
        foreach ($allegati as $a) {
            $beans[] = ['content' => $a->contenuto, 'fileName' => $a->nomeFile, 'description' => $a->descrizione];
        }
        $r = $this->chiama('saveDocument', [
            'document' => $xmlDocumento,
            'attachmentBeans' => $beans,
            'params' => ['pdfConversion' => true, 'sendEMail' => $inviaEmail],
        ]);
        $xml = $this->leggiXml($r);
        $doc = $xml->xpath('//doc');
        $url = $xml->xpath('//url');
        $attr = $doc ? $doc[0]->attributes() : null;

        return [
            'nrecord' => $attr !== null ? (string) ($attr['nrecord'] ?? '') : '',
            'num_prot' => $attr !== null ? (string) ($attr['num_prot'] ?? '') : '',
            'url' => $url ? trim((string) $url[0]) : '',
        ];
    }

    public function fascicola(string $xmlFascicolo, string $matricolaRpa): void
    {
        if ($matricolaRpa !== '') {
            $this->chiama('setWSUser', ['user' => $matricolaRpa, 'pnumber' => $matricolaRpa]);
        }
        $this->chiama('addInFolder', ['fascicolo' => $xmlFascicolo]);
    }

    public function infoRecord(string $nrecord): array
    {
        $xml = $this->leggiXml($this->chiama('getRecordInfos', ['id' => $nrecord]));
        $doc = $xml->xpath('//doc');
        $out = [];
        if ($doc) {
            foreach ($doc[0]->attributes() ?? [] as $k => $v) {
                $out[(string) $k] = (string) $v;
            }
        }

        return $out;
    }

    public function urlDocumento(string $id): ?string
    {
        $r = $this->chiama('getDocumentURL', ['id' => $id]);
        $s = is_string($r) ? $r : (is_object($r) ? (string) ($r->any ?? $r->_ ?? '') : '');

        return $s !== '' ? trim($s) : null;
    }

    public function prova(): string
    {
        $funzioni = array_map(static fn (string $f): string => (string) preg_replace('/^\S+\s+(\w+)\(.*$/', '$1', $f), $this->soap()->__getFunctions() ?? []);
        $mancanti = array_diff(['saveDocument', 'addInFolder', 'getRecordInfos', 'getDocumentURL'], $funzioni);
        if ($mancanti) {
            throw new RuntimeException('Il servizio risponde ma non ha le operazioni attese (' . implode(', ', $mancanti) . '): controlla l\'indirizzo del WSDL.');
        }

        // Il WSDL può essere pubblico: per controllare davvero utente e password si fa una lettura che non crea nulla.
        // Un errore «documento inesistente» significa che l'accesso è stato accettato; solo il rifiuto delle credenziali è un guasto.
        try {
            $this->soap()->__soapCall('getRecordInfos', ['id' => '0']);
        } catch (SoapFault $e) {
            if (preg_match('/\b401\b|unauthori[sz]ed|authenticat|non autorizzat|credenzial|forbidden|\b403\b/i', $e->getMessage())) {
                throw new RuntimeException('Titulus non accetta l\'utente o la password (' . $this->senzaSegreti($e->getMessage()) . ').', 0, $e);
            }
        }

        return 'Collegamento riuscito: il servizio risponde, utente e password sono accettati, ci sono ' . count($funzioni) . ' operazioni (comprese saveDocument e addInFolder).';
    }

    /** @param array<string, mixed> $argomenti */
    private function chiama(string $operazione, array $argomenti): mixed
    {
        try {
            return $this->soap()->__soapCall($operazione, $argomenti);
        } catch (SoapFault $e) {
            throw new RuntimeException("Titulus ha risposto con un errore ($operazione): " . $this->senzaSegreti($e->getMessage()), 0, $e);
        }
    }

    private function leggiXml(mixed $risposta): SimpleXMLElement
    {
        $testo = is_string($risposta) ? $risposta : (is_object($risposta) ? (string) ($risposta->any ?? $risposta->_ ?? $risposta->_value_1 ?? '') : '');
        if (trim($testo) === '') {
            throw new RuntimeException('Titulus ha risposto senza dati.');
        }
        $prima = libxml_use_internal_errors(true);
        try {
            $x = simplexml_load_string($testo, SimpleXMLElement::class, LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($prima);
        }
        if ($x === false) {
            throw new RuntimeException('La risposta di Titulus non è un XML leggibile.');
        }

        return $x;
    }

    private function senzaSegreti(string $m): string
    {
        return str_replace([$this->password, base64_encode($this->config->username . ':' . $this->password)], '***', $m);
    }
}
