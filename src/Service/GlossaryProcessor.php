<?php

namespace Drupal\glossary_tooltip\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Url;

class GlossaryProcessor {

  private EntityTypeManagerInterface $entityTypeManager;

  public function __construct(EntityTypeManagerInterface $entityTypeManager) {
    $this->entityTypeManager = $entityTypeManager;
  }

  public function process(string $html): string {
    $storage = $this->entityTypeManager->getStorage('taxonomy_term');

    $ids = $storage->getQuery()
      ->condition('vid', 'glossary')
      ->accessCheck(FALSE)
      ->execute();

    if (empty($ids)) {
      return $html;
    }

    $terms = [];
    $entities = $storage->loadMultiple($ids);

    foreach ($entities as $term) {
      $raw_description = $term->getDescription();
      $plain_description = strip_tags($raw_description);

      if (empty($plain_description)) {
        continue;
      }

      $display_description = $plain_description;

      if (mb_strlen($plain_description) > 100) {
        $url = Url::fromRoute('entity.taxonomy_term.canonical', [
          'taxonomy_term' => $term->id()
        ])->toString();

        $display_description = mb_substr($plain_description, 0, 100) . "... (Read more at $url)";
      }

      $terms[] = [
        'word' => $term->getName(),
        'description' => $display_description,
      ];
    }

    if (empty($terms)) {
      return $html;
    }

    $dom = new \DOMDocument();
    libxml_use_internal_errors(TRUE);
    $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();

    $xpath = new \DOMXPath($dom);
    $textNodes = $xpath->query('//text()[not(ancestor::a) and not(ancestor::script) and not(ancestor::style)]');

    foreach ($textNodes as $textNode) {
      $original = $textNode->nodeValue;
      $modified = htmlspecialchars($original, ENT_QUOTES, 'UTF-8');

      foreach ($terms as $term) {
        $word = preg_quote($term['word'], '/');
        $desc = htmlspecialchars($term['description'], ENT_QUOTES, 'UTF-8');

        $pattern = '/\b(' . $word . ')\b/i';
        $replacement = '<span class="glossary-term" title="' . $desc . '" style="font-weight:bold; text-decoration:underline; cursor:help;">$1</span>';

        $modified = preg_replace($pattern, $replacement, $modified);
      }

      if ($modified !== htmlspecialchars($original, ENT_QUOTES, 'UTF-8')) {
        $fragment = $dom->createDocumentFragment();
        @$fragment->appendXML($modified);
        $textNode->parentNode->replaceChild($fragment, $textNode);
      }
    }

    return $dom->saveHTML();
  }
}
