# Coupon API Documentation

## Overview

The LazyChat Coupon API provides three endpoints for managing WooCommerce coupons. All endpoints require authentication via Bearer Token or WooCommerce Consumer Key/Secret.

**Base URL:** `/wp-json/lazychat/v1/coupons`

**Authentication Methods:**
- Bearer Token (Header: `Authorization: Bearer YOUR_TOKEN`)
- WooCommerce Consumer Key/Secret (in request body)

---

## Endpoints

### 1. Create or Update Coupon
`POST /wp-json/lazychat/v1/coupons/create-or-update`

Creates a new coupon or updates an existing one.

#### Request Body Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `id` | integer | No | Coupon ID (required for updates) |
| `code` | string | Yes* | Coupon code (*required for new coupons) |
| `discount_type` | string | Yes* | Discount type: `percent`, `fixed_cart`, or `fixed_product` (*required for new) |
| `amount` | number | Yes* | Discount amount (*required for new) |
| `description` | string | No | Coupon description |
| `minimum_amount` | number | No | Minimum order amount required |
| `maximum_amount` | number | No | Maximum order amount allowed |
| `individual_use` | boolean | No | If true, cannot be used with other coupons |
| `product_ids` | array | No | Array of product IDs coupon applies to |
| `excluded_product_ids` | array | No | Array of product IDs coupon does not apply to |
| `product_categories` | array | No | Array of category IDs coupon applies to |
| `excluded_product_categories` | array | No | Array of category IDs coupon does not apply to |
| `email_restrictions` | array | No | Array of email addresses allowed to use coupon |
| `exclude_sale_items` | boolean | No | If true, coupon cannot be used on sale items |
| `usage_limit` | integer | No | Maximum number of times coupon can be used |
| `usage_limit_per_user` | integer | No | Maximum number of times a single user can use coupon |
| `limit_usage_to_x_items` | integer | No | Maximum number of items coupon applies to |
| `date_expires` | string | No | Expiration date in Y-m-d format (e.g., "2024-12-31") |
| `free_shipping` | boolean | No | If true, grants free shipping |

#### Example Requests

**Create Basic Percentage Coupon:**
```json
{
  "code": "SUMMER2024",
  "discount_type": "percent",
  "amount": "20",
  "description": "Summer sale 20% off"
}
```

**Create Fixed Cart Discount:**
```json
{
  "code": "SAVE10",
  "discount_type": "fixed_cart",
  "amount": "10",
  "description": "$10 off entire cart",
  "minimum_amount": "50"
}
```

**Create Fixed Product Discount:**
```json
{
  "code": "PRODUCT5",
  "discount_type": "fixed_product",
  "amount": "5",
  "description": "$5 off specific products",
  "product_ids": [123, 456, 789]
}
```

**Create Coupon with Full Options:**
```json
{
  "code": "WELCOME10",
  "discount_type": "fixed_cart",
  "amount": "10",
  "description": "Welcome discount for new customers",
  "minimum_amount": "50",
  "maximum_amount": "500",
  "individual_use": true,
  "product_ids": [123, 456],
  "excluded_product_ids": [111],
  "product_categories": [5, 8],
  "excluded_product_categories": [3],
  "email_restrictions": ["customer@example.com"],
  "exclude_sale_items": true,
  "usage_limit": 100,
  "usage_limit_per_user": 1,
  "limit_usage_to_x_items": 5,
  "date_expires": "2024-12-31",
  "free_shipping": false
}
```

**Create Free Shipping Coupon:**
```json
{
  "code": "FREESHIP",
  "discount_type": "fixed_cart",
  "amount": "0",
  "description": "Free shipping on orders over $50",
  "minimum_amount": "50",
  "free_shipping": true
}
```

**Update Existing Coupon:**
```json
{
  "id": 123,
  "amount": "25",
  "description": "Updated: Summer sale 25% off",
  "usage_limit": 200
}
```

#### Response

**Success (200):**
```json
{
  "success": true,
  "message": "Coupon created successfully. Coupon ID: 123",
  "coupon": {
    "id": 123,
    "code": "SUMMER2024",
    "amount": "20",
    "discount_type": "percent",
    "description": "Summer sale 20% off",
    "status": "active",
    "created_via": "lazychat",
    "date_created": "2024-12-29T19:00:00",
    "date_modified": "2024-12-29T19:00:00",
    "date_expires": "2024-12-31T00:00:00",
    "minimum_amount": "0",
    "maximum_amount": "0",
    "individual_use": false,
    "product_ids": [],
    "excluded_product_ids": [],
    "product_categories": [],
    "excluded_product_categories": [],
    "email_restrictions": [],
    "exclude_sale_items": false,
    "usage_limit": 0,
    "usage_limit_per_user": 0,
    "limit_usage_to_x_items": 0,
    "usage_count": 0,
    "used_by": [],
    "free_shipping": false
  },
  "plugin_version": "1.4.8"
}
```

**Error Responses:**

*Missing Required Field (400):*
```json
{
  "code": "missing_coupon_code",
  "message": "Coupon code is required.",
  "data": { "status": 400 }
}
```

*Duplicate Coupon (409):*
```json
{
  "code": "coupon_already_exists",
  "message": "Coupon with code \"SUMMER2024\" already exists.",
  "data": {
    "status": 409,
    "existing_coupon_id": 456
  }
}
```

*Not LazyChat Coupon (403):*
```json
{
  "code": "coupon_not_editable",
  "message": "This coupon was not created by LazyChat and cannot be updated via the API.",
  "data": { "status": 403 }
}
```

*Coupon Not Found (404):*
```json
{
  "code": "coupon_not_found",
  "message": "Coupon not found.",
  "data": { "status": 404 }
}
```

---

### 2. List Coupons
`POST /wp-json/lazychat/v1/coupons/list`

Retrieves a paginated list of coupons.

#### Request Body Parameters

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `page` | integer | 1 | Page number (must be > 0) |
| `per_page` | integer | 10 | Items per page (1-1000) |
| `status` | string | "all" | Filter by status: `all`, `active`, `expired`, or `used_up` |
| `is_lazychat_coupon` | boolean | false | If true, show only LazyChat-created coupons |

#### Example Requests

**List All Coupons (Default):**
```json
{
  "page": 1,
  "per_page": 10
}
```

**List with Pagination:**
```json
{
  "page": 2,
  "per_page": 20
}
```

**List Only LazyChat Coupons:**
```json
{
  "page": 1,
  "per_page": 10,
  "is_lazychat_coupon": true
}
```

**List Only Active Coupons:**
```json
{
  "page": 1,
  "per_page": 10,
  "status": "active"
}
```

**List LazyChat Active Coupons:**
```json
{
  "page": 1,
  "per_page": 10,
  "is_lazychat_coupon": true,
  "status": "active"
}
```

**List Expired Coupons:**
```json
{
  "page": 1,
  "per_page": 10,
  "status": "expired"
}
```

**List Used Up Coupons:**
```json
{
  "page": 1,
  "per_page": 10,
  "status": "used_up"
}
```

#### Response

**Success (200):**
```json
{
  "page": 1,
  "per_page": 10,
  "total_coupons": 25,
  "total_pages": 3,
  "coupons": [
    {
      "id": 123,
      "code": "SUMMER2024",
      "amount": "20",
      "discount_type": "percent",
      "description": "Summer sale 20% off",
      "status": "active",
      "created_via": "lazychat",
      "date_created": "2024-12-29T19:00:00",
      "date_modified": "2024-12-29T19:00:00",
      "date_expires": "2024-12-31T00:00:00",
      "minimum_amount": "0",
      "maximum_amount": "0",
      "usage_limit": 100,
      "usage_count": 15,
      "free_shipping": false
    },
    {
      "id": 124,
      "code": "WELCOME10",
      "amount": "10",
      "discount_type": "fixed_cart",
      "status": "active",
      "created_via": "lazychat",
      "usage_limit": 50,
      "usage_count": 32
    }
  ],
  "plugin_version": "1.4.8"
}
```

---

### 3. Delete Coupon
`POST /wp-json/lazychat/v1/coupons/delete`

Permanently deletes a coupon. Only coupons created by LazyChat can be deleted.

#### Request Body Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `coupon_id` | integer | Yes | ID of the coupon to delete |

#### Example Request

```json
{
  "coupon_id": 123
}
```

#### Response

**Success (200):**
```json
{
  "success": true,
  "message": "Coupon \"SUMMER2024\" deleted successfully.",
  "coupon_id": 123,
  "coupon_code": "SUMMER2024",
  "plugin_version": "1.4.8"
}
```

**Error Responses:**

*Invalid Coupon ID (400):*
```json
{
  "code": "invalid_coupon_id",
  "message": "Invalid coupon ID.",
  "data": { "status": 400 }
}
```

*Coupon Not Found (404):*
```json
{
  "code": "coupon_not_found",
  "message": "Coupon not found.",
  "data": { "status": 404 }
}
```

*Not LazyChat Coupon (403):*
```json
{
  "code": "coupon_not_deletable",
  "message": "This coupon was not created by LazyChat and cannot be deleted via the API.",
  "data": { "status": 403 }
}
```

---

## Authentication

### Bearer Token Authentication

Include the Bearer token in the Authorization header:

```bash
curl -X POST https://yoursite.com/wp-json/lazychat/v1/coupons/create-or-update \
  -H "Authorization: Bearer YOUR_TOKEN_HERE" \
  -H "Content-Type: application/json" \
  -d '{"code": "TEST", "discount_type": "percent", "amount": "10"}'
```

### WooCommerce Consumer Key/Secret

Include credentials in the request body:

```bash
curl -X POST https://yoursite.com/wp-json/lazychat/v1/coupons/list \
  -H "Content-Type: application/json" \
  -d '{
    "consumer_key": "ck_xxxxxxxxxxxxx",
    "consumer_secret": "cs_xxxxxxxxxxxxx",
    "page": 1,
    "per_page": 10
  }'
```

---

## Security & Permissions

### LazyChat Tracking

All coupons created through this API are marked with `_created_via: lazychat` metadata.

**Important Restrictions:**
- ✅ **Create**: Any authenticated user can create coupons
- ✅ **List**: Can view all coupons or filter by LazyChat-created only
- ⚠️ **Update**: Only LazyChat-created coupons can be updated (returns 403 error otherwise)
- ⚠️ **Delete**: Only LazyChat-created coupons can be deleted (returns 403 error otherwise)

This prevents accidental modification of coupons created through WooCommerce admin or other sources.

---

## Coupon Status

Coupons automatically have their status determined:

- **active** - Coupon is valid and can be used
- **expired** - Coupon expiration date has passed
- **used_up** - Coupon has reached its usage limit

---

## Discount Types

### percent
Percentage discount on cart or products.
```json
{
  "discount_type": "percent",
  "amount": "20"  // 20% off
}
```

### fixed_cart
Fixed amount discount on entire cart.
```json
{
  "discount_type": "fixed_cart",
  "amount": "10"  // $10 off cart
}
```

### fixed_product
Fixed amount discount on specific products.
```json
{
  "discount_type": "fixed_product",
  "amount": "5",  // $5 off per product
  "product_ids": [123, 456]
}
```

---

## Common Use Cases

### One-Time Welcome Coupon
```json
{
  "code": "WELCOME15",
  "discount_type": "percent",
  "amount": "15",
  "description": "15% off first order",
  "usage_limit_per_user": 1,
  "email_restrictions": ["newcustomer@example.com"]
}
```

### Limited Time Flash Sale
```json
{
  "code": "FLASH50",
  "discount_type": "percent",
  "amount": "50",
  "description": "Flash sale - 50% off",
  "date_expires": "2024-12-31",
  "usage_limit": 100
}
```

### Category-Specific Discount
```json
{
  "code": "ELECTRONICS20",
  "discount_type": "percent",
  "amount": "20",
  "description": "20% off electronics",
  "product_categories": [5, 8, 12]
}
```

### Free Shipping Threshold
```json
{
  "code": "SHIPFREE",
  "discount_type": "fixed_cart",
  "amount": "0",
  "description": "Free shipping over $100",
  "minimum_amount": "100",
  "free_shipping": true
}
```

### VIP Customer Discount
```json
{
  "code": "VIP30",
  "discount_type": "percent",
  "amount": "30",
  "description": "VIP member discount",
  "email_restrictions": ["vip@example.com"],
  "exclude_sale_items": true
}
```

---

## Error Codes Reference

| Code | Status | Description |
|------|--------|-------------|
| `missing_coupon_code` | 400 | Coupon code not provided |
| `missing_discount_type` | 400 | Discount type not provided |
| `missing_amount` | 400 | Discount amount not provided |
| `invalid_discount_type` | 400 | Invalid discount type value |
| `invalid_coupon_id` | 400 | Invalid coupon ID format |
| `rest_invalid_request` | 400 | Invalid request body |
| `coupon_not_editable` | 403 | Coupon not created by LazyChat |
| `coupon_not_deletable` | 403 | Coupon not created by LazyChat |
| `coupon_not_found` | 404 | Coupon does not exist |
| `coupon_already_exists` | 409 | Duplicate coupon code |
| `coupon_save_failed` | 500 | Failed to save coupon |
| `coupon_delete_failed` | 500 | Failed to delete coupon |
| `coupon_operation_failed` | 500 | General operation failure |
