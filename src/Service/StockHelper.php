<?php

namespace Drupal\commerce_stock_variation_filter\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\commerce_product\Entity\ProductVariationInterface;

/**
 * Service providing stock-related helper methods for variation filtering.
 *
 * This service centralizes all stock checking logic to ensure consistency
 * across hooks, widgets, and event subscribers. It also provides caching
 * for performance optimization during form rendering.
 *
 * Stock determination logic:
 * - Checks for field_stock field on variation entity.
 * - Value > 0 = in stock.
 * - Value <= 0, NULL, or field missing = out of stock.
 */
class StockHelper {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * Static cache for stock maps to avoid repeated field access.
   *
   * @var array
   */
  protected static array $stockMapCache = [];

  /**
   * Constructs a new StockHelper service.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager service.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager) {
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * Checks if a single variation is in stock.
   *
   * @param \Drupal\commerce_product\Entity\ProductVariationInterface $variation
   *   The product variation entity.
   *
   * @return bool
   *   TRUE if in stock (field_stock > 0), FALSE otherwise.
   */
  public function isInStock(ProductVariationInterface $variation): bool {
    // Check if the variation has the field_stock field.
    if (!$variation->hasField('field_stock')) {
      // No stock field = treat as out of stock (fail-safe).
      return FALSE;
    }

    $stock_field = $variation->get('field_stock');

    // Check if field has a value.
    if ($stock_field->isEmpty()) {
      // Empty/NULL value = out of stock.
      return FALSE;
    }

    // Get the stock value (integer field).
    $stock_value = (int) $stock_field->value;

    // Only positive stock counts are considered "in stock".
    return $stock_value > 0;
  }

  /**
   * Builds a stock availability map for multiple variations.
   *
   * @param \Drupal\commerce_product\Entity\ProductVariationInterface[] $variations
   *   Array of product variation entities.
   *
   * @return array
   *   Associative array: variation_id => bool (TRUE if in stock).
   */
  public function buildStockMap(array $variations): array {
    $stock_map = [];

    foreach ($variations as $variation) {
      $variation_id = $variation->id();
      $stock_map[$variation_id] = $this->isInStock($variation);
    }

    return $stock_map;
  }

  /**
   * Finds the first in-stock variation from a list.
   *
   * Iterates through variations in their existing order and returns
   * the first one with positive stock. Useful for setting default selection.
   *
   * @param \Drupal\commerce_product\Entity\ProductVariationInterface[] $variations
   *   Array of product variation entities.
   *
   * @return \Drupal\commerce_product\Entity\ProductVariationInterface|null
   *   The first in-stock variation, or NULL if none found.
   */
  public function getFirstInStockVariation(array $variations): ?ProductVariationInterface {
    foreach ($variations as $variation) {
      if ($this->isInStock($variation)) {
        return $variation;
      }
    }

    return NULL;
  }

  /**
   * Checks if any variation in the list is in stock.
   *
   * @param \Drupal\commerce_product\Entity\ProductVariationInterface[] $variations
   *   Array of product variation entities.
   *
   * @return bool
   *   TRUE if at least one variation has stock > 0.
   */
  public function hasAnyInStock(array $variations): bool {
    foreach ($variations as $variation) {
      if ($this->isInStock($variation)) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Builds a map of attribute values to their variations.
   *
   * Used to determine which attribute options should be disabled based on
   * which variations they belong to.
   *
   * @param \Drupal\commerce_product\Entity\ProductVariationInterface[] $variations
   *   Array of product variation entities.
   *
   * @return array
   *   Nested array: attribute_field_name => attribute_value_id => [variation_ids].
   */
  public function buildAttributeVariationMap(array $variations): array {
    $map = [];

    foreach ($variations as $variation) {
      $variation_id = $variation->id();

      // Get all attribute fields from the variation.
      // Attribute fields are entity reference fields to product attribute values.
      $field_definitions = $variation->getFieldDefinitions();

      foreach ($field_definitions as $field_name => $field_definition) {
        // Check if this is an attribute field.
        // Attribute fields use 'commerce_product_attribute_value' as target type.
        if ($field_definition->getType() !== 'entity_reference') {
          continue;
        }

        $handler_settings = $field_definition->getSetting('handler_settings') ?? [];
        $target_type = $field_definition->getSetting('target_type');

        // Verify this is an attribute reference field.
        if ($target_type !== 'commerce_product_attribute_value') {
          continue;
        }

        // Get the attribute value ID.
        $attribute_field = $variation->get($field_name);
        if ($attribute_field->isEmpty()) {
          continue;
        }

        $attribute_value_id = $attribute_field->target_id;

        // Add to the map.
        if (!isset($map[$field_name])) {
          $map[$field_name] = [];
        }
        if (!isset($map[$field_name][$attribute_value_id])) {
          $map[$field_name][$attribute_value_id] = [];
        }
        $map[$field_name][$attribute_value_id][] = $variation_id;
      }
    }

    return $map;
  }

  /**
   * Gets in-stock variation IDs from a list of variations.
   *
   * @param \Drupal\commerce_product\Entity\ProductVariationInterface[] $variations
   *   Array of product variation entities.
   *
   * @return array
   *   Array of variation IDs that are in stock.
   */
  public function getInStockVariationIds(array $variations): array {
    $in_stock_ids = [];

    foreach ($variations as $variation) {
      if ($this->isInStock($variation)) {
        $in_stock_ids[] = $variation->id();
      }
    }

    return $in_stock_ids;
  }

  /**
   * Filters variations to only return those in stock.
   *
   * @param \Drupal\commerce_product\Entity\ProductVariationInterface[] $variations
   *   Array of product variation entities.
   *
   * @return \Drupal\commerce_product\Entity\ProductVariationInterface[]
   *   Filtered array containing only in-stock variations.
   */
  public function filterInStockVariations(array $variations): array {
    return array_filter($variations, function (ProductVariationInterface $variation) {
      return $this->isInStock($variation);
    });
  }

  /**
   * Determines which attribute values should be marked as disabled.
   *
   * An attribute value is disabled if ALL variations containing it are
   * out of stock. If even one variation with that attribute value is
   * in stock, the attribute value remains enabled.
   *
   * @param array $attribute_variation_map
   *   Map from buildAttributeVariationMap().
   * @param array $stock_map
   *   Map from buildStockMap().
   * @param string $attribute_field_name
   *   The attribute field name to check.
   *
   * @return array
   *   Array of attribute value IDs that should be disabled.
   */
  public function getDisabledAttributeValues(array $attribute_variation_map, array $stock_map, string $attribute_field_name): array {
    $disabled_values = [];

    if (!isset($attribute_variation_map[$attribute_field_name])) {
      return $disabled_values;
    }

    foreach ($attribute_variation_map[$attribute_field_name] as $attribute_value_id => $variation_ids) {
      $has_in_stock_variation = FALSE;

      foreach ($variation_ids as $variation_id) {
        if (!empty($stock_map[$variation_id])) {
          $has_in_stock_variation = TRUE;
          break;
        }
      }

      if (!$has_in_stock_variation) {
        $disabled_values[] = $attribute_value_id;
      }
    }

    return $disabled_values;
  }

}
