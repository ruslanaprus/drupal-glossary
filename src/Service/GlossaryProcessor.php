<?php

namespace Drupal\glossary_tooltip\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;

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
      if ($term->hasField('field_description')) {
        $terms[] = [
          'word' => $term->getName(),
          'description' => $term->get('field_description')->value ?? '',
        ];
      }
    }

    if (empty($terms)) {
      return $html;
    }

    $dom = new \DOMDocument();
    libxml_use_internal_errors(TRUE);
    $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
    libxml_clear_errors();

    $xpath = new \DOMXPath($dom);
    $textNodes = $xpath->query('//text()');

    foreach ($textNodes as $textNode) {
      $original = $textNode->nodeValue;
      $modified = $original;

      foreach ($terms as $term) {
        if (empty($term['description'])) {
          continue;
        }

        $word = $term['word'];
        $description = htmlspecialchars($term['description']);

        if (stripos($modified, $word) !== FALSE) {
          $replacement = '<span class="glossary-term" title="' . $description . '">' . $word . '</span>';

          $modified = str_ireplace($word, $replacement, $modified);
        }
      }

      if ($modified !== $original) {
        $fragment = $dom->createDocumentFragment();
        $fragment->appendXML($modified);
        $textNode->parentNode->replaceChild($fragment, $textNode);
      }
    }

    return $dom->saveHTML();
  }
}
