<?php

namespace Drupal\glossary_tooltip\Plugin\Filter;

use Drupal\filter\Plugin\FilterBase;
use Drupal\filter\FilterProcessResult;
use Drupal\glossary_tooltip\Service\GlossaryProcessor;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * @Filter(
 *   id = "glossary_filter",
 *   title = @Translation("Glossary Tooltip Filter"),
 *   description = @Translation("Adds tooltips for glossary terms."),
 *   type = Drupal\filter\Plugin\FilterInterface::TYPE_TRANSFORM_REVERSIBLE
 * )
 */
class GlossaryFilter extends FilterBase implements ContainerFactoryPluginInterface {

  protected GlossaryProcessor $processor;

  public function __construct(array $configuration, $plugin_id, $plugin_definition, GlossaryProcessor $processor) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->processor = $processor;
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('glossary_tooltip.processor')
    );
  }

  public function process($text, $langcode) {
    $processed = $this->processor->processHtml($text);
    $result = new FilterProcessResult($processed);
    $termIds = $this->processor->getGlossaryTermIds();
    $tags = array_map(fn($id) => "taxonomy_term:$id", $termIds);
    $result->addCacheTags($tags);
    $result->addAttachments(['library' => ['glossary_tooltip/glossary_tooltip']]);
    return $result;
  }
}
