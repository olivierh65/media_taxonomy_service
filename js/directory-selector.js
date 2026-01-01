/**
 * @file
 * JavaScript for media-taxonomy-service directory tree selection using jstree.
 */

(function (Drupal, once) {
  'use strict';

  /**
   * Initialize jstree for directory selection.
   */
  Drupal.behaviors.mediaTaxonomyDirectorySelector = {
    attach: function (context) {
      once('media-taxonomy-jstree-init', '#media-drop-directory-tree', context).forEach(function (element) {
        var $tree = (typeof jQuery !== 'undefined') ? jQuery(element) : window.jQuery(element);

        // Get tree data from drupalSettings (Drupal 8+ global variable).
        var treeData = [];
        if (typeof drupalSettings !== 'undefined' && drupalSettings.mediaDrop) {
          treeData = drupalSettings.mediaDrop.directoryTree || [];
        }

        var selectedTerm = $tree.attr('data-selected-term');

        // Initialize jstree with drag-and-drop and context menu support.
        $tree.jstree({
          'core': {
            'data': treeData,
            'check_callback': true,
            'themes': {
              'name': 'default',
              'icons': true,
              'stripes': true,
            },
          },
          'plugins': ['wholerow', 'contextmenu', 'dnd'],
          'contextmenu': {
            'items': function (node) {
              return {
                'create': {
                  'label': Drupal.t('Create directory'),
                  'action': function (obj) {
                    createDirectory(node, $tree, vocabularyId);
                  },
                },
                'rename': {
                  'label': Drupal.t('Rename'),
                  'action': function (obj) {
                    $tree.jstree('edit', node);
                  },
                },
                'delete': {
                  'label': Drupal.t('Delete'),
                  'action': function (obj) {
                    if (confirm(Drupal.t('Are you sure?'))) {
                      deleteTerm(node.data.term_id, $tree);
                    }
                  },
                },
              };
            },
          },
        });

        // Handle node selection.
        $tree.on('changed.jstree', function (e, data) {
          if (data.action === 'select_node') {
            var selected = $tree.jstree('get_selected', true);
            if (selected.length > 0) {
              var nodeId = selected[0].id;
              var selectedField = document.getElementById('media-drop-selected-term');
              if (selectedField) {
                selectedField.value = nodeId;
              }
            }
          }
        });

        // Handle drag-and-drop to update node positions.
        $tree.on('move_node.jstree', function (e, data) {
          // Node has been moved to a new parent
          var node = data.node;
          var newParentId = data.parent === '#' ? 0 : data.parent;
          var oldParentId = data.old_parent === '#' ? 0 : data.old_parent;

          // Recalculate weights for old and new parents
          if (oldParentId !== newParentId) {
            recalculateWeightsForParent($tree, oldParentId);
          }
          recalculateWeightsForParent($tree, newParentId);

          // Collect all new weights for all affected terms
          var nodes = $tree.jstree(true).get_json('#', { flat: true });
          var weightsToUpdate = {};

          nodes.forEach(function (n) {
            if (n.data && n.data.term_id) {
              var nodeParentId = n.parent === '#' ? 0 : getNodeParentId($tree, n.parent, nodes);
              if (nodeParentId === oldParentId || nodeParentId === newParentId) {
                weightsToUpdate[n.data.term_id] = n.data.weight || 0;
              }
            }
          });

          // Send AJAX request to save the new parent and weights
          jQuery.ajax({
            url: '/admin/taxonomy-service/directory/move-term',
            type: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({
              term_id: node.id,
              parent_id: newParentId,
              weights: weightsToUpdate,
            }),
            success: function (response) {
              if (response.success) {
                console.log('Node ' + node.id + ' moved to parent ' + newParentId);
              } else {
                alert(Drupal.t('Error moving directory: @error', { '@error': response.message || 'Unknown error' }));
                $tree.jstree('refresh');
              }
            },
            error: function (xhr, status, error) {
              alert(Drupal.t('Error moving directory: @error', { '@error': error }));
              $tree.jstree('refresh');
            },
          });
        });

        // If a term was pre-selected, select it.
        if (selectedTerm) {
          $tree.jstree('select_node', selectedTerm);
        }
      });
    },
  };

  /**
   * Handle creation of new directory term via AJAX.
   */
  Drupal.behaviors.mediaTaxonomyCreateDirectory = {
    attach: function (context) {
      var jQuery = (typeof jQuery !== 'undefined') ? jQuery : window.jQuery;
      var $form = jQuery('form[id*="album"]', context);
      if ($form.length === 0) {
        return;
      }

      // We could add form submission handler here for directory creation.
      // For now, the form submission is handled server-side.
    },
  };

  /**
   * Get the term_id of a parent node from its jstree node id.
   *
   * @param {jQuery} $tree
   *   The jstree container jQuery object.
   * @param {string} parentNodeId
   *   The jstree node ID of the parent.
   * @param {Array} nodes
   *   Array of all nodes from jstree.
   *
   * @returns {number}
   *   The term_id of the parent, or 0 if root.
   */
  function getNodeParentId($tree, parentNodeId, nodes) {
    if (!nodes || nodes.length === 0) {
      return 0;
    }
    var parentNode = nodes.find(function (n) { return n.id === parentNodeId; });
    return (parentNode && parentNode.data && parentNode.data.term_id) ? parentNode.data.term_id : 0;
  }

  /**
   * Calculate the weight of a term based on its position among siblings.
   * Weight is the index position within siblings.
   *
   * @param {jQuery} $tree
   *   The jstree container jQuery object.
   * @param {number} parentId
   *   The term_id of the parent (0 for root).
   *
   * @returns {number}
   *   The number of children found.
   */
  function calculateTermWeight($tree, parentId) {
    var parentJstreeId = parentId === 0 ? '#' : parentId;
    var $parent = $tree.jstree(true).get_node(parentJstreeId);

    if (!$parent) {
      return 0;
    }

    var childrenIds = $parent.children || [];
    var index = 0;

    for (var i = 0; i < childrenIds.length; i++) {
      var childNode = $tree.jstree(true).get_node(childrenIds[i]);
      if (childNode && childNode.data) {
        childNode.data.weight = index;
        index++;
      }
    }

    return index;
  }

  /**
   * Recalculate all weights for children of a parent node.
   * Weights are assigned sequentially (0, 1, 2, ...) based on visual order.
   *
   * @param {jQuery} $tree
   *   The jstree container jQuery object.
   * @param {number} parentId
   *   The term_id of the parent (0 for root).
   */
  function recalculateWeightsForParent($tree, parentId) {
    var parentJstreeId = parentId === 0 ? '#' : parentId;
    var $parent = $tree.jstree(true).get_node(parentJstreeId);

    if (!$parent) {
      return;
    }

    var childrenIds = $parent.children || [];
    var weight = 0;

    childrenIds.forEach(function (childId) {
      var childNode = $tree.jstree(true).get_node(childId);
      if (childNode) {
        if (!childNode.data) {
          childNode.data = {};
        }
        childNode.data.weight = weight;
        weight++;
      }
    });
  }

  /**
   * Create a new directory term via AJAX.
   *
   * @param {Object} parentNode
   *   The parent jstree node.
   * @param {jQuery} $tree
   *   The jstree container jQuery object.
   * @param {string} vocabularyId
   *   The vocabulary ID.
   */
  function createDirectory(parentNode, $tree, vocabularyId) {
    var termName = prompt(Drupal.t('Enter the name for the new directory:'));

    if (!termName || termName.trim() === '') {
      return;
    }

    var parentId = parentNode.data && parentNode.data.term_id ? parentNode.data.term_id : 0;

    // AJAX call to create the term
    jQuery.ajax({
      url: '/admin/taxonomy-service/directory/create-term',
      type: 'POST',
      contentType: 'application/json',
      data: JSON.stringify({
        vocabulary_id: vocabularyId,
        name: termName,
        parent_id: parentId,
      }),
      success: function (response) {
        if (response.success && response.term_id) {
          // Add the new node to jstree
          var newNode = {
            id: response.term_id,
            text: termName,
            data: {
              term_id: response.term_id,
              weight: 0,
            },
            children: [],
          };

          $tree.jstree('create_node', parentNode, newNode, 'last', false, false);
          Drupal.announce(Drupal.t('Directory "@name" has been created.', { '@name': termName }));
        } else {
          alert(Drupal.t('Error creating directory: @error', { '@error': response.message || 'Unknown error' }));
        }
      },
      error: function (xhr, status, error) {
        alert(Drupal.t('Error creating directory: @error', { '@error': error }));
      },
    });
  }

  /**
   * Delete a term via AJAX.
   *
   * @param {string} termId
   *   The term ID to delete.
   * @param {jQuery} $tree
   *   The jstree container jQuery object.
   */
  function deleteTerm(termId, $tree) {
    jQuery.ajax({
      url: '/admin/taxonomy-service/directory/delete-term',
      type: 'POST',
      contentType: 'application/json',
      data: JSON.stringify({
        term_id: termId,
      }),
      success: function (response) {
        if (response.success) {
          // Find and delete the node from jstree
          var nodeId = termId.toString();
          var nodes = $tree.jstree('get_node', function (node) {
            return node.data && node.data.term_id == termId;
          });

          if (nodes) {
            $tree.jstree('delete_node', nodes);
            Drupal.announce(Drupal.t('Directory has been deleted.'));
          }
        } else {
          alert(Drupal.t('Error deleting directory: @error', { '@error': response.message || 'Unknown error' }));
        }
      },
      error: function (xhr, status, error) {
        alert(Drupal.t('Error deleting directory: @error', { '@error': error }));
      },
    });
  }

})(Drupal, once);
