<?php

declare(strict_types=1);

namespace Characterization;

use Symfony\Component\DomCrawler\Crawler;

final class Normalizer
{
    public static function text(string $html, string $baseUrl, string $prefix): string
    {
        $crawler = new Crawler($html);
        $crawler->filter('head, script, style')->each(static function (Crawler $node): void {
            $element = $node->getNode(0);
            $element->parentNode->removeChild($element);
        });
        $crawler->filter('p, div, h1, h2, h3, h4, li, td, th')->each(static function (Crawler $node): void {
            $element = $node->getNode(0);
            $element->appendChild($element->ownerDocument->createTextNode(' '));
        });
        $text = $crawler->text('', true);
        $text = str_replace([$baseUrl, $prefix], ['{BASE_URL}', '{FIXTURE}'], $text);
        $text = preg_replace('/\b[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}\b/i', '{GUID}', $text);
        $text = preg_replace('/\b\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}\b/', '{TIMESTAMP}', $text);
        $text = preg_replace('/\bReference:\s*[a-f0-9]{13,32}\b/i', 'Reference: {REFERENCE}', $text);

        return trim(preg_replace('/\s+/u', ' ', $text));
    }
}
