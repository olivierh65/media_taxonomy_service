<?php

namespace Drupal\media_taxonomy_service\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Service for managing taxonomy-based directories with jstree.
 */
class DirectoryService {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs a DirectoryService object.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager) {
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * Create a new taxonomy term for directory management.
   *
   * @param string $vocabulary_id
   *   The vocabulary ID.
   * @param string $term_name
   *   The term name.
   * @param int $parent_id
   *   The parent term ID (0 for root).
   *
   * @return \Drupal\taxonomy\Entity\Term
   *   The created term.
   *
   * @throws \Exception
   */
  public function createDirectoryTerm($vocabulary_id, $term_name, $parent_id = 0) {
    $term_storage = $this->entityTypeManager->getStorage('taxonomy_term');

    $term = $term_storage->create([
      'vid' => $vocabulary_id,
      'name' => $term_name,
      'parent' => $parent_id,
    ]);

    $term->save();

    return $term;
  }

  /**
   * Delete a taxonomy term.
   *
   * @param int $term_id
   *   The term ID to delete.
   *
   * @throws \Exception
   */
  public function deleteDirectoryTerm($term_id) {
    $term_storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $term = $term_storage->load($term_id);

    if ($term) {
      $term->delete();
    }
  }

  /**
   * Update parent of a taxonomy term.
   *
   * @param int $term_id
   *   The term ID.
   * @param int $parent_id
   *   The new parent ID (0 for root).
   *
   * @throws \Exception
   */
  public function moveDirectoryTerm($term_id, $parent_id) {
    $term_storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $term = $term_storage->load($term_id);

    if ($term) {
      $term->parent = $parent_id;
      $term->save();
    }
  }

  /**
   * Get directory tree data formatted for jstree.
   *
   * @param string $vocabulary_id
   *   The vocabulary ID.
   * @param int $selected_tid
   *   Optional selected term ID.
   *
   * @return array
   *   Tree data for jstree.
   */
  public function getDirectoryTreeData($vocabulary_id, $selected_tid = NULL) {
    $tree_data = [];
    $terms = $this->entityTypeManager->getStorage('taxonomy_term')
      ->loadTree($vocabulary_id, 0, 1, TRUE);

    foreach ($terms as $term) {
      $tree_data[] = $this->buildTreeNode($term, $vocabulary_id, $selected_tid);
    }

    return $tree_data;
  }

  /**
   * Build a jstree node from a taxonomy term.
   *
   * @param \Drupal\taxonomy\Entity\Term $term
   *   The taxonomy term.
   * @param string $vocabulary_id
   *   The vocabulary ID.
   * @param int $selected_tid
   *   Optional selected term ID.
   *
   * @return array
   *   Node data for jstree.
   */
  protected function buildTreeNode($term, $vocabulary_id, $selected_tid = NULL) {
    $children = [];
    $child_terms = $this->entityTypeManager->getStorage('taxonomy_term')
      ->loadTree($vocabulary_id, $term->id(), 1, TRUE);

    foreach ($child_terms as $child_term) {
      $children[] = $this->buildTreeNode($child_term, $vocabulary_id, $selected_tid);
    }

    return [
      'id' => $term->id(),
      'text' => $term->getName(),
      'data' => [
        'term_id' => $term->id(),
        'weight' => $term->get('weight')->value ?? 0,
      ],
      'children' => $children,
      'state' => [
        'selected' => $selected_tid && $selected_tid == $term->id(),
      ],
    ];
  }

  /**
   * Build the file path from a taxonomy term's breadcrumb.
   *
   * @param \Drupal\taxonomy\Entity\Term $term
   *   The taxonomy term.
   *
   * @return string
   *   The path constructed from term names.
   */
  public function buildTermPath($term) {
    $path_parts = [];

    $current_term = $term;
    while ($current_term) {
      array_unshift($path_parts, $current_term->getName());

      if ($current_term->parent && !empty($current_term->parent->target_id)) {
        $current_term = $this->entityTypeManager->getStorage('taxonomy_term')
          ->load($current_term->parent->target_id);
      }
      else {
        break;
      }
    }

    return implode('/', $path_parts);
  }

}
