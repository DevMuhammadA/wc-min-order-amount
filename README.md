# WooCommerce Minimum Order Amount

Enforce a **minimum order subtotal** with friendly notices.  
- Configurable amount
- Apply after coupons (optional)
- Exclude roles (e.g., administrator, shop_manager)
- Notice in **Cart** and blocking error in **Checkout**
- Shortcode: `[min_order_banner]`

## Installation
1. Upload to `wp-content/plugins/` or install via **Plugins → Add New → Upload Plugin**.
2. Activate **WooCommerce Minimum Order Amount**.
3. Go to **WooCommerce → Min Order Amount** to configure.

## How it calculates
- Uses cart **subtotal (excl. tax, before coupons)**.
- If “Apply after coupons” is ON, discounts are subtracted before comparing to the minimum.
- Shipping & taxes are **not** counted towards the threshold.

## Shortcode
```
[min_order_banner]
```
Renders the same friendly notice used on the cart page.

## Requirements
- WordPress 6.0+
- WooCommerce 6.0+
- PHP 7.4+

## Changelog
- 1.0.0 — Initial release
