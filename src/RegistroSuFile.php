<?php

declare(strict_types=1);

namespace Unical\Titulus;

use RuntimeException;

/** Registro degli invii su un file di testo, una riga JSON per invio (nessun database). Nel file non c'è mai la password. */
final class RegistroSuFile implements RegistroInvii
{
    public function __construct(private string $percorso)
    {
    }

    public function registra(array $riga): int
    {
        $id = count($this->leggi()) + 1;
        $riga = ['id' => $id, 'quando' => date('c')] + $riga;
        $json = json_encode($riga, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false || file_put_contents($this->percorso, $json . "\n", FILE_APPEND | LOCK_EX) === false) {
            throw new RuntimeException('Non riesco a scrivere il registro degli invii in ' . $this->percorso);
        }

        return $id;
    }

    public function numeroAssegnato(string $rifTipo, ?int $rifId): ?string
    {
        if ($rifTipo === '') {
            return null;
        }
        foreach (array_reverse($this->leggi()) as $r) {
            if (($r['rif_tipo'] ?? '') === $rifTipo && ($r['rif_id'] ?? null) === $rifId && ($r['esito'] ?? '') === Costanti::ESITO_OK && ($r['num_prot'] ?? '') !== '') {
                return (string) $r['num_prot'];
            }
        }

        return null;
    }

    /** @return list<array<string, mixed>> */
    private function leggi(): array
    {
        if (!is_file($this->percorso)) {
            return [];
        }
        $out = [];
        foreach (file($this->percorso, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $linea) {
            $r = json_decode($linea, true);
            if (is_array($r)) {
                $out[] = $r;
            }
        }

        return $out;
    }
}
