<?php

namespace App\Services\AiCommon;

use App\Contracts\AiCommonAttachmentExtractor;
use DOMDocument;
use DOMXPath;
use Symfony\Component\Process\Process;
use Throwable;
use ZipArchive;

class BoundedAiCommonAttachmentExtractor implements AiCommonAttachmentExtractor
{
    private const MAX_CHARACTERS = 2000;

    private const MAX_ZIP_ENTRIES = 2000;

    private const MAX_ZIP_UNCOMPRESSED_BYTES = 20 * 1024 * 1024;

    private const MAX_XML_BYTES = 5 * 1024 * 1024;

    public function extract(string $binary, string $extension, array $selector): array
    {
        return match (strtolower($extension)) {
            'pdf' => $this->pdf($binary, $selector),
            'docx' => $this->docx($binary, $selector),
            'xlsx' => $this->xlsx($binary, $selector),
            default => throw new AiCommonExtractionException('extraction_unsupported'),
        };
    }

    private function pdf(string $binary, array $selector): array
    {
        $from = $this->positiveInt($selector['page_from'] ?? null, 'page_from');
        $to = $this->positiveInt($selector['page_to'] ?? null, 'page_to');
        if ($to < $from || ($to - $from + 1) > 10) {
            throw new AiCommonExtractionException('selection_out_of_bounds');
        }
        $input = tempnam(sys_get_temp_dir(), 'co-pdf-in-');
        $output = tempnam(sys_get_temp_dir(), 'co-pdf-out-');
        if ($input === false || $output === false) {
            throw new AiCommonExtractionException('extractor_unavailable');
        }
        try {
            if (file_put_contents($input, $binary) !== strlen($binary)) {
                throw new AiCommonExtractionException('extractor_unavailable');
            }
            $process = new Process([
                (string) config('services.ai_common.pdftotext_binary', 'pdftotext'),
                '-f', (string) $from, '-l', (string) $to,
                '-enc', 'UTF-8', '-nopgbrk', $input, $output,
            ]);
            $process->setTimeout(15)->run();
            if (! $process->isSuccessful() || ! is_file($output) || filesize($output) > 1024 * 1024) {
                throw new AiCommonExtractionException('extraction_failed');
            }
            $content = file_get_contents($output);
            if (! is_string($content)) {
                throw new AiCommonExtractionException('extraction_failed');
            }

            return $this->result($content, [
                'page_from' => $from,
                'page_to' => $to,
            ], 'pdftotext', 'p3-v1');
        } catch (AiCommonExtractionException $error) {
            throw $error;
        } catch (Throwable $error) {
            throw new AiCommonExtractionException('extraction_failed', $error);
        } finally {
            @unlink($input);
            @unlink($output);
        }
    }

    private function docx(string $binary, array $selector): array
    {
        $from = $this->positiveInt($selector['paragraph_from'] ?? null, 'paragraph_from');
        $to = $this->positiveInt($selector['paragraph_to'] ?? null, 'paragraph_to');
        if ($to < $from || ($to - $from + 1) > 50) {
            throw new AiCommonExtractionException('selection_out_of_bounds');
        }
        [$zip, $path] = $this->openZip($binary);
        try {
            $xml = $this->zipEntry($zip, 'word/document.xml');
            $document = $this->xml($xml);
            $xpath = new DOMXPath($document);
            $paragraphs = $xpath->query("//*[local-name()='body']/*[local-name()='p']");
            if ($paragraphs === false || $to > $paragraphs->length) {
                throw new AiCommonExtractionException('selection_out_of_bounds');
            }
            $selected = [];
            for ($index = $from - 1; $index < $to; $index++) {
                $parts = [];
                foreach ($xpath->query(".//*[local-name()='t']", $paragraphs->item($index)) ?: [] as $node) {
                    $parts[] = $node->textContent;
                }
                $selected[] = implode('', $parts);
            }

            return $this->result(implode("\n", $selected), [
                'paragraph_from' => $from,
                'paragraph_to' => $to,
            ], 'ooxml-docx', 'p3-v1');
        } finally {
            $zip->close();
            @unlink($path);
        }
    }

    private function xlsx(string $binary, array $selector): array
    {
        $sheetIndex = $this->positiveInt($selector['sheet_index'] ?? null, 'sheet_index');
        $rowFrom = $this->positiveInt($selector['row_from'] ?? null, 'row_from');
        $rowTo = $this->positiveInt($selector['row_to'] ?? null, 'row_to');
        if ($sheetIndex > 20 || $rowTo < $rowFrom || ($rowTo - $rowFrom + 1) > 100) {
            throw new AiCommonExtractionException('selection_out_of_bounds');
        }
        [$zip, $path] = $this->openZip($binary);
        try {
            $sheetPath = $this->sheetPath($zip, $sheetIndex);
            $shared = $this->sharedStrings($zip);
            $document = $this->xml($this->zipEntry($zip, $sheetPath));
            $xpath = new DOMXPath($document);
            $rows = $xpath->query("//*[local-name()='sheetData']/*[local-name()='row']");
            $lines = [];
            foreach ($rows ?: [] as $row) {
                $number = (int) $row->getAttribute('r');
                if ($number < $rowFrom || $number > $rowTo) {
                    continue;
                }
                $cells = [];
                foreach ($xpath->query("./*[local-name()='c']", $row) ?: [] as $cell) {
                    $coordinate = $cell->getAttribute('r');
                    if (($xpath->query("./*[local-name()='f']", $cell)?->length ?? 0) > 0) {
                        $cells[] = $coordinate.'=[formula omitted]';

                        continue;
                    }
                    $valueNode = $xpath->query("./*[local-name()='v']", $cell)?->item(0);
                    $inlineNode = $xpath->query("./*[local-name()='is']//*[local-name()='t']", $cell)?->item(0);
                    $value = $inlineNode?->textContent ?? $valueNode?->textContent ?? '';
                    if ($cell->getAttribute('t') === 's' && ctype_digit($value)) {
                        $value = $shared[(int) $value] ?? '';
                    }
                    $cells[] = $coordinate.'='.$value;
                }
                if ($cells !== []) {
                    $lines[] = implode(' | ', $cells);
                }
            }

            return $this->result(implode("\n", $lines), [
                'sheet_index' => $sheetIndex,
                'row_from' => $rowFrom,
                'row_to' => $rowTo,
            ], 'ooxml-xlsx', 'p3-v1');
        } finally {
            $zip->close();
            @unlink($path);
        }
    }

    private function result(string $content, array $selector, string $driver, string $version): array
    {
        $content = trim((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $content));
        if ($content === '') {
            throw new AiCommonExtractionException('extraction_empty');
        }
        if (mb_strlen($content) > self::MAX_CHARACTERS) {
            throw new AiCommonExtractionException('selection_too_large');
        }

        return compact('content', 'selector', 'driver', 'version');
    }

    private function positiveInt(mixed $value, string $field): int
    {
        $result = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($result === false) {
            throw new AiCommonExtractionException('invalid_'.$field);
        }

        return $result;
    }

    /** @return array{ZipArchive,string} */
    private function openZip(string $binary): array
    {
        $path = tempnam(sys_get_temp_dir(), 'co-ooxml-');
        if ($path === false || file_put_contents($path, $binary) !== strlen($binary)) {
            throw new AiCommonExtractionException('extractor_unavailable');
        }
        $zip = new ZipArchive;
        try {
            if ($zip->open($path) !== true || $zip->numFiles > self::MAX_ZIP_ENTRIES) {
                throw new AiCommonExtractionException('extraction_failed');
            }
            $total = 0;
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index);
                $total += (int) ($stat['size'] ?? 0);
                if ($total > self::MAX_ZIP_UNCOMPRESSED_BYTES) {
                    throw new AiCommonExtractionException('archive_expansion_limit');
                }
            }

            return [$zip, $path];
        } catch (Throwable $error) {
            try {
                $zip->close();
            } catch (Throwable) {
                // The archive never opened; only the original safe extraction error is relevant.
            }
            @unlink($path);
            throw $error;
        }
    }

    private function zipEntry(ZipArchive $zip, string $name): string
    {
        $stat = $zip->statName($name);
        if ($stat === false || (int) ($stat['size'] ?? 0) > self::MAX_XML_BYTES) {
            throw new AiCommonExtractionException('extraction_failed');
        }
        $content = $zip->getFromName($name);
        if (! is_string($content)) {
            throw new AiCommonExtractionException('extraction_failed');
        }

        return $content;
    }

    private function xml(string $content): DOMDocument
    {
        $previous = libxml_use_internal_errors(true);
        try {
            $document = new DOMDocument;
            if (! $document->loadXML($content, LIBXML_NONET | LIBXML_COMPACT)) {
                throw new AiCommonExtractionException('extraction_failed');
            }

            return $document;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function sheetPath(ZipArchive $zip, int $sheetIndex): string
    {
        $workbook = $this->xml($this->zipEntry($zip, 'xl/workbook.xml'));
        $sheets = (new DOMXPath($workbook))->query("//*[local-name()='sheets']/*[local-name()='sheet']");
        $sheet = $sheets?->item($sheetIndex - 1);
        if (! $sheet) {
            throw new AiCommonExtractionException('selection_out_of_bounds');
        }
        $relationshipId = $sheet->getAttributeNS(
            'http://schemas.openxmlformats.org/officeDocument/2006/relationships',
            'id',
        );
        $relationships = $this->xml($this->zipEntry($zip, 'xl/_rels/workbook.xml.rels'));
        foreach ((new DOMXPath($relationships))->query("//*[local-name()='Relationship']") ?: [] as $relationship) {
            if ($relationship->getAttribute('Id') === $relationshipId) {
                $target = str_replace('\\', '/', $relationship->getAttribute('Target'));
                $target = preg_replace('#^/?worksheets/#', 'worksheets/', $target);
                if (! is_string($target) || str_contains($target, '..')) {
                    break;
                }

                if (str_starts_with($target, '/xl/')) {
                    return ltrim($target, '/');
                }

                return 'xl/'.ltrim($target, '/');
            }
        }
        throw new AiCommonExtractionException('extraction_failed');
    }

    /** @return array<int, string> */
    private function sharedStrings(ZipArchive $zip): array
    {
        if ($zip->locateName('xl/sharedStrings.xml') === false) {
            return [];
        }
        $document = $this->xml($this->zipEntry($zip, 'xl/sharedStrings.xml'));
        $xpath = new DOMXPath($document);
        $strings = [];
        foreach ($xpath->query("//*[local-name()='si']") ?: [] as $item) {
            if (count($strings) >= 10_000) {
                throw new AiCommonExtractionException('archive_expansion_limit');
            }
            $parts = [];
            foreach ($xpath->query(".//*[local-name()='t']", $item) ?: [] as $node) {
                $parts[] = $node->textContent;
            }
            $strings[] = implode('', $parts);
        }

        return $strings;
    }
}
