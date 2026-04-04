<?php

namespace Drupal\glossary_tooltip\Service;

class GlossaryProcessor {

  private GlossaryRepository $repository;
  private GlossaryHighlighter $highlighter;

  public function __construct(GlossaryRepository $repository, GlossaryHighlighter $highlighter) {
    $this->repository = $repository;
    $this->highlighter = $highlighter;
  }

  public function processHtml(string $html): string {
    $terms = $this->repository->getTerms();
    return $this->highlighter->highlight($html, $terms);
  }

  public function getGlossaryTermIds(): array {
    return $this->repository->getGlossaryTermIds();
  }
}
