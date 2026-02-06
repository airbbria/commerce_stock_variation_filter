/**
 * @file
 * JavaScript behaviors for Commerce Stock Variation Filter.
 *
 * Handles client-side stock filtering for:
 * - Disabling attribute options that only belong to out-of-stock variations.
 * - Updating disabled states after AJAX variation changes.
 */

(function ($, Drupal, drupalSettings, once) {

  'use strict';

  /**
   * Behavior for initializing stock variation filter on page load.
   */
  Drupal.behaviors.commerceStockVariationFilter = {
    attach: function (context, settings) {
      // Process attribute containers with stock-disabled data.
      once('commerce-stock-filter-container', '[data-stock-disabled]', context)
        .forEach(function (element) {
          Drupal.commerceStockVariationFilter.processAttributeContainer(element);
        });
    }
  };

  /**
   * Namespace for stock variation filter functions.
   */
  Drupal.commerceStockVariationFilter = Drupal.commerceStockVariationFilter || {};

  /**
   * Processes an attribute container to disable out-of-stock options.
   *
   * @param {HTMLElement} container
   *   The attribute container element with data-stock-disabled attribute.
   */
  Drupal.commerceStockVariationFilter.processAttributeContainer = function (container) {
    var disabledValues = [];

    try {
      disabledValues = JSON.parse(container.getAttribute('data-stock-disabled') || '[]');
    }
    catch (e) {
      console.warn('Commerce Stock Filter: Could not parse disabled values', e);
      return;
    }

    if (!disabledValues.length) {
      return;
    }

    // Create a map for quick lookup (convert to strings for comparison).
    var disabledMap = {};
    disabledValues.forEach(function (value) {
      disabledMap[String(value)] = true;
    });

    var $container = $(container);
    var tagName = container.tagName.toLowerCase();

    // Handle select elements.
    if (tagName === 'select') {
      Drupal.commerceStockVariationFilter.processSelectElement(container, disabledMap);
      return;
    }

    // Handle radio buttons within the container.
    $container.find('input[type="radio"]').each(function () {
      var $radio = $(this);
      var value = String($radio.val());

      if (disabledMap[value]) {
        // Disable the radio button.
        $radio.prop('disabled', true);
        // Add class to wrapper for styling.
        $radio.closest('.js-form-item, .form-item').addClass('form-item-disabled');
      }
    });
  };

  /**
   * Processes a select element to disable out-of-stock options.
   *
   * @param {HTMLSelectElement} selectElement
   *   The select element to process.
   * @param {Object} disabledMap
   *   Map of disabled values.
   */
  Drupal.commerceStockVariationFilter.processSelectElement = function (selectElement, disabledMap) {
    // If disabledMap not provided, parse from attribute.
    if (!disabledMap) {
      var disabledValues = [];
      try {
        disabledValues = JSON.parse(selectElement.getAttribute('data-stock-disabled') || '[]');
      }
      catch (e) {
        return;
      }
      disabledMap = {};
      disabledValues.forEach(function (value) {
        disabledMap[String(value)] = true;
      });
    }

    // Mark options as disabled.
    var options = selectElement.querySelectorAll('option');
    options.forEach(function (option) {
      if (disabledMap[String(option.value)]) {
        option.disabled = true;
      }
    });

    // Prevent selection of disabled options.
    $(selectElement).on('change.commerceStockFilter', function () {
      if (disabledMap[String(this.value)]) {
        var firstEnabled = selectElement.querySelector('option:not([disabled])');
        if (firstEnabled) {
          this.value = firstEnabled.value;
          $(this).trigger('change');
        }
      }
    });
  };

  /**
   * Refreshes stock filter states after AJAX variation change.
   *
   * This function is called via Drupal.AjaxCommands.prototype.invoke
   * from the ProductVariationAjaxSubscriber.
   *
   * @param {object} data
   *   The stock data from the server:
   *   - stockMap: variation_id => bool
   *   - disabledAttributes: field_name => [disabled_value_ids]
   *   - selectedVariationId: The newly selected variation ID
   *   - selectedVariationInStock: bool
   */
  $.fn.commerceStockVariationFilterRefresh = function (data) {
    if (!data) {
      return;
    }

    var disabledAttributes = data.disabledAttributes || {};

    // Update each attribute field.
    Object.keys(disabledAttributes).forEach(function (fieldName) {
      var disabledValues = disabledAttributes[fieldName];
      var disabledMap = {};

      disabledValues.forEach(function (value) {
        disabledMap[String(value)] = true;
      });

      // Find the attribute widget by field name.
      var $wrapper = $('[data-drupal-selector*="' + fieldName.replace(/_/g, '-') + '"]');

      // Handle select elements.
      $wrapper.find('select').each(function () {
        var $select = $(this);
        $select.find('option').each(function () {
          var $option = $(this);
          if (disabledMap[String($option.val())]) {
            $option.prop('disabled', true);
          }
          else {
            $option.prop('disabled', false);
          }
        });
        $select.attr('data-stock-disabled', JSON.stringify(disabledValues));
      });

      // Handle radio buttons.
      $wrapper.find('input[type="radio"]').each(function () {
        var $radio = $(this);
        var value = String($radio.val());
        var $formItem = $radio.closest('.js-form-item, .form-item');

        if (disabledMap[value]) {
          $radio.prop('disabled', true);
          $formItem.addClass('form-item-disabled');
        }
        else {
          $radio.prop('disabled', false);
          $formItem.removeClass('form-item-disabled');
        }
      });
    });
  };

})(jQuery, Drupal, drupalSettings, once);
