<?php

declare(strict_types=1);

namespace Unical\Titulus;

use InvalidArgumentException;
use ZipArchive;

/**
 * Un file da inviare a Titulus (il primo è il documento principale). Titulus rifiuta i file senza estensione o con l'estensione
 * sbagliata (errore ISO 32000-1 sui falsi PDF): qui si ricava l'estensione giusta dal contenuto, come fa il connettore Python.
 */
final class AllegatoTitulus
{
    private const PROTETTE = ['.zip', '.7z', '.rar', '.tar', '.gz', '.xml', '.json', '.csv', '.p7m', '.p7s', '.tsd', '.m7m', '.eml', '.msg', '.png', '.jpg', '.jpeg', '.tiff', '.tif',
        '.docx', '.xlsx', '.pptx', '.odt', '.ods', '.odp', '.doc', '.xls', '.ppt'];

    private function __construct(public readonly string $nomeFile, public readonly string $contenuto, public readonly string $descrizione)
    {
    }

    /** @throws InvalidArgumentException se il file è vuoto o non si riconosce */
    public static function crea(string $nomeFile, string $contenuto, ?string $descrizione = null): self
    {
        $nomeFile = trim(str_replace(['/', '\\', "\0"], '_', $nomeFile));
        if ($contenuto === '') {
            throw new InvalidArgumentException("Il file «{$nomeFile}» è vuoto.");
        }
        $nome = self::conEstensione($nomeFile === '' ? 'documento' : $nomeFile, $contenuto);

        return new self($nome, $contenuto, $descrizione ?? $nome);
    }

    private static function conEstensione(string $nome, string $c): string
    {
        $ext = strtolower((string) (($p = strrpos($nome, '.')) !== false ? substr($nome, $p) : ''));
        $pdf = str_starts_with($c, '%PDF');
        if (in_array($ext, self::PROTETTE, true)) {
            return $nome;
        }
        if ($ext === '.pdf' || $pdf) {
            if (!$pdf) {
                throw new InvalidArgumentException("Il file «{$nome}» ha estensione .pdf ma non è un PDF valido: Titulus lo rifiuterebbe.");
            }

            return $ext === '.pdf' ? $nome : $nome . '.pdf';
        }
        if ($ext !== '') {
            return $nome; // estensione non in elenco: la si lascia, decide Titulus
        }
        $magic = substr($c, 0, 4);
        if (str_starts_with($magic, "PK\x03\x04")) {
            return $nome . self::estensioneZip($c);
        }
        if (str_starts_with($magic, "\xD0\xCF\x11\xE0")) {
            $testa = substr($c, 0, 4096);

            return $nome . match (true) {
                str_contains($testa, 'WordDocument') => '.doc',
                str_contains($testa, 'Workbook'), str_contains($testa, 'Book') => '.xls',
                str_contains($testa, '__substg1.0') => '.msg',
                str_contains($testa, 'PowerPoint') => '.ppt',
                default => '.doc',
            };
        }

        return $nome . match (true) {
            str_starts_with($magic, "\x30\x82") => '.p7m',
            str_starts_with(strtolower($magic), 'from'), str_starts_with(strtolower($magic), 'retu') => '.eml',
            str_starts_with($magic, "\x89PNG") => '.png',
            str_starts_with($magic, "\xFF\xD8\xFF") => '.jpg',
            str_starts_with($magic, "II*\x00"), str_starts_with($magic, "MM\x00*") => '.tiff',
            str_starts_with($magic, '<?xm') => '.xml',
            str_starts_with($magic, '{'), str_starts_with($magic, '[') => '.json',
            default => throw new InvalidArgumentException("Il formato del file «{$nome}» non si riconosce: dagli un'estensione (es. .pdf)."),
        };
    }

    private static function estensioneZip(string $contenuto): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'tit');
        if ($tmp === false || !class_exists(ZipArchive::class)) {
            return '.zip';
        }
        try {
            file_put_contents($tmp, $contenuto);
            $z = new ZipArchive();
            if ($z->open($tmp) !== true) {
                return '.zip';
            }
            $ext = '.zip';
            if ($z->locateName('word/document.xml') !== false) {
                $ext = '.docx';
            } elseif ($z->locateName('xl/workbook.xml') !== false) {
                $ext = '.xlsx';
            } elseif ($z->locateName('ppt/presentation.xml') !== false) {
                $ext = '.pptx';
            } elseif (($mime = $z->getFromName('mimetype')) !== false) {
                $ext = match (true) {
                    str_contains($mime, 'opendocument.text') => '.odt',
                    str_contains($mime, 'opendocument.spreadsheet') => '.ods',
                    str_contains($mime, 'opendocument.presentation') => '.odp',
                    default => '.zip',
                };
            }
            $z->close();

            return $ext;
        } finally {
            @unlink($tmp);
        }
    }
}
