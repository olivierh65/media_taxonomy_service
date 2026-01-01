<?php

namespace Drupal\media_taxonomy_service\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\media_taxonomy_service\Service\DirectoryService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controller for taxonomy directory management operations.
 */
class TaxonomyDirectoryController extends ControllerBase {

  /**
   * The directory service.
   *
   * @var \Drupal\media_taxonomy_service\Service\DirectoryService
   */
  protected $directoryService;

  /**
   * Constructs a TaxonomyDirectoryController object.
   */
  public function __construct(DirectoryService $directory_service) {
    $this->directoryService = $directory_service;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('media_taxonomy_service.directory_service')
    );
  }

  /**
   * Create a new taxonomy term via AJAX.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The HTTP request.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with the new term ID or error message.
   */
  public function createTerm(Request $request) {
    // Check permission.
    if (!$this->currentUser()->hasPermission('manage media_drop albums')) {
      return new JsonResponse(['success' => FALSE, 'message' => 'Access denied'], 403);
    }

    $data = json_decode($request->getContent(), TRUE);

    if (!isset($data['vocabulary_id'], $data['name'])) {
      return new JsonResponse(['success' => FALSE, 'message' => 'Missing parameters'], 400);
    }

    $vocabulary_id = $data['vocabulary_id'];
    $term_name = trim($data['name']);
    $parent_id = $data['parent_id'] ?? 0;

    if (empty($term_name)) {
      return new JsonResponse(['success' => FALSE, 'message' => 'Term name cannot be empty'], 400);
    }

    try {
      // Create the term using the service.
      $term = $this->directoryService->createDirectoryTerm($vocabulary_id, $term_name, $parent_id);

      return new JsonResponse([
        'success' => TRUE,
        'term_id' => $term->id(),
        'term_name' => $term->getName(),
      ]);
    }
    catch (\Exception $e) {
      return new JsonResponse([
        'success' => FALSE,
        'message' => $e->getMessage(),
      ], 400);
    }
  }

  /**
   * Delete a taxonomy term via AJAX.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The HTTP request.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with success status.
   */
  public function deleteTerm(Request $request) {
    // Check permission.
    if (!$this->currentUser()->hasPermission('manage media_drop albums')) {
      return new JsonResponse(['success' => FALSE, 'message' => 'Access denied'], 403);
    }

    $data = json_decode($request->getContent(), TRUE);

    if (!isset($data['term_id'])) {
      return new JsonResponse(['success' => FALSE, 'message' => 'Missing term_id'], 400);
    }

    $term_id = $data['term_id'];

    try {
      $this->directoryService->deleteDirectoryTerm($term_id);

      return new JsonResponse(['success' => TRUE]);
    }
    catch (\Exception $e) {
      return new JsonResponse([
        'success' => FALSE,
        'message' => $e->getMessage(),
      ], 400);
    }
  }

  /**
   * Move a taxonomy term (change parent) via AJAX.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The HTTP request.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with success status.
   */
  public function moveTerm(Request $request) {
    // Check permission.
    if (!$this->currentUser()->hasPermission('manage media_drop albums')) {
      return new JsonResponse(['success' => FALSE, 'message' => 'Access denied'], 403);
    }

    $data = json_decode($request->getContent(), TRUE);

    if (!isset($data['term_id'], $data['parent_id'])) {
      return new JsonResponse(['success' => FALSE, 'message' => 'Missing parameters'], 400);
    }

    $term_id = $data['term_id'];
    $parent_id = $data['parent_id'];
    $weights = $data['weights'] ?? [];

    try {
      $this->directoryService->moveDirectoryTerm($term_id, $parent_id);

      // Update weights for all affected terms if provided.
      if (!empty($weights)) {
        $term_storage = $this->entityTypeManager()->getStorage('taxonomy_term');
        foreach ($weights as $affected_term_id => $weight) {
          $term = $term_storage->load($affected_term_id);
          if ($term && $term->hasField('weight')) {
            $term->set('weight', (int) $weight);
            $term->save();
          }
        }
      }

      return new JsonResponse(['success' => TRUE]);
    }
    catch (\Exception $e) {
      return new JsonResponse([
        'success' => FALSE,
        'message' => $e->getMessage(),
      ], 400);
    }
  }

  /**
   * Update a taxonomy term via AJAX.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The HTTP request.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with success status.
   */
  public function updateTerm(Request $request) {
    // Check permission.
    if (!$this->currentUser()->hasPermission('manage media_drop albums')) {
      return new JsonResponse(['success' => FALSE, 'message' => 'Access denied'], 403);
    }

    $data = json_decode($request->getContent(), TRUE);

    if (!isset($data['term_id'])) {
      return new JsonResponse(['success' => FALSE, 'message' => 'Missing term_id'], 400);
    }

    try {
      $term_storage = $this->entityTypeManager()->getStorage('taxonomy_term');
      $term = $term_storage->load($data['term_id']);

      if (!$term) {
        return new JsonResponse(['success' => FALSE, 'message' => 'Term not found'], 404);
      }

      // Update name.
      if (isset($data['name'])) {
        $term->set('name', $data['name']);
      }

      // Update description.
      if (isset($data['description'])) {
        $term->set('description', $data['description']);
      }

      $term->save();

      return new JsonResponse(['success' => TRUE]);
    }
    catch (\Exception $e) {
      return new JsonResponse([
        'success' => FALSE,
        'message' => $e->getMessage(),
      ], 400);
    }
  }

  /**
   * Get term data via AJAX.
   *
   * @param int $term_id
   *   The term ID.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with term data.
   */
  public function getTerm($term_id) {
    // Check permission.
    if (!$this->currentUser()->hasPermission('manage media_drop albums')) {
      return new JsonResponse(['success' => FALSE, 'message' => 'Access denied'], 403);
    }

    try {
      $term_storage = $this->entityTypeManager()->getStorage('taxonomy_term');
      $term = $term_storage->load($term_id);

      if (!$term) {
        return new JsonResponse(['success' => FALSE, 'error' => 'Term not found'], 404);
      }

      return new JsonResponse([
        'success' => TRUE,
        'data' => [
          'id' => $term->id(),
          'name' => $term->getName(),
          'description' => $term->getDescription(),
          'vid' => $term->bundle(),
        ],
      ]);
    }
    catch (\Exception $e) {
      return new JsonResponse([
        'success' => FALSE,
        'error' => $e->getMessage(),
      ], 400);
    }
  }

}
