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
      $modified = htmlspecialchars($original, ENT_QUOTES, 'UTF-8');

      foreach ($terms as $term) {
        $pattern = '/\b(' . preg_quote($term['word'], '/') . ')\b/i';
        $replacement = '<span class="glossary-term" title="' . htmlspecialchars($term['description'], ENT_QUOTES, 'UTF-8') . '" style="font-weight:bold; text-decoration:underline; cursor:help;">$1</span>';
        $modified = preg_replace($pattern, $replacement, $modified);
      }

      if ($modified !== htmlspecialchars($original, ENT_QUOTES, 'UTF-8')) {
        $fragment = $dom->createDocumentFragment();
        @$fragment->appendXML($modified);
        $textNode->parentNode->replaceChild($fragment, $textNode);
      }
    }
  }
}
