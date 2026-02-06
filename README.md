# Commerce Stock Variation Filter

A Drupal 10 Commerce module that extends the Add to Cart form to filter product variations based on stock availability.

## Features

- **Smart Default Selection**: Automatically selects the first in-stock variation on initial page load
- **Disabled Attribute Options**: Out-of-stock attribute values are visible but marked as disabled/not clickable
- **Complete Out-of-Stock Handling**: When all variations are out of stock, hides the form and shows an "Out of stock" message
- **AJAX-Safe**: Works seamlessly with Commerce's AJAX variation selection system
- **No Core Hacks**: Uses proper Drupal/Commerce extension points

## Requirements

- Drupal 10.x
- Commerce 2.x (commerce_product, commerce_cart, commerce_order)
- Product variations must have a `field_stock` integer field

## Installation

1. Copy the `commerce_stock_variation_filter` directory to your `modules/custom/` folder
2. Enable the module: `drush en commerce_stock_variation_filter`
3. Clear caches: `drush cr`

## Configuration

### Required: Create the field_stock Field

The module expects a `field_stock` integer field on your product variation entities:

1. Go to `/admin/commerce/config/product-variation-types`
2. Edit your variation type(s)
3. Add a new field:
   - Field type: **Number (integer)**
   - Machine name: `field_stock`
   - Label: "Stock" (or your preference)
4. Save the field

### Optional: Customize Styling

Override the CSS in your theme by targeting these classes:

```css
/* Out of stock message */
.commerce-stock-variation-filter--out-of-stock { }

/* Disabled attribute labels */
.commerce-stock-variation-filter--disabled-label { }

/* Disabled swatches */
.commerce-stock-variation-filter--disabled-swatch { }
```

## How It Works

### Architecture

The module uses these Commerce/Drupal extension points:

| Component | Purpose |
|-----------|---------|
| `hook_form_alter()` | Intercepts Add to Cart form on initial load |
| `StockHelper` service | Centralized stock checking logic |
| `ProductVariationAjaxSubscriber` | Handles AJAX variation change events |
| JavaScript behaviors | Client-side disabled state management |

### Stock Determination Logic

A variation is considered **in stock** when:
- The `field_stock` field exists
- The field has a value
- The value is greater than 0

A variation is considered **out of stock** when:
- The `field_stock` field doesn't exist
- The field value is NULL/empty
- The field value is 0 or negative

### Attribute Disabling Logic

An attribute option is disabled only when **ALL** variations containing that attribute value are out of stock. If even one variation with that attribute value is in stock, the option remains enabled.

Example:
- Red/Small: Out of stock
- Red/Large: In stock
- Blue/Small: In stock

Result: "Red" remains enabled (one variation in stock), "Small" remains enabled (one variation in stock).

## Edge Cases Handled

| Scenario | Behavior |
|----------|----------|
| Single variation product | Renders normally, shows out of stock if field_stock ≤ 0 |
| All variations out of stock | Hides entire form, shows "Out of stock" message |
| Null/missing field_stock value | Treated as out of stock |
| Negative stock value | Treated as out of stock |
| User selects out-of-stock combo via AJAX | Shows warning message |

## Extending the Module

### Custom Stock Field

To use a different field name, modify `StockHelper::isInStock()`:

```php
public function isInStock(ProductVariationInterface $variation): bool {
  // Change 'field_stock' to your field name
  if (!$variation->hasField('field_custom_stock')) {
    return FALSE;
  }
  // ... rest of logic
}
```

### Custom Out of Stock Logic

Extend the `StockHelper` service:

```yaml
# yourmodule.services.yml
services:
  commerce_stock_variation_filter.stock_helper:
    class: Drupal\yourmodule\Service\CustomStockHelper
```

## Troubleshooting

### Module has no effect

1. Verify `field_stock` exists on your variation type
2. Clear all caches: `drush cr`
3. Check `/admin/reports/status` for module warnings

### Disabled options still selectable

1. Verify JavaScript is loading (check browser console)
2. Ensure `core/once` library is available
3. Check for JavaScript errors

### AJAX changes don't update disabled states

1. Verify the event subscriber is registered: `drush devel:services | grep stock`
2. Check that Commerce AJAX is working normally

## License

This module is licensed under the GPL-2.0-or-later license.
