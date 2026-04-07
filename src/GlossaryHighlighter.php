<?php

namespace Drupal\glossary_tooltip;

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
    $output = '';
    foreach ($dom->documentElement->childNodes as $child) {
      $output .= $dom->saveHTML($child);
    }
    return $output;
  }

  private function createDom(string $html): ?\DOMDocument {
    $dom = new \DOMDocument();
    libxml_use_internal_errors(TRUE);
    $success = $dom->loadHTML(self::UTF8_XML_HEADER . '<div>' . $html . '</div>',
      LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
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

  private function buildCombinedRegex(array $words): string {
    $chunks = array_chunk($words, self::REGEX_CHUNK_SIZE);
    $patterns = [];

    foreach ($chunks as $chunk) {
      $patterns[] = '(' . implode('|',
          array_map(fn($w) => preg_quote($w, '/'), $chunk)
        ) . ')';
    }

    $pattern = '/(?<=^|[^\p{L}])(' . implode('|', $patterns) . ')(?=$|[^\p{L}])/iu';
    return $pattern;
  }

  private function applyHighlights(\DOMDocument $dom, array $terms): void {
    $xpath = new \DOMXPath($dom);
    $textNodes = $xpath->query(self::XPATH_TEXT_NODES);

    $lookup = [];
    foreach ($terms as $term) {
      $lookup[mb_strtolower($term['word'])] = [
        'description' => $term['description'],
        'url' => $term['url'] ?? NULL,
        'is_truncated' => $term['is_truncated'] ?? FALSE,
      ];
    }

    $words = array_keys($lookup);
    usort($words, fn($a, $b) => mb_strlen($b) <=> mb_strlen($a));

    $regex = $this->buildCombinedRegex($words);

    foreach ($textNodes as $textNode) {
      $original = $textNode->nodeValue;
      $parent = $textNode->parentNode;

      if (!preg_match_all($regex, $original, $matches, PREG_OFFSET_CAPTURE)) {
        continue;
      }

      $cursor = 0;
      $newNodes = [];

      foreach ($matches[1] as [$matchWord, $bytePos]) {
        $charPos = mb_strlen(mb_strcut($original, 0, $bytePos));

        if ($charPos > $cursor) {
          $newNodes[] = $dom->createTextNode(mb_substr($original, $cursor, $charPos - $cursor));
        }

        $termData = $lookup[mb_strtolower($matchWord)] ?? null;
        if ($termData) {
          $span = $this->createHighlightedTerm($dom, $matchWord, $termData);
          $newNodes[] = $span;
        }

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

  private function createHighlightedTerm(\DOMDocument $dom, string $word, array $termData): \DOMElement {
    $span = $dom->createElement('span', $word);
    $span->setAttribute('class', 'glossary-term');

    $tooltip = $dom->createElement('span', '');
    $tooltip->setAttribute('class', 'glossary-tooltip');

    $tooltipText = $dom->createTextNode($termData['description']);
    $tooltip->appendChild($tooltipText);

    if (!empty($termData['is_truncated']) && !empty($termData['url'])) {
      $dots = $dom->createTextNode('... ');
      $tooltip->appendChild($dots);

      $link = $dom->createElement('a', 'Read more');
      $link->setAttribute('href', $termData['url']);
      $link->setAttribute('class', 'glossary-tooltip-link');
      $link->setAttribute('target', '_blank');
      $link->setAttribute('rel', 'noopener noreferrer');
      $tooltip->appendChild($link);
    }

    $span->appendChild($tooltip);
    return $span;
  }
}
