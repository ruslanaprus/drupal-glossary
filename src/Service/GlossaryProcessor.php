<?php

namespace Drupal\glossary_tooltip\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Url;
use Drupal\Core\Cache\CacheBackendInterface;
use Psr\Log\LoggerInterface;

class GlossaryProcessor {

  private EntityTypeManagerInterface $entityTypeManager;
  private CacheBackendInterface $cache;
  private LoggerInterface $logger;

  public const DESCRIPTION_TRUNCATE_LENGTH = 100;
  public const XPATH_TEXT_NODES = '//text()[not(ancestor::a) and not(ancestor::script) and not(ancestor::style)]';

  public function __construct(EntityTypeManagerInterface $entityTypeManager, CacheBackendInterface $cache, LoggerInterface $logger) {
    $this->entityTypeManager = $entityTypeManager;
    $this->cache = $cache;
    $this->logger = $logger;
  }

  public function process(string $html): string {
    if ($html === '') {
      return '';
    }

    $terms = $this->loadGlossaryTerms();
    if (empty($terms)) {
      return $html;
    }

    $dom = new \DOMDocument();
    libxml_use_internal_errors(TRUE);
    $success = $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    $errors = libxml_get_errors();
    libxml_clear_errors();

    if (!$success || !$dom->documentElement) {
      $this->logger->warning('GlossaryProcessor: Failed to parse HTML. Errors: @errors', [
        '@errors' => json_encode($errors),
      ]);
      return $html;
    }

    $this->highlightTerms($dom, $terms);

    return $dom->saveHTML();
  }

  private function loadGlossaryTerms(): array {
    $cid = 'glossary_tooltip:terms';
    if ($cache = $this->cache->get($cid)) {
      return $cache->data;
    }

    $storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $ids = $storage->getQuery()
      ->condition('vid', 'glossary')
      ->accessCheck(FALSE)
      ->execute();

    if (empty($ids)) {
      return [];
    }

    $terms = [];
    foreach ($storage->loadMultiple($ids) as $term) {
      $desc = $this->prepareDescription($term);
      if ($desc) {
        $words = [$term->getName()];
        if ($term->hasField('field_synonyms') && !$term->get('field_synonyms')->isEmpty()) {
          foreach ($term->get('field_synonyms')->getValue() as $item) {
            if (!empty($item['value'])) {
              $words[] = $item['value'];
            }
          }
        }

        foreach ($words as $word) {
          $terms[] = [
            'word' => $word,
            'description' => $desc,
          ];
        }
      }
    }

    $this->cache->set($cid, $terms, CacheBackendInterface::CACHE_PERMANENT, ['taxonomy_term_list:glossary']);
    return $terms;
  }

  private function prepareDescription($term): ?string {
    $plain = strip_tags($term->getDescription());
    if (!$plain) {
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

    $lookup = [];
    foreach ($terms as $term) {
      $lookup[mb_strtolower($term['word'])] = $term['description'];
    }

    $words = array_keys($lookup);
    usort($words, fn($a, $b) => mb_strlen($b) <=> mb_strlen($a));

    $regex = '/(?<=^|[^\p{L}])(' . implode('|', array_map(fn($w) => preg_quote($w, '/'), $words)) . ')(?=$|[^\p{L}])/iu';

    foreach ($textNodes as $textNode) {
      $original = $textNode->nodeValue;
      $parent = $textNode->parentNode;

      if (!preg_match_all($regex, $original, $matches, PREG_OFFSET_CAPTURE)) {
        continue;
      }

      $cursor = 0;
      $newNodes = [];

      foreach ($matches[1] as [$matchWord, $pos]) {
        if ($pos > $cursor) {
          $newNodes[] = $dom->createTextNode(substr($original, $cursor, $pos - $cursor));
        }

        $desc = $lookup[mb_strtolower($matchWord)] ?? '';
        $span = $dom->createElement('span', $matchWord);
        $span->setAttribute('class', 'glossary-term');
        $span->setAttribute('title', htmlspecialchars($desc, ENT_QUOTES, 'UTF-8'));
        $span->setAttribute('style', 'font-weight:bold; text-decoration:underline; cursor:help;');
        $newNodes[] = $span;

        $cursor = $pos + strlen($matchWord);
      }

      if ($cursor < strlen($original)) {
        $newNodes[] = $dom->createTextNode(substr($original, $cursor));
      }

      if ($newNodes) {
        foreach ($newNodes as $node) {
          $parent->insertBefore($node, $textNode);
        }
        $parent->removeChild($textNode);
      }
    }
  }
}
