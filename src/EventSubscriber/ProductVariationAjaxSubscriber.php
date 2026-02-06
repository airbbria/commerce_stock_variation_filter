<?php

namespace Drupal\commerce_stock_variation_filter\EventSubscriber;

use Drupal\commerce_product\Event\ProductDefaultVariationEvent;
use Drupal\commerce_product\Event\ProductEvents;
use Drupal\commerce_product\Event\ProductVariationAjaxChangeEvent;
use Drupal\commerce_stock_variation_filter\Service\StockHelper;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Drupal\Core\Ajax\InvokeCommand;

/**
 * Subscribes to product variation AJAX change events for stock filtering.
 *
 * This subscriber ensures that stock filtering behavior persists through
 * Commerce's AJAX variation selection system. When a user changes an attribute
 * (e.g., selects a different color), Commerce fires an AJAX event. This
 * subscriber intercepts that event to:
 *
 * 1. Add stock availability data to the AJAX response.
 * 2. Trigger JavaScript to re-apply disabled states to attribute options.
 * 3. Handle edge cases where the selected combination is out of stock.
 *
 * Why use an event subscriber instead of just form_alter:
 * - Commerce's AJAX system rebuilds form elements after variation change.
 * - Form alter alone would require tracking complex state across requests.
 * - Event subscriber provides a clean hook into Commerce's AJAX pipeline.
 */
class ProductVariationAjaxSubscriber implements EventSubscriberInterface {

  /**
   * The stock helper service.
   *
   * @var \Drupal\commerce_stock_variation_filter\Service\StockHelper
   */
  protected StockHelper $stockHelper;

  /**
   * Constructs a new ProductVariationAjaxSubscriber.
   *
   * @param \Drupal\commerce_stock_variation_filter\Service\StockHelper $stock_helper
   *   The stock helper service.
   */
  public function __construct(StockHelper $stock_helper) {
    $this->stockHelper = $stock_helper;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      ProductEvents::PRODUCT_DEFAULT_VARIATION => ['onProductDefaultVariation', 100],
      ProductEvents::PRODUCT_VARIATION_AJAX_CHANGE => ['onVariationAjaxChange', 100],
    ];
  }

  /**
   * Responds to product default variation event.
   *
   * This ensures the first IN-STOCK variation is selected as default
   * instead of just the first variation.
   *
   * @param \Drupal\commerce_product\Event\ProductDefaultVariationEvent $event
   *   The product default variation event.
   */
  public function onProductDefaultVariation(ProductDefaultVariationEvent $event): void {
    $product = $event->getProduct();
    $default_variation = $event->getDefaultVariation();

    // If there's no default variation, nothing to do.
    if (!$default_variation) {
      return;
    }

    // Check if the current default is already in stock.
    if ($this->stockHelper->isInStock($default_variation)) {
      return;
    }

    // Current default is out of stock, find the first in-stock variation.
    $variations = $product->getVariations();
    $first_in_stock = $this->stockHelper->getFirstInStockVariation($variations);

    if ($first_in_stock) {
      $event->setDefaultVariation($first_in_stock);
    }
  }

  /**
   * Responds to product variation AJAX change events.
   *
   * @param \Drupal\commerce_product\Event\ProductVariationAjaxChangeEvent $event
   *   The product variation AJAX change event.
   */
  public function onVariationAjaxChange(ProductVariationAjaxChangeEvent $event): void {
    $variation = $event->getProductVariation();
    $response = $event->getResponse();

    // Check the stock status of the newly selected variation.
    $is_in_stock = $this->stockHelper->isInStock($variation);

    // Get the product to build stock map for all variations.
    $product = $variation->getProduct();
    if (!$product) {
      return;
    }

    /** @var \Drupal\commerce_product\ProductVariationStorageInterface $variation_storage */
    $variation_storage = \Drupal::entityTypeManager()->getStorage('commerce_product_variation');
    $all_variations = $variation_storage->loadEnabled($product);

    $stock_map = $this->stockHelper->buildStockMap($all_variations);
    $attribute_variation_map = $this->stockHelper->buildAttributeVariationMap($all_variations);

    // Build disabled attribute values map for JavaScript.
    $disabled_attributes = [];
    foreach (array_keys($attribute_variation_map) as $field_name) {
      $disabled_values = $this->stockHelper->getDisabledAttributeValues(
        $attribute_variation_map,
        $stock_map,
        $field_name
      );
      if (!empty($disabled_values)) {
        $disabled_attributes[$field_name] = $disabled_values;
      }
    }

    // Add command to invoke JavaScript stock filter refresh.
    // This calls our custom JS function to update disabled states.
    $response->addCommand(new InvokeCommand(
      NULL,
      'commerceStockVariationFilterRefresh',
      [
        [
          'stockMap' => $stock_map,
          'disabledAttributes' => $disabled_attributes,
          'selectedVariationId' => $variation->id(),
          'selectedVariationInStock' => $is_in_stock,
        ],
      ]
    ));
  }

}
