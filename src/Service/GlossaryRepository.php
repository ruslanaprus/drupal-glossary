<?php

namespace Drupal\glossary_tooltip\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Url;
use Psr\Log\LoggerInterface;

class GlossaryRepository {

  private EntityTypeManagerInterface $entityTypeManager;
  private CacheBackendInterface $cache;
  private LoggerInterface $logger;

  public const DESCRIPTION_TRUNCATE_LENGTH = 100;

  public function __construct(EntityTypeManagerInterface $entityTypeManager, CacheBackendInterface $cache, LoggerInterface $logger) {
    $this->entityTypeManager = $entityTypeManager;
    $this->cache = $cache;
    $this->logger = $logger;
  }

  public function getTerms(): array {
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
      if (!$desc) {
        continue;
      }

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

    $this->cache->set($cid, $terms, CacheBackendInterface::CACHE_PERMANENT, ['taxonomy_term_list:glossary']);
    return $terms;
  }

  private function prepareDescription($term): ?string {
    $plain = strip_tags($term->getDescription());
    if (!$plain) {
      return null;
    }

    if (mb_strlen($plain) > self::DESCRIPTION_TRUNCATE_LENGTH) {
      try {
        $url = Url::fromRoute('entity.taxonomy_term.canonical', [
          'taxonomy_term' => $term->id()
        ])->toString();
      } catch (\Exception $e) {
        $this->logger->error('Failed to generate term URL: @message', ['@message' => $e->getMessage()]);
        return mb_substr($plain, 0, self::DESCRIPTION_TRUNCATE_LENGTH);
      }

      return mb_substr($plain, 0, self::DESCRIPTION_TRUNCATE_LENGTH) . "... (Read more at $url)";
    }

    return $plain;
  }

  public function getGlossaryTermIds(): array {
    $storage = $this->entityTypeManager->getStorage('taxonomy_term');
    return $storage->getQuery()
      ->condition('vid', 'glossary')
      ->accessCheck(FALSE)
      ->execute();
  }
}
