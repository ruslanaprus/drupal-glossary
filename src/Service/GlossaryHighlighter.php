<?php

namespace Drupal\glossary_tooltip\Service;

use Psr\Log\LoggerInterface;

class GlossaryHighlighter {

  public const XPATH_TEXT_NODES = '//text()[not(ancestor::a) and not(ancestor::script) and not(ancestor::style)]';
  private const UTF8_XML_HEADER = '<?xml encoding="utf-8" ?>';
  public const REGEX_CHUNK_SIZE = 50;

  private LoggerInterface $logger;

  public function __construct(LoggerInterface $logger) {
    $this->logger = $logger;
  }

  public function highlight(string $html, array $terms): string {
    if (empty(trim($html)) || empty($terms)) {
      return $html;
    }

    $dom = $this->createDom($html);
    if (!$dom) {
      return $html;
    }

    $this->applyHighlights($dom, $terms);
    $output = $dom->saveHTML($dom->documentElement);
    return str_replace(self::UTF8_XML_HEADER, '', $output);
  }

  private function createDom(string $html): ?\DOMDocument {
    $dom = new \DOMDocument();
    libxml_use_internal_errors(TRUE);
    $success = $dom->loadHTML(self::UTF8_XML_HEADER . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);

    if (!$success || !$dom->documentElement) {
      $errors = libxml_get_errors();
      libxml_clear_errors();
      $this->logger->warning('GlossaryHighlighter: Failed to parse HTML. Errors: @errors', [
        '@errors' => json_encode($errors),
      ]);
      return null;
    }
    return $dom;
  }

  private function buildRegexes(array $words): array {
    $regexes = [];
    $chunks = array_chunk($words, self::REGEX_CHUNK_SIZE);
    foreach ($chunks as $chunk) {
      $regexes[] = '/(?<=^|[^\p{L}])(' . implode('|', array_map(fn($w) => preg_quote($w, '/'), $chunk)) . ')(?=$|[^\p{L}])/iu';
    }
    return $regexes;
  }

  private function applyHighlights(\DOMDocument $dom, array $terms): void {
    $xpath = new \DOMXPath($dom);
    $textNodes = $xpath->query(self::XPATH_TEXT_NODES);

    $lookup = [];
    foreach ($terms as $term) {
      $lookup[mb_strtolower($term['word'])] = $term['description'];
    }

    $words = array_keys($lookup);
    usort($words, fn($a, $b) => mb_strlen($b) <=> mb_strlen($a));

    $regexes = $this->buildRegexes($words);

    foreach ($textNodes as $textNode) {
      $original = $textNode->nodeValue;
      $parent = $textNode->parentNode;

      foreach ($regexes as $regex) {
        if (!preg_match_all($regex, $original, $matches, PREG_OFFSET_CAPTURE)) {
          continue;
        }

        $cursor = 0;
        $newNodes = [];

        foreach ($matches[1] as [$matchWord, $bytePos]) {
          $charPos = mb_strlen(substr($original, 0, $bytePos));

          if ($charPos > $cursor) {
            $newNodes[] = $dom->createTextNode(mb_substr($original, $cursor, $charPos - $cursor));
          }

          $desc = $lookup[mb_strtolower($matchWord)] ?? '';
          $span = $dom->createElement('span', $matchWord);
          $span->setAttribute('class', 'glossary-term');
          $span->setAttribute('data-tooltip', $desc);
          $newNodes[] = $span;

          $cursor = $charPos + mb_strlen($matchWord);
        }

        if ($cursor < mb_strlen($original)) {
          $newNodes[] = $dom->createTextNode(mb_substr($original, $cursor));
        }

        if (!empty($newNodes)) {
          foreach ($newNodes as $node) {
            $parent->insertBefore($node, $textNode);
          }
          $parent->removeChild($textNode);
        }
      }
    }
  }
}
