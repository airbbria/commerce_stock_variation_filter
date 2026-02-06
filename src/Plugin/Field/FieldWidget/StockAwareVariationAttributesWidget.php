<?php

namespace Drupal\commerce_stock_variation_filter\Plugin\Field\FieldWidget;

use Drupal\commerce_product\Plugin\Field\FieldWidget\ProductVariationAttributesWidget;
use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\commerce_stock_variation_filter\Service\StockHelper;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\Attribute\FieldWidget;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Stock-aware variation attributes widget.
 *
 * Extends the core Commerce attributes widget to:
 * 1. Select the first IN-STOCK variation as default (instead of first overall)
 * 2. Mark out-of-stock attribute options as disabled
 * 3. Hide the form entirely when all variations are out of stock
 *
 * This is the proper Commerce extension point for controlling variation
 * selection behavior - it integrates cleanly with Commerce's AJAX system.
 */
#[FieldWidget(
  id: "commerce_product_variation_attributes_stock",
  label: new TranslatableMarkup("Product variation attributes (Stock-aware)"),
  field_types: ["entity_reference"],
)]
class StockAwareVariationAttributesWidget extends ProductVariationAttributesWidget {

  /**
   * The stock helper service.
   *
   * @var \Drupal\commerce_stock_variation_filter\Service\StockHelper
   */
  protected StockHelper $stockHelper;

  /**
   * {@inheritdoc}
   */
  public static function create(
    ContainerInterface $container,
    array $configuration,
    $plugin_id,
    $plugin_definition
  ) {
    // Use parent::create() to properly inject all parent services
    // (attributeFieldManager, variationAttributeMapper, fieldWidgetManager).
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->stockHelper = $container->get('commerce_stock_variation_filter.stock_helper');
    return $instance;
  }

  /**
   * {@inheritdoc}
   *
   * Override to select first IN-STOCK variation as default.
   *
   * The parent implementation returns the first enabled variation.
   * We override to return the first variation where field_stock > 0.
   */
  protected function getDefaultVariation(ProductInterface $product, array $variations) {
    // First, try to find an in-stock variation.
    $first_in_stock = $this->stockHelper->getFirstInStockVariation($variations);

    if ($first_in_stock) {
      return $first_in_stock;
    }

    // Fallback to parent behavior if no in-stock variation exists.
    // This allows the "all out of stock" logic to handle display.
    return parent::getDefaultVariation($product, $variations);
  }

  /**
   * {@inheritdoc}
   *
   * Override to handle the "all out of stock" case and disable options.
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state) {
    /** @var \Drupal\commerce_product\Entity\ProductInterface $product */
    $product = $form_state->get('product');

    // Load enabled variations using parent's method.
    $variations = $this->loadEnabledVariations($product);

    // Check if ALL variations are out of stock.
    if (!empty($variations) && !$this->stockHelper->hasAnyInStock($variations)) {
      // Signal to hide the form and show "Out of stock" message.
      $form_state->set('hide_form', TRUE);
      $form_state->set('commerce_stock_all_out_of_stock', TRUE);
      return $element;
    }

    // Call parent to build the standard element.
    $element = parent::formElement($items, $delta, $element, $form, $form_state);

    // Store stock data for attribute processing.
    if (!empty($variations)) {
      $stock_map = $this->stockHelper->buildStockMap($variations);
      $form_state->set('commerce_stock_variation_filter_stock_map', $stock_map);
      $form_state->set('commerce_stock_variation_filter_variations', $variations);

      // Process attributes to mark disabled options.
      if (isset($element['attributes'])) {
        $this->processAttributesForStock($element['attributes'], $variations, $stock_map);
      }
    }

    return $element;
  }

  /**
   * Processes attribute elements to disable out-of-stock options.
   *
   * @param array $attributes
   *   The attributes form element array (by reference).
   * @param \Drupal\commerce_product\Entity\ProductVariationInterface[] $variations
   *   Array of product variation entities.
   * @param array $stock_map
   *   Variation ID => is_in_stock boolean map.
   */
  protected function processAttributesForStock(array &$attributes, array $variations, array $stock_map): void {
    // Build attribute to variation mapping.
    $attribute_variation_map = $this->stockHelper->buildAttributeVariationMap($variations);

    foreach ($attributes as $field_name => &$attribute_element) {
      // Skip properties (keys starting with #).
      if (strpos($field_name, '#') === 0 || !is_array($attribute_element)) {
        continue;
      }

      $element_type = $attribute_element['#type'] ?? NULL;
      if (!$element_type) {
        continue;
      }

      // Get disabled values for this attribute.
      $disabled_values = $this->stockHelper->getDisabledAttributeValues(
        $attribute_variation_map,
        $stock_map,
        $field_name
      );

      if (empty($disabled_values)) {
        continue;
      }

      // Store for #after_build and JavaScript.
      $attribute_element['#commerce_stock_disabled_values'] = $disabled_values;
      $attribute_element['#attributes']['data-stock-disabled'] = json_encode($disabled_values);
      $attribute_element['#attributes']['class'][] = 'commerce-stock-variation-filter--processed';

      switch ($element_type) {
        case 'select':
          // Modify option labels for select elements.
          if (isset($attribute_element['#options'])) {
            foreach ($disabled_values as $disabled_value) {
              if (isset($attribute_element['#options'][$disabled_value])) {
                $attribute_element['#options'][$disabled_value] .= ' ' . t('(Out of stock)');
              }
            }
          }
          break;

        case 'radios':
          // Use #after_build for radios (child elements don't exist yet).
          $attribute_element['#after_build'][] = [static::class, 'afterBuildRadios'];
          break;

        case 'commerce_product_rendered_attribute':
          // Use #after_build for rendered attributes.
          $attribute_element['#after_build'][] = [static::class, 'afterBuildRendered'];
          break;
      }
    }
  }

  /**
   * #after_build callback for radio button elements.
   *
   * @param array $element
   *   The form element.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return array
   *   The modified element.
   */
  public static function afterBuildRadios(array $element, FormStateInterface $form_state): array {
    $disabled_values = $element['#commerce_stock_disabled_values'] ?? [];

    foreach ($disabled_values as $disabled_value) {
      if (isset($element[$disabled_value])) {
        // Set the #disabled property which Drupal uses to render disabled attribute.
        $element[$disabled_value]['#disabled'] = TRUE;
        // Also set the attribute directly for extra safety.
        $element[$disabled_value]['#attributes']['disabled'] = 'disabled';
        // Add form-item-disabled class to the wrapper div.
        if (!isset($element[$disabled_value]['#wrapper_attributes']['class'])) {
          $element[$disabled_value]['#wrapper_attributes']['class'] = [];
        }
        $element[$disabled_value]['#wrapper_attributes']['class'][] = 'form-item-disabled';
      }
    }

    return $element;
  }

  /**
   * #after_build callback for rendered attribute elements.
   *
   * @param array $element
   *   The form element.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return array
   *   The modified element.
   */
  public static function afterBuildRendered(array $element, FormStateInterface $form_state): array {
    $disabled_values = $element['#commerce_stock_disabled_values'] ?? [];

    foreach ($disabled_values as $disabled_value) {
      if (isset($element[$disabled_value])) {
        // Add form-item-disabled class to the wrapper div.
        if (!isset($element[$disabled_value]['#wrapper_attributes']['class'])) {
          $element[$disabled_value]['#wrapper_attributes']['class'] = [];
        }
        $element[$disabled_value]['#wrapper_attributes']['class'][] = 'form-item-disabled';
      }
    }

    return $element;
  }

}
