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

  public function getGlossaryData(): array {
    $cid = 'glossary_tooltip:terms_data';
    if ($cache = $this->cache->get($cid)) {
      return $cache->data;
    }

    $storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $ids = $storage->getQuery()
      ->condition('vid', 'glossary')
      ->accessCheck(FALSE)
      ->execute();

    if (empty($ids)) {
      return ['terms' => [], 'ids' => []];
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
        $entry = [
          'word' => $word,
          'description' => $desc,
        ];

        if (is_array($desc) && isset($desc['url'])) {
          $entry['url'] = $desc['url'];
          $entry['description'] = $desc['text'];
          $entry['is_truncated'] = TRUE;
        } else {
          $entry['is_truncated'] = FALSE;
        }
        $terms[] = $entry;
      }
    }

    $data = ['terms' => $terms, 'ids' => array_values($ids)];
    $this->cache->set($cid, $data, CacheBackendInterface::CACHE_PERMANENT, ['taxonomy_term_list:glossary']);
    return $data;
  }

  private function prepareDescription($term) {
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

      return [
        'text' => mb_substr($plain, 0, self::DESCRIPTION_TRUNCATE_LENGTH) . "...",
        'url' => $url,
      ];
    }

    return $plain;
  }
}
