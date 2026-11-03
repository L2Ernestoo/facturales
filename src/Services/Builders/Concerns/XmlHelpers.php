<?php

namespace Lc\Fel\Services\Builders\Concerns;

trait XmlHelpers
{
    protected function cleanText(?string $value): string
    {
        $value = trim((string) ($value ?? ''));
        return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '';
    }

    protected function normalizeNit(string $value): string
    {
        $normalized = strtoupper($this->cleanText($value));
        $normalized = str_replace([' ', '-', '/'], '', $normalized);

        return $normalized === '' || $normalized === 'C/F' ? 'CF' : $normalized;
    }

    protected function limitString(string $value, int $maxLength): string
    {
        $clean = $this->cleanText($value);
        if ($clean === '') {
            return '';
        }

        return function_exists('mb_substr') ? mb_substr($clean, 0, $maxLength) : substr($clean, 0, $maxLength);
    }

    /**
     * Texto limpio, recortado y escapado para SimpleXMLElement::addChild(),
     * que no escapa "&" por su cuenta y lo descarta sin aviso.
     */
    protected function text(string $value, int $maxLength): string
    {
        return htmlspecialchars($this->limitString($value, $maxLength), ENT_XML1 | ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    protected function dec(float $value, int $decimals = 2): string
    {
        return number_format(round($value, $decimals), $decimals, '.', '');
    }

    protected function stripXmlDeclaration(string $xml): string
    {
        if (strpos($xml, "\n") === false) {
            return $xml;
        }

        return substr($xml, strpos($xml, "\n") + 1);
    }

    protected function sanitizeTagName(string $value): string
    {
        $value = preg_replace('/[^A-Za-z0-9_]/', '', trim($value)) ?? '';
        if ($value === '') {
            return '';
        }

        return is_numeric(substr($value, 0, 1)) ? '_' . $value : $value;
    }
}
