<?php

declare(strict_types=1);

namespace Unical\Titulus;

/**
 * Dove collegarsi a Titulus, con quale utenza e per quale AOO. Non sa nulla di database o di file di configurazione:
 * chi usa il pacchetto la riempie come preferisce (costruttore, `daArray()`, variabili d'ambiente con `daAmbiente()`).
 * La password non ne fa parte: si passa a parte al servizio, così non finisce per sbaglio in un log.
 */
final class ConfigTitulus
{
    public function __construct(
        public readonly string $urlWs,
        public readonly string $username,
        public readonly string $aoo,
        public readonly string $namespaceNs2 = '',
        public readonly string $agd = '',
        public readonly string $autore = '',
        public readonly string $emailDefault = '',
        public readonly bool $fascicolazioneSeparata = true,
        public readonly string $nome = '',
        public readonly bool $attivo = true,
    ) {
    }

    /**
     * @param array<string, mixed> $r chiavi: url_ws, username, aoo (obbligatorie); namespace_ns2, agd, autore, email_default,
     *        fascicolazione_separata (default 1), nome, attivo (default 1)
     */
    public static function daArray(array $r): self
    {
        return new self(
            (string) ($r['url_ws'] ?? ''),
            (string) ($r['username'] ?? ''),
            (string) ($r['aoo'] ?? ''),
            (string) ($r['namespace_ns2'] ?? ''),
            (string) ($r['agd'] ?? ''),
            (string) ($r['autore'] ?? ''),
            (string) ($r['email_default'] ?? ''),
            (int) ($r['fascicolazione_separata'] ?? 1) === 1,
            (string) ($r['nome'] ?? ''),
            (int) ($r['attivo'] ?? 1) === 1,
        );
    }

    /**
     * Legge la configurazione dalle variabili d'ambiente TITULUS_URL_WS, TITULUS_USERNAME, TITULUS_AOO (e, se ci sono,
     * TITULUS_NAMESPACE_NS2, TITULUS_AGD, TITULUS_AUTORE, TITULUS_EMAIL_DEFAULT). La password è TITULUS_PASSWORD, da leggere a parte.
     */
    public static function daAmbiente(string $prefisso = 'TITULUS_'): self
    {
        $v = static fn (string $k): string => (string) (getenv($prefisso . $k) ?: '');

        return new self($v('URL_WS'), $v('USERNAME'), $v('AOO'), $v('NAMESPACE_NS2'), $v('AGD'), $v('AUTORE'), $v('EMAIL_DEFAULT'));
    }

    /** Mancano i dati per collegarsi? (testo dell'avviso, null se è completa) */
    public function incompleta(): ?string
    {
        foreach (['urlWs' => 'l\'indirizzo del servizio', 'username' => 'l\'username', 'aoo' => 'l\'AOO'] as $campo => $nome) {
            if (trim($this->$campo) === '') {
                return "Manca $nome.";
            }
        }

        return null;
    }
}
