<?php

namespace Drupal\glossary_tooltip\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Url;

class GlossaryProcessor {

  private EntityTypeManagerInterface $entityTypeManager;

  public const DESCRIPTION_TRUNCATE_LENGTH = 100;
  public const XPATH_TEXT_NODES = '//text()[not(ancestor::a) and not(ancestor::script) and not(ancestor::style)]';

  public function __construct(EntityTypeManagerInterface $entityTypeManager) {
    $this->entityTypeManager = $entityTypeManager;
  }

  public function process(string $html): string {
    $terms = $this->loadGlossaryTerms();

    if (empty($terms)) {
      return $html;
    }

    $dom = new \DOMDocument();
    libxml_use_internal_errors(TRUE);
    $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();

    $this->highlightTerms($dom, $terms);

    return $dom->saveHTML();
  }

  private function loadGlossaryTerms(): array {
    $storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $ids = $storage->getQuery()
      ->condition('vid', 'glossary')
      ->accessCheck(FALSE)
      ->execute();

    if (empty($ids)) {
      return [];
    }

    $terms = [];
    $entities = $storage->loadMultiple($ids);

    foreach ($entities as $term) {
      $desc = $this->prepareDescription($term);
      if ($desc) {
        $terms[] = [
          'word' => $term->getName(),
          'description' => $desc,
        ];
      }
    }
    return $terms;
  }

  private function prepareDescription($term): ?string {
    $raw = $term->getDescription();
    $plain = strip_tags($raw);

    if (empty($plain)) {
      return null;
    }

    if (mb_strlen($plain) > self::DESCRIPTION_TRUNCATE_LENGTH) {
      $url = Url::fromRoute('entity.taxonomy_term.canonical', [
        'taxonomy_term' => $term->id()
      ])->toString();

      return mb_substr($plain, 0, self::DESCRIPTION_TRUNCATE_LENGTH) . "... (Read more at $url)";
    }

    return $plain;
  }

  private function highlightTerms(\DOMDocument $dom, array $terms): void {
    $xpath = new \DOMXPath($dom);
    $textNodes = $xpath->query(self::XPATH_TEXT_NODES);

    foreach ($textNodes as $textNode) {
      $original = $textNode->nodeValue;
      $parent = $textNode->parentNode;

      $cursor = 0;
      $newNodes = [];

      foreach ($terms as $term) {
        $pattern = '/\b(' . preg_quote($term['word'], '/') . ')\b/i';

        if (preg_match_all($pattern, $original, $matches, PREG_OFFSET_CAPTURE)) {
          foreach ($matches[1] as $match) {
            [$word, $pos] = $match;

            if ($pos > $cursor) {
              $newNodes[] = $dom->createTextNode(substr($original, $cursor, $pos - $cursor));
            }

            $span = $dom->createElement('span', $word);
            $span->setAttribute('class', 'glossary-term');
            $span->setAttribute(
              'title',
              htmlspecialchars($term['description'], ENT_QUOTES, 'UTF-8')
            );
            $span->setAttribute(
              'style',
              'font-weight:bold; text-decoration:underline; cursor:help;'
            );
            $newNodes[] = $span;

            $cursor = $pos + strlen($word);
          }
        }
      }

      if ($cursor < strlen($original)) {
        $newNodes[] = $dom->createTextNode(substr($original, $cursor));
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
