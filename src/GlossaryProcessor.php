<?php

namespace Drupal\glossary_tooltip;

class GlossaryProcessor {

  private GlossaryRepository $repository;
  private GlossaryHighlighter $highlighter;

  public function __construct(GlossaryRepository $repository, GlossaryHighlighter $highlighter) {
    $this->repository = $repository;
    $this->highlighter = $highlighter;
  }

  public function processHtml(string $html): string {
    $data = $this->getGlossaryData();
    return $this->highlighter->highlight($html, $data['terms']);
  }

  public function getGlossaryData(): array {
    return $this->repository->getGlossaryData();
  }
}
