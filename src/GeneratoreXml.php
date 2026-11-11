<?php

declare(strict_types=1);

namespace Unical\Titulus;

use DOMDocument;
use DOMElement;
use InvalidArgumentException;

/**
 * I due XML che Titulus si aspetta: il documento (<doc>, porta di document.jinja2.xml del connettore Python) e la
 * fascicolazione (<fascicolo>, porta di fascicolo.jinja2.xml). L'XML si costruisce con DOM, quindi apici, «&» e «<» nei nomi e
 * nell'oggetto vengono protetti da soli (il template Jinja non lo faceva).
 */
final class GeneratoreXml
{
    /**
     * @param array{
     *   tipo: string, bozza: bool, aoo: string, autore: string, oggetto: string, num_allegati: int,
     *   cod_repertorio?: string|null, voce_indice?: string|null, cod_titolario: string, classif?: string,
     *   rpa_nome: string, uo_nome: string, uo: string,
     *   op?: array{uo: string, nome: string, uo_nome: string, matricola: string}|null,
     *   cc?: list<array{uo: string, nome: string, uo_nome: string, matricola: string}>,
     *   esterno?: array{nome: string, codice_fiscale: string, email: string, telefono: string, fax: string, indirizzo: string},
     *   mittente_interno?: array{nome: string, uff_nome: string, uff_cod: string},
     *   notifica?: array{url: string, label: string, metodo: string, auth: string}|null,
     *   collegato_a?: string|null
     * } $d
     */
    public function documento(array $d): string
    {
        if (!isset(Costanti::TIPI_DOCUMENTO[$d['tipo']])) {
            throw new InvalidArgumentException('Tipo di documento non valido: ' . $d['tipo']);
        }
        $interno = $d['tipo'] === 'interno';
        $x = new DOMDocument('1.0', 'UTF-8');
        $x->formatOutput = false;
        $doc = $x->createElement('doc');
        $x->appendChild($doc);
        $doc->setAttribute('tipo', $d['tipo']);
        $doc->setAttribute('bozza', $d['bozza'] ? 'si' : 'no');
        $doc->setAttribute('cod_amm_aoo', $d['aoo']);
        $doc->appendChild(self::nodo($x, 'autore', $d['autore']));

        if (!empty($d['cod_repertorio'])) {
            $rep = $x->createElement('repertorio');
            $rep->setAttribute('cod', (string) $d['cod_repertorio']);
            $doc->appendChild($rep);
        }
        if ($interno) {
            $min = $x->createElement('minuta');
            $min->appendChild($this->classif($x, $d));
            $mi = $d['mittente_interno'] ?? ['nome' => '', 'uff_nome' => '', 'uff_cod' => ''];
            $mit = $x->createElement('mittente');
            $mit->setAttribute('nome_persona', $mi['nome']);
            $mit->setAttribute('nome_uff', $mi['uff_nome']);
            $mit->setAttribute('cod_uff', $mi['uff_cod']);
            $min->appendChild($mit);
            $doc->appendChild($min);
        }
        $doc->appendChild(self::nodo($x, 'oggetto', $d['oggetto']));
        if (!empty($d['voce_indice']) && $d['bozza']) {
            $doc->appendChild(self::nodo($x, 'voce_indice', (string) $d['voce_indice']));
        }
        if (!$interno) {
            $doc->appendChild($this->classif($x, $d));
        }
        $doc->appendChild(self::nodo($x, 'allegato', (string) $d['num_allegati']));

        $rifs = $x->createElement('rif_interni');
        $rifs->appendChild($this->rifInterno($x, 'RPA', $d['uo'], $d['rpa_nome'], $d['uo_nome'], ''));
        if (!empty($d['op'])) {
            $rifs->appendChild($this->rifInterno($x, 'OP', $d['op']['uo'], $d['op']['nome'], $d['op']['uo_nome'], $d['op']['matricola']));
        }
        foreach ($d['cc'] ?? [] as $cc) {
            $rifs->appendChild($this->rifInterno($x, 'CC', $cc['uo'], $cc['nome'], $cc['uo_nome'], $cc['matricola']));
        }
        $doc->appendChild($rifs);

        if (!$interno) {
            $e = $d['esterno'] ?? ['nome' => '', 'codice_fiscale' => '', 'email' => '', 'telefono' => '', 'fax' => '', 'indirizzo' => ''];
            $rifEst = $x->createElement('rif_esterni');
            $re = $x->createElement('rif_esterno');
            $re->setAttribute('codice_fiscale', $e['codice_fiscale']);
            $nome = self::nodo($x, 'nome', $e['nome']);
            $nome->setAttribute('cod', $e['codice_fiscale']);
            $re->appendChild($nome);
            if ($e['indirizzo'] !== '' || $e['email'] !== '' || $e['fax'] !== '' || $e['telefono'] !== '') {
                $ind = self::nodo($x, 'indirizzo', $e['indirizzo']);
                $ind->setAttribute('email', $e['email']);
                $ind->setAttribute('fax', $e['fax']);
                $ind->setAttribute('tel', $e['telefono']);
                $ind->setAttributeNS('http://www.w3.org/XML/1998/namespace', 'xml:space', 'preserve');
                $re->appendChild($ind);
            }
            $rifEst->appendChild($re);
            $doc->appendChild($rifEst);
        }

        if (!empty($d['notifica']) && $d['notifica']['url'] !== '') {
            $n = $d['notifica'];
            $extra = $x->createElement('extra');
            $notifiche = $x->createElement('notifiche');
            $no = $x->createElement('notifica');
            foreach (['type' => 'REST', 'event' => 'PROTOCOLLAZIONE', 'label' => $n['label'], 'method' => $n['metodo'], 'url' => $n['url'], 'includeNRecord' => 'true', 'done' => 'NO'] as $a => $v) {
                $no->setAttribute($a, $v);
            }
            if ($n['auth'] !== '') {
                $no->setAttribute('auth', $n['auth']);
            }
            $notifiche->appendChild($no);
            $extra->appendChild($notifiche);
            $doc->appendChild($extra);
        }
        if (!empty($d['collegato_a'])) {
            $l = $x->createElement('link_interno');
            $l->setAttribute('href', (string) $d['collegato_a']);
            $doc->appendChild($l);
        }

        return (string) $x->saveXML($doc);
    }

    /** XML della fascicolazione: il documento (per nrecord e numero di protocollo) dentro il fascicolo indicato. */
    public function fascicolo(string $fascicoloNumero, string $docNrecord, string $docNumProt): string
    {
        $x = new DOMDocument('1.0', 'UTF-8');
        $f = $x->createElement('fascicolo');
        $f->setAttribute('physdoc', '');
        $f->setAttribute('nrecord', '');
        $f->setAttribute('numero', $fascicoloNumero);
        $d = $x->createElement('doc');
        $d->setAttribute('physdoc', '');
        $d->setAttribute('nrecord', $docNrecord);
        $d->setAttribute('num_prot', $docNumProt);
        $d->setAttribute('minuta', 'no');
        $f->appendChild($d);
        $x->appendChild($f);

        return (string) $x->saveXML($f);
    }

    /** @param array<string, mixed> $d */
    private function classif(DOMDocument $x, array $d): DOMElement
    {
        $c = self::nodo($x, 'classif', (string) ($d['classif'] ?? ''));
        $c->setAttribute('cod', $d['cod_titolario']);

        return $c;
    }

    private function rifInterno(DOMDocument $x, string $diritto, string $uo, string $persona, string $uoNome, string $matricola): DOMElement
    {
        $r = $x->createElement('rif_interno');
        $r->setAttribute('diritto', $diritto);
        if ($persona !== '' || $diritto === 'RPA') {
            $r->setAttribute('nome_persona', $persona);
        }
        if ($uoNome !== '' || $diritto === 'RPA') {
            $r->setAttribute('nome_uff', $uoNome);
        }
        $r->setAttribute('cod_uff', $uo);
        if ($matricola !== '') {
            $r->setAttribute('cod_persona', $matricola);
        }

        return $r;
    }

    /** Elemento con un testo: il testo è un nodo a parte, così «&», «<» e «>» vengono protetti (createElement con il valore non lo fa). */
    private static function nodo(DOMDocument $x, string $nome, string $testo): DOMElement
    {
        $e = $x->createElement($nome);
        // Si tolgono i caratteri di controllo che l'XML non ammette
        $e->appendChild($x->createTextNode((string) preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $testo)));

        return $e;
    }
}
