<?php

namespace Drupal\media_taxonomy_service\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\taxonomy\Entity\Term;
use Drupal\media\MediaInterface;
use Drupal\field\Entity\FieldConfig;
use Psr\Log\LoggerInterface;

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
   * The file system service.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  /**
   * The logger service.
   *
   * @var Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * Constructs a DirectoryService object.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    FileSystemInterface $file_system,
    LoggerInterface $logger,
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->fileSystem = $file_system;
    $this->logger = $logger;
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

  /**
   * Get the full children hierarchy starting from a taxonomy term ID.
   *
   * The vocabulary ID is automatically resolved from the term.
   *
   * @param int $term_id
   *   The root taxonomy term ID.
   *
   * @return array
   *   Recursive array representing the directory tree.
   *
   *   Example:
   *   [
   *     'tid' => 12,
   *     'name' => 'Photos',
   *     'path' => 'Photos/Vacances/2024',
   *     'children' => [
   *       ...
   *     ],
   *   ]
   */
  public function getDirectoryTreeFromTermId($term_id) {
    $term_storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $root_term = $term_storage->load($term_id);

    if (!$root_term) {
      return [];
    }

    $vid = $root_term->bundle();

    return $this->buildDirectoryTree($root_term, $vid);
  }

  /**
   * Recursively build directory tree array from a taxonomy term.
   *
   * @param \Drupal\taxonomy\Entity\Term $term
   *   The current taxonomy term.
   * @param string $vocabulary_id
   *   The vocabulary ID.
   *
   * @return array
   *   Recursive directory structure.
   */
  protected function buildDirectoryTree($term, $vocabulary_id) {
    $children_tree = [];

    $children = $this->entityTypeManager
      ->getStorage('taxonomy_term')
      ->loadTree($vocabulary_id, $term->id(), 1, TRUE);

    foreach ($children as $child) {
      $children_tree[] = $this->buildDirectoryTree($child, $vocabulary_id);
    }

    return [
      'tid' => $term->id(),
      'name' => $term->getName(),
      'path' => $this->buildTermPath($term),
      'children' => $children_tree,
    ];
  }

  /**
   * Get the full hierarchy starting from a taxonomy term ID representing a leaf.
   * to the root.
   *
   * The vocabulary ID is automatically resolved from the term.
   *
   * @param int $term_id
   *   The root taxonomy term ID.
   *
   * @return array
   *   Recursive array representing the directory tree.
   *
   *   Example:
   *   [
   *     'tid' => 12,
   *     'name' => 'Photos',
   *     'path' => 'Photos/Vacances/2024',
   *     'children' => [
   *       ...
   *     ],
   *   ]
   */
  public function getDirectoryTreeFromLeafTermId($term_id) {
    $term_storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $term = $term_storage->load($term_id);

    if (!$term) {
      return [];
    }

    return $this->buildDirectoryTreeFromLeaf($term);
  }

  /**
   * Build a directory path tree from root to a leaf term.
   *
   * @param \Drupal\taxonomy\Entity\Term $term
   *   Leaf taxonomy term.
   *
   * @return array
   *   Linear directory tree (one child per level).
   */
  protected function buildDirectoryPathFromLeaf($term) {
    $current = [
      'tid' => $term->id(),
      'name' => $term->getName(),
      'path' => $this->buildTermPath($term),
      'children' => [],
    ];

    /** @var \Drupal\taxonomy\Entity\Term|null $parent */
    $parent = $term->get('parent')->entity;

    // Cas racine → on retourne le noeud seul.
    if (!$parent) {
      return $current;
    }

    // On remonte et on IMBRIQUE, pas on ajoute.
    $parent_tree = $this->buildDirectoryPathFromLeaf($parent);
    $parent_tree['children'] = [$current];

    return $parent_tree;
  }

  /**
   * Ensure that directories defined by a taxonomy tree exist on filesystem.
   *
   * @throws \RuntimeException
   */
  public function ensureDirectoriesExist(array $directory_tree, string $base_directory) {
    try {
      if (!file_exists($base_directory)) {
        $this->fileSystem->prepareDirectory(
        $base_directory,
        FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS
        );
      }
    }
    catch (\Exception $e) {
      $this->logger->error(
      'Failed to create base directory @dir: @message',
      [
        '@dir' => $base_directory,
        '@message' => $e->getMessage(),
      ]
      );

      throw new \RuntimeException(
        sprintf('Unable to create base directory: %s', $base_directory),
        0,
        $e
      );
    }

    if (!empty($directory_tree['children'])) {
      foreach ($directory_tree['children'] as $child) {
        $this->createDirectoryRecursive($child, $base_directory);
      }
    }
  }

  /**
   * Recursively create directories from taxonomy tree.
   *
   * @throws \RuntimeException
   */
  protected function createDirectoryRecursive(array $node, string $parent_directory) {
    $directory_name = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $node['name']);
    $current_directory = $parent_directory . '/' . $directory_name;

    try {
      if (!file_exists($current_directory)) {
        $this->fileSystem->prepareDirectory(
        $current_directory,
        FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS
        );
      }
    }
    catch (\Exception $e) {
      $this->logger->error(
      'Failed to create directory @dir for term @tid: @message',
      [
        '@dir' => $current_directory,
        '@tid' => $node['tid'],
        '@message' => $e->getMessage(),
      ]
      );

      // On stoppe uniquement cette branche.
      throw new \RuntimeException(
        sprintf('Unable to create directory: %s', $current_directory),
        0,
        $e
      );
    }

    if (!empty($node['children'])) {
      foreach ($node['children'] as $child) {
        $this->createDirectoryRecursive($child, $current_directory);
      }
    }
  }

  /**
   * Get or create a taxonomy term by name with optional parent.
   *
   * @param string $vocabulary_id
   *   The vocabulary ID.
   * @param string $term_name
   *   The term name.
   * @param int $parent_tid
   *   The parent term ID (0 for root).
   *
   * @return int|null
   *   The term ID if found or created, NULL otherwise.
   */
  public function getOrCreateTerm($vocabulary_id, $term_name, $parent_tid = 0) {
    // Check if the term already exists.
    $query = $this->entityTypeManager
      ->getStorage('taxonomy_term')
      ->getQuery()
      ->condition('vid', $vocabulary_id)
      ->condition('name', $term_name)
      ->accessCheck(FALSE);

    if ($parent_tid > 0) {
      $query->condition('parent', $parent_tid);
    }

    $tids = $query->execute();

    if (!empty($tids)) {
      // The term already exists.
      return reset($tids);
    }

    // Create the new term.
    $term = Term::create([
      'vid' => $vocabulary_id,
      'name' => $term_name,
      'parent' => $parent_tid > 0 ? [$parent_tid] : [],
    ]);

    $term->save();

    return $term->id();
  }

  /**
   * Clean up empty terms without associated media.
   *
   * @param string $vocabulary_id
   *   The vocabulary ID.
   * @param string $field_name
   *   The media field name that references the taxonomy (default: 'directory').
   */
  public function cleanupEmptyTerms($vocabulary_id, $field_name = 'directory') {
    $terms = $this->entityTypeManager
      ->getStorage('taxonomy_term')
      ->loadByProperties(['vid' => $vocabulary_id]);

    foreach ($terms as $term) {
      // Check if any media uses this term.
      $query = $this->entityTypeManager
        ->getStorage('media')
        ->getQuery()
        ->condition($field_name, $term->id())
        ->accessCheck(FALSE);

      $count = $query->count()->execute();

      if ($count == 0) {
        // No media uses this term, it can be deleted.
        $term->delete();
      }
    }
  }

  /**
   * Ensure that a directory exists for a specific taxonomy term.
   *
   * Creates the full directory path from root to the term.
   *
   * @param int $term_id
   *   The term ID.
   * @param string $uri_scheme
   *   The URI scheme (public or private).
   *
   * @return bool
   *   TRUE if successful, FALSE otherwise.
   */
  public function ensureTermDirectoryExists($term_id, $uri_scheme) {
    if (!$term_id || $term_id <= 0) {
      return TRUE;
    }

    try {
      $term = $this->entityTypeManager->getStorage('taxonomy_term')->load($term_id);
      if (!$term) {
        return FALSE;
      }

      // Build the complete directory path for this term.
      $directory_path = $this->buildDirectoryPathFromTerm($term_id);

      // Get the base directory for the URI scheme.
      $wrapper = \Drupal::service('stream_wrapper_manager')->getViaScheme($uri_scheme);
      if (!$wrapper) {
        $this->logger->warning('No stream wrapper found for scheme @scheme', [
          '@scheme' => $uri_scheme,
        ]);
        return FALSE;
      }

      $base_directory = $wrapper->getDirectoryPath();
      $full_directory_path = $base_directory . '/' . $directory_path;

      // Create the directory if it doesn't exist.
      if (!file_exists($full_directory_path)) {
        $this->fileSystem->prepareDirectory(
          $full_directory_path,
          FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS
        );
      }

      return TRUE;
    }
    catch (\Exception $e) {
      $this->logger->error('Error ensuring term directory exists: @message', [
        '@message' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * Move media files to the new directory corresponding to the taxonomy term.
   *
   * @param \Drupal\media\MediaInterface $media
   *   The media entity.
   * @param int|null $new_term_id
   *   The ID of the new directory term (NULL or 0 for root).
   * @param bool $is_leaf
   *   Whether the new term is a leaf in the taxonomy tree.
   *
   * @return bool
   *   TRUE if the move was successful, FALSE otherwise.
   */
  public function moveMediaFilesToDirectory($media, $new_term_id = NULL, $is_leaf = FALSE) {
    if (!$media) {
      return FALSE;
    }

    try {
      // Get the media URI scheme first.
      $uri_scheme = $this->getMediaURIScheme($media);
      if (!$uri_scheme) {
        $this->logger->notice('Could not determine URI scheme for media @mid', ['@mid' => $media->id()]);
        return FALSE;
      }

      // If a new term is specified, ensure the directory structure exists.
      if ($new_term_id && $new_term_id > 0) {
        if (!$this->ensureTermDirectoryExists($new_term_id, $uri_scheme)) {
          $this->logger->warning('Could not ensure term directory exists for term @tid', [
            '@tid' => $new_term_id,
          ]);
        }
      }

      // Build the target path based on the directory term.
      $target_path = $uri_scheme . '://' . $this->buildDirectoryPathFromTerm($new_term_id);

      // Retrieve the media's file fields.
      $file_fields = $this->getMediaFileFields($media);

      if (empty($file_fields)) {
        $this->logger->notice('Media @mid has no file fields', ['@mid' => $media->id()]);
        return TRUE;
      }

      $moved_any = FALSE;
      $media_modified = FALSE;
      $file_system = \Drupal::service('file_system');
      $file_usage = \Drupal::service('file.usage');

      foreach ($file_fields as $field_name) {
        if ($media->hasField($field_name)) {
          $field_values = $media->get($field_name)->getValue();

          foreach ($field_values as $delta => $value) {
            if (isset($value['target_id'])) {
              $file = $this->entityTypeManager->getStorage('file')->load($value['target_id']);

              if ($file) {
                $old_uri = $file->getFileUri();
                $is_image = strpos($file->getMimeType(), 'image/') === 0;
                $filename = basename($old_uri);
                $new_uri = $target_path . '/' . $filename;

                // Skip if already in the right location.
                if ($old_uri === $new_uri) {
                  $this->logger->debug('File @file already in target location', ['@file' => $filename]);
                  continue;
                }

                try {
                  // Ensure target directory exists.
                  $directory = dirname($new_uri);
                  $file_system->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);

                  // Handle potential filename conflicts.
                  $new_uri = $file_system->getDestinationFilename($new_uri, FileSystemInterface::EXISTS_RENAME);

                  // Move the physical file on disk.
                  $result = $file_system->move($old_uri, $new_uri, FileSystemInterface::EXISTS_RENAME);

                  if ($result) {
                    // Update the file entity URI (SANS créer un nouveau fichier).
                    $file->setFileUri($result);
                    $file->save();
                    $usage = $file_usage->listUsage($file);
                    if (empty($usage)) {
                      // Ré-enregistrer l'usage si perdu.
                      $file_usage->add($file, 'file', 'media', $media->id());
                      $this->logger->warning('Had to re-add file_usage for file @fid', ['@fid' => $file->id()]);
                    }

                    // Clear image style derivatives for images.
                    if ($is_image) {
                      image_path_flush($old_uri);
                    }

                    $moved_any = TRUE;

                    $this->logger->info('Moved file from @old to @new (file @fid)', [
                      '@old' => $old_uri,
                      '@new' => $result,
                      '@fid' => $file->id(),
                    ]);
                  }
                  else {
                    $this->logger->warning('Failed to move file @old to @new', [
                      '@old' => $old_uri,
                      '@new' => $new_uri,
                    ]);
                  }
                }
                catch (\Exception $e) {
                  $this->logger->warning('Exception moving file @old to @new: @error', [
                    '@old' => $old_uri,
                    '@new' => $new_uri,
                    '@error' => $e->getMessage(),
                  ]);
                }
              }
            }
          }
        }
      }

      // Note: No need to save media entity since file IDs haven't changed.
      $file = $this->entityTypeManager->getStorage('file')->load($value['target_id']);
      if ($file) {
        $usage = $file_usage->listUsage($file);
        $this->logger->info('Post-move check for file @fid: status=@status, usage=@usage', [
          '@fid' => $file->id(),
          '@status' => $file->get('status')->value,
          '@usage' => json_encode($usage),
        ]);
      }

      return $moved_any;
    }
    catch (\Exception $e) {
      $this->logger->error('Error moving media files: @message', [
        '@message' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * Build the physical path based on a directory term.
   *
   * @param int|null $term_id
   *   The ID of the directory term (NULL or 0 for root).
   *
   * @return string
   *   The physical path (e.g., 'public://2025-12' or 'public://').
   */
  public function buildDirectoryPathFromTerm($term_id = NULL) {
    // If no term or 0, return the root of the public scheme.
    if (!$term_id || $term_id == 0) {
      return '';
    }

    try {
      $term = $this->entityTypeManager->getStorage('taxonomy_term')->load($term_id);

      if (!$term) {
        return '';
      }

      // Build the path from the term and its parents.
      $path_parts = [];
      $current_term = $term;

      while ($current_term) {
        array_unshift($path_parts, $current_term->getName());

        // Retrieve the parent.
        $parent = $current_term->get('parent');
        if ($parent && !empty($parent->target_id)) {
          $current_term = $this->entityTypeManager->getStorage('taxonomy_term')
            ->load($parent->target_id);
        }
        else {
          break;
        }
      }

      return implode('/', $path_parts);
    }
    catch (\Exception $e) {
      $this->logger->error('Error building directory path: @message', [
        '@message' => $e->getMessage(),
      ]);
      return '';
    }
  }

  /**
   * Retrieves the names of fields containing files in a media entity.
   *
   * @param \Drupal\media\MediaInterface $media
   *   The media entity.
   *
   * @return array
   *   Array of names of fields containing files.
   */
  protected function getMediaFileFields($media) {
    $file_fields = [];

    try {
      $bundle = $media->bundle();
      $field_configs = $this->entityTypeManager->getStorage('field_config')
        ->loadByProperties([
          'entity_type' => 'media',
          'bundle' => $bundle,
        ]);

      foreach ($field_configs as $field_config) {
        $field_type = $field_config->getType();
        // Include image, file, video_file, and audio_file fields.
        if (in_array($field_type, ['image', 'file', 'video_file', 'audio_file'])) {
          $file_fields[] = $field_config->getName();
        }
      }
    }
    catch (\Exception $e) {
      $this->logger->error('Error getting media file fields: @message', [
        '@message' => $e->getMessage(),
      ]);
    }

    return $file_fields;
  }

  /**
   * Returns the uri_scheme (public|private) of the file/image field of a Media.
   *
   * @param \Drupal\media\MediaInterface $media
   *   The Media entity.
   *
   * @return string|null
   *   'public', 'private' or NULL if no field found.
   */
  public function getMediaURIScheme(MediaInterface $media): ?string {
    $field_name = $this->getMediaFileFields($media);
    if (empty($field_name)) {
      return NULL;
    }
    else {
      $field_config = FieldConfig::loadByName(
        'media',
        $media->bundle(),
        $field_name[0]
      );

      if ($field_config) {
        // 'public' or 'private'
        return $field_config->getSetting('uri_scheme') ?? 'public';
      }
    }
  }

  /**
   * Get the Media Directories vocabulary ID.
   *
   * @return string|null
   *   The vocabulary ID or NULL if Media Directories is not enabled.
   */
  public function getMediaDirectoriesVocabulary() {
    if (!\Drupal::moduleHandler()->moduleExists('media_directories')) {
      return NULL;
    }

    $config = \Drupal::config('media_directories.settings');
    return $config->get('directory_taxonomy');
  }

  /**
   * Create or retrieve a taxonomy term for a directory with optional subfolder.
   *
   * @param int $depot_id
   *   The ID of the depot.
   * @param string $user_folder_name
   *   The name of the user folder (e.g., "olivier.duchemin").
   * @param string|null $subfolder_name
   *   The optional subfolder name (e.g., "morning").
   *
   * @return int|null
   *   The ID of the created/found term, or NULL if Media Directories is not enabled.
   */
  public function ensureDirectoryTerm($depot_id, $user_folder_name, $subfolder_name = NULL) {
    $vocabulary_id = $this->getMediaDirectoriesVocabulary();

    if (!$vocabulary_id) {
      return NULL;
    }

    // Retrieving the depot to get the parent term.
    $database = \Drupal::database();
    $depot = $database->select('media_drop_depots', 'a')
      ->fields('a')
      ->condition('id', $depot_id)
      ->execute()
      ->fetchObject();

    if (!$depot) {
      return NULL;
    }

    // Parent term = the depot directory (if set)
    $parent_tid = !empty($depot->media_directory) ? $depot->media_directory : 0;

    // 1. Create/retrieve the term for the user folder
    $user_term_id = $this->getOrCreateTerm($vocabulary_id, $user_folder_name, $parent_tid);

    // 2. If a subfolder is specified, create it under the user folder
    if (!empty($subfolder_name)) {
      return $this->getOrCreateTerm($vocabulary_id, $subfolder_name, $user_term_id);
    }

    return $user_term_id;
  }

  /**
   * Create the term structure for a complete depot.
   *
   * @param int $depot_id
   *   The ID of the depot.
   * @param string $depot_name
   *   The name of the depot.
   *
   * @return int|null
   *   The ID of the created depot term.
   */
  public function createDepotDirectoryStructure($depot_id, $depot_name) {
    $vocabulary_id = $this->getMediaDirectoriesVocabulary();

    if (!$vocabulary_id) {
      return NULL;
    }

    // Create a term for the depot itself if it doesn't exist.
    $depot_term_id = $this->getOrCreateTerm($vocabulary_id, $depot_name, 0);

    // Update the depot with this term.
    $database = \Drupal::database();
    $database->update('media_drop_depots')
      ->fields(['media_directory' => $depot_term_id])
      ->condition('id', $depot_id)
      ->execute();

    return $depot_term_id;
  }

  /**
   * Retreive the settings of a media type.
   *
   * @param string $media_type_id
   *   System ID of the media type (e.g., 'image', 'document').
   *
   * @return array|null
   *   Array of settings or NULL if the media type does not exist.
   */
  public function getMediaSourceFieldSettings(string $media_type) : ?array {
    $media_type = $this->entityTypeManager
      ->getStorage('media_type')
      ->load($media_type);

    if (!$media_type) {
      return NULL;
    }
    // Récupération du plugin source.
    $source = $media_type->getSource();

    // Nom du champ source (ex: field_media_image, field_media_file).
    $field_name = $source
      ->getSourceFieldDefinition($media_type)
      ->getName();

    // Chargement de la config du champ (FieldConfig).
    $field_config = FieldConfig::loadByName(
    'media',
    $media_type->id(),
    $field_name
    );

    return $field_config?->getSettings();
  }

}
