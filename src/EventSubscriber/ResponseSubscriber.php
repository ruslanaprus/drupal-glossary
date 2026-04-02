<?php

namespace Drupal\glossary_tooltip\EventSubscriber;

use Drupal\glossary_tooltip\Service\GlossaryProcessor;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Drupal\Core\Render\HtmlResponse;
use Drupal\Core\Routing\AdminContext;

class ResponseSubscriber implements EventSubscriberInterface {

  protected $processor;
  protected $adminContext;

  public function __construct(GlossaryProcessor $processor, AdminContext $admin_context) {
    $this->processor = $processor;
    $this->adminContext = $admin_context;
  }

  public static function getSubscribedEvents() {
    return [
      KernelEvents::RESPONSE => ['onResponse', -10],
    ];
  }

  public function onResponse(ResponseEvent $event) {
    if ($this->adminContext->isAdminRoute()) {
      return;
    }

    $response = $event->getResponse();

    if (!$response instanceof HtmlResponse) {
      return;
    }

    $content = $response->getContent();
    $processed_content = $this->processor->process($content);
    $response->setContent($processed_content);
  }
}
