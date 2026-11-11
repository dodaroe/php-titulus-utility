# php-titulus-utility 🏛️

Connettore **PHP** per il protocollo SOAP di **Titulus**, pari del connettore Python [`dj-titulus-utility`](../README.md): stesse operazioni (`saveDocument`, `addInFolder`, `setWSUser`, `getRecordInfos`, `getDocumentURL`), stessi XML, stessi controlli. Non dipende da nessun framework né da un database: lo usa qualsiasi programma PHP (Laravel, Symfony, WordPress, uno script).

Cosa fa: protocollo **in arrivo**, **in partenza**, **tra uffici**, **avvio dell'iter** (bozza + voce d'indice), fascicolazione, lettura del numero di un'iter concluso, prova del collegamento.

## 📦 Requisiti e installazione

PHP 8.1 o superiore, con le estensioni `soap`, `dom`, `mbstring` (e, facoltativa, `zip`).

**Con Composer** (dal repository, o da una cartella locale):

```json
{
    "repositories": [{ "type": "path", "url": "../php-titulus-utility" }],
    "require": { "unical/php-titulus-utility": "*" }
}
```

**Senza Composer**: copia la cartella dove vuoi e carica l'autoloader.

```php
require '/percorso/php-titulus-utility/autoload.php';
```

## ⚙️ Configurazione

La configurazione è un oggetto: la riempi come preferisci. **La password si passa a parte**, così non finisce per sbaglio in un log.

```php
use Unical\Titulus\ConfigTitulus;

// a mano
$config = new ConfigTitulus(
    urlWs: 'https://titulus-prod.dominio.it/ws/Titulus4?wsdl',
    username: 'utente_ws',
    aoo: 'UCALPRG',
    autore: 'Il mio programma',          // facoltativo
);

// da un array (per esempio una riga del tuo database)
$config = ConfigTitulus::daArray(['url_ws' => '…', 'username' => '…', 'aoo' => '…']);

// dalle variabili d'ambiente TITULUS_URL_WS, TITULUS_USERNAME, TITULUS_AOO (+ TITULUS_AUTORE, TITULUS_AGD, …)
$config = ConfigTitulus::daAmbiente();   // la password è TITULUS_PASSWORD
```

## 🚀 Esempi di utilizzo

### 0. Prova del collegamento (non crea nulla in Titulus)

```php
use Unical\Titulus\ServizioTitulus;

$titulus = new ServizioTitulus($config, getenv('TITULUS_PASSWORD'));
['ok' => $ok, 'messaggio' => $msg] = $titulus->provaConnessione();
```

### 1. Protocollo in arrivo

```php
use Unical\Titulus\{AllegatoTitulus, DocumentoDaProtocollare, ParametriProtocollo};

$documento = new DocumentoDaProtocollare(
    oggetto: 'Convenzione di tirocinio',
    principale: AllegatoTitulus::crea('convenzione.pdf', file_get_contents('convenzione.pdf')),
    allegati: [AllegatoTitulus::crea('allegato.pdf', $altroPdf)],            // facoltativo
    esterno: ['nome' => 'Mario', 'cognome' => 'Rossi', 'email' => 'mario@example.it'],  // chi scrive
);

$parametri = new ParametriProtocollo(
    tipo: 'arrivo',
    uo: '2014.2',                // ufficio dell'RPA (vedi CatalogoTitulus::UFFICI)
    codTitolario: '5/1',         // vedi CatalogoTitulus::TITOLARIO
    rpaNome: 'Anna Bianchi',
    rpaMatricola: '123',         // serve solo per la fascicolazione
    fascicoloNumero: '2026-5/1.1',   // facoltativo
);

$esito = $titulus->protocollaArrivo($documento, $parametri);
echo $esito->ok ? "Protocollo: {$esito->numProt}" : "Errore: {$esito->messaggio}";
```

I metodi che inviano **non lanciano mai eccezioni**: restituiscono un `EsitoProtocollo` (`ok`, `messaggio` già scritto in italiano, `nrecord`, `numProt`, `url`).

### 2. Protocollo in partenza

Serve il codice fiscale del destinatario.

```php
$documento = new DocumentoDaProtocollare('Lettera di incarico', $pdf,
    esterno: ['nome' => 'Anna', 'cognome' => 'Verdi', 'codice_fiscale' => 'VRDNNA80A41H501X']);
$esito = $titulus->protocollaPartenza($documento, $parametri);
```

### 3. Documento tra uffici

Se non indichi il mittente (`conMittenteInterno([...])`) si usano l'ufficio e l'RPA dei parametri.

```php
$esito = $titulus->protocollaInterno(new DocumentoDaProtocollare('Nota interna', $pdf), $parametri);
```

### 4. Avvio dell'iter / bozza

Titulus restituisce un **nrecord**, non il numero di protocollo. Serve la voce d'indice.

```php
$parametri = new ParametriProtocollo('arrivo', '2014.2', '5/1', 'Anna Bianchi', voceIndice: 'Iter Approva e Firma');
$esito = $titulus->avviaIter($documento, $parametri);   // $esito->nrecord

// più tardi, quando l'iter è concluso:
$r = $titulus->leggiNumero($esito->nrecord);            // ['ok' => true, 'num_prot' => '…', 'url' => '…']
```

### 5. Registro degli invii e «mai due volte»

Passando un `RegistroInvii` ogni invio (riuscito o no) viene annotato, e `numero()` non protocolla due volte lo stesso documento: se per quel riferimento (`rifTipo`, `rifId`) esiste già un numero, lo restituisce.

```php
use Unical\Titulus\RegistroSuFile;   // oppure RegistroInMemoria, oppure il tuo (implementa RegistroInvii: 2 funzioni)

$titulus = new ServizioTitulus($config, $password, registro: new RegistroSuFile('/var/log/titulus.jsonl'));

$documento = new DocumentoDaProtocollare('Domanda', $pdf, esterno: ['nome' => 'Mario'], rifTipo: 'domanda', rifId: 42);
['numero' => $numero, 'errore' => $errore] = $titulus->numero($documento, $parametri);
```

## 🧱 Com'è fatto

| Classe | A cosa serve |
| --- | --- |
| `ServizioTitulus` | il punto d'ingresso: `protocolla*`, `avviaIter`, `numero`, `leggiNumero`, `provaConnessione` |
| `ConfigTitulus`, `ParametriProtocollo`, `DocumentoDaProtocollare` | i dati: dove collegarsi, come registrare, cosa inviare |
| `AllegatoTitulus` | un file, con l'estensione giusta ricavata dal contenuto (come il connettore Python) |
| `GeneratoreXml` | gli XML del documento e della fascicolazione (con DOM: `&`, `<`, apici protetti da soli) |
| `ClienteSoapTitulus` (`ClienteTitulus`) | le chiamate SOAP con l'estensione `soap` di PHP, autenticazione HTTP Basic, la password non compare mai negli errori |
| `CatalogoTitulus` | titolario e uffici di Titulus dell'Ateneo (da `conf.py` del connettore Python) |
| `RegistroInvii` | dove ricordare gli invii (`RegistroInMemoria`, `RegistroSuFile`, o il tuo) |

`ClienteTitulus` e `FabbricaClienteTitulus` sono interfacce: nelle prove si passa un cliente finto come terzo argomento di `ServizioTitulus`, senza uscire in rete.

## 🧪 Prove

```bash
php tests/esegui.php
```

Prove senza Composer e senza rete (cliente SOAP finto). Il servizio vero si prova con `provaConnessione()`.

## ↔️ Differenze rispetto al connettore Python

- Non c'è il database di configurazione (`ConfigurationWSProtocollo*`): la configurazione e i parametri sono oggetti.
- Non ci sono ancora le ricevute di ritorno (`WSTitulusMessageBroker`) né la lettura degli allegati (`WSTitulusFileClient`).
- L'ambiente di collaudo non è un parametro `test=True`: si crea un secondo `ConfigTitulus` col WSDL di collaudo.
