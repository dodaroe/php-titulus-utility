<?php

declare(strict_types=1);

namespace Unical\Titulus;

/** Registro degli invii che vive solo finché dura la richiesta: utile per le prove o per script brevi. */
final class RegistroInMemoria implements RegistroInvii
{
    /** @var array<int, array<string, mixed>> */
    public array $righe = [];

    public function registra(array $riga): int
    {
        $id = count($this->righe) + 1;
        $this->righe[$id] = $riga + ['id' => $id];

        return $id;
    }

    public function numeroAssegnato(string $rifTipo, ?int $rifId): ?string
    {
        if ($rifTipo === '') {
            return null;
        }
        foreach (array_reverse($this->righe) as $r) {
            if ($r['rif_tipo'] === $rifTipo && $r['rif_id'] === $rifId && $r['esito'] === Costanti::ESITO_OK && $r['num_prot'] !== '') {
                return (string) $r['num_prot'];
            }
        }

        return null;
    }
}
