# Order Creation Functionality Tests

## Overview
This document provides test cases to validate the order creation functionality in LazyChat plugin.

## API Endpoint
```
POST /wp-json/lazychat/v1/orders/create
```

## Test Cases

### Test 1: Basic Order with Single Product
**Description**: Create a simple order with one product

```json
{
  "consumer_key": "YOUR_CONSUMER_KEY",
  "consumer_secret": "YOUR_CONSUMER_SECRET",
  "status": "pending",
  "billing": {
    "first_name": "John",
    "last_name": "Doe",
    "email": "john.doe@example.com",
    "phone": "+1234567890",
    "address_1": "123 Main St",
    "city": "New York",
    "state": "NY",
    "postcode": "10001",
    "country": "US"
  },
  "line_items": [
    {
      "product_id": 123,
      "quantity": 2
    }
  ],
  "payment_method": "cod",
  "payment_method_title": "Cash on Delivery"
}
```

**Expected Result**: Order created successfully with all details

---

### Test 2: Order with Variation Product
**Description**: Create order with a variable product (e.g., T-shirt with size and color)

```json
{
  "consumer_key": "YOUR_CONSUMER_KEY",
  "consumer_secret": "YOUR_CONSUMER_SECRET",
  "status": "processing",
  "billing": {
    "first_name": "Jane",
    "last_name": "Smith",
    "email": "jane.smith@example.com",
    "phone": "+1234567890",
    "address_1": "456 Oak Ave",
    "city": "Los Angeles",
    "state": "CA",
    "postcode": "90001",
    "country": "US"
  },
  "line_items": [
    {
      "product_id": 100,
      "variation_id": 150,
      "quantity": 1
    }
  ]
}
```

**Expected Result**: Order with variation created, variation attributes properly set

---

### Test 3: Order with Custom Price Override
**Description**: Create order with manual/custom pricing

```json
{
  "consumer_key": "YOUR_CONSUMER_KEY",
  "consumer_secret": "YOUR_CONSUMER_SECRET",
  "status": "pending",
  "billing": {
    "first_name": "Bob",
    "last_name": "Johnson",
    "email": "bob.johnson@example.com",
    "phone": "+1234567890",
    "address_1": "789 Pine Rd",
    "city": "Chicago",
    "state": "IL",
    "postcode": "60601",
    "country": "US"
  },
  "line_items": [
    {
      "product_id": 200,
      "quantity": 1,
      "price": 49.99
    }
  ]
}
```

**Expected Result**: Order created with custom price ($49.99), not product's default price
**Response Check**: Look for `custom_price` object in line items

---

### Test 4: Multiple Products Order
**Description**: Create order with multiple different products

```json
{
  "consumer_key": "YOUR_CONSUMER_KEY",
  "consumer_secret": "YOUR_CONSUMER_SECRET",
  "status": "pending",
  "billing": {
    "first_name": "Alice",
    "last_name": "Williams",
    "email": "alice.williams@example.com",
    "phone": "+1234567890",
    "address_1": "321 Elm St",
    "city": "Houston",
    "state": "TX",
    "postcode": "77001",
    "country": "US"
  },
  "line_items": [
    {
      "product_id": 100,
      "quantity": 2
    },
    {
      "product_id": 150,
      "quantity": 1
    },
    {
      "product_id": 200,
      "quantity": 3
    }
  ]
}
```

**Expected Result**: Order with 3 different products, correct quantities and totals

---

### Test 5: Order with Shipping and Fees
**Description**: Order including shipping charges and additional fees

```json
{
  "consumer_key": "YOUR_CONSUMER_KEY",
  "consumer_secret": "YOUR_CONSUMER_SECRET",
  "status": "pending",
  "billing": {
    "first_name": "David",
    "last_name": "Brown",
    "email": "david.brown@example.com",
    "phone": "+1234567890",
    "address_1": "555 Maple Dr",
    "city": "Phoenix",
    "state": "AZ",
    "postcode": "85001",
    "country": "US"
  },
  "shipping": {
    "first_name": "David",
    "last_name": "Brown",
    "address_1": "555 Maple Dr",
    "city": "Phoenix",
    "state": "AZ",
    "postcode": "85001",
    "country": "US"
  },
  "line_items": [
    {
      "product_id": 300,
      "quantity": 1
    }
  ],
  "shipping_lines": [
    {
      "method_id": "flat_rate",
      "method_title": "Flat Rate Shipping",
      "total": "10.00"
    }
  ],
  "fee_lines": [
    {
      "name": "Handling Fee",
      "total": "5.00",
      "tax_status": "taxable"
    }
  ]
}
```

**Expected Result**: Order with proper shipping and fee calculations

---

### Test 6: Order with Coupon
**Description**: Create order with discount coupon applied

```json
{
  "consumer_key": "YOUR_CONSUMER_KEY",
  "consumer_secret": "YOUR_CONSUMER_SECRET",
  "status": "pending",
  "billing": {
    "first_name": "Emma",
    "last_name": "Davis",
    "email": "emma.davis@example.com",
    "phone": "+1234567890",
    "address_1": "777 Cedar Ln",
    "city": "Philadelphia",
    "state": "PA",
    "postcode": "19101",
    "country": "US"
  },
  "line_items": [
    {
      "product_id": 400,
      "quantity": 2
    }
  ],
  "coupon_lines": [
    {
      "code": "SUMMER2025"
    }
  ]
}
```

**Expected Result**: Order with coupon discount applied, discount shown in order total
**Note**: Coupon "SUMMER2025" must exist in WooCommerce

---

### Test 7: Order with Existing Customer
**Description**: Create order for existing customer by email

```json
{
  "consumer_key": "YOUR_CONSUMER_KEY",
  "consumer_secret": "YOUR_CONSUMER_SECRET",
  "status": "pending",
  "billing": {
    "first_name": "Michael",
    "last_name": "Wilson",
    "email": "existing.customer@example.com",
    "phone": "+1234567890",
    "address_1": "999 Birch Ct",
    "city": "San Antonio",
    "state": "TX",
    "postcode": "78201",
    "country": "US"
  },
  "line_items": [
    {
      "product_id": 500,
      "quantity": 1
    }
  ]
}
```

**Expected Result**: Order linked to existing customer account, `customer_id` in response
**Note**: Customer with email "existing.customer@example.com" must exist

---

### Test 8: Order with Meta Data
**Description**: Order with custom metadata

```json
{
  "consumer_key": "YOUR_CONSUMER_KEY",
  "consumer_secret": "YOUR_CONSUMER_SECRET",
  "status": "pending",
  "billing": {
    "first_name": "Sarah",
    "last_name": "Martinez",
    "email": "sarah.martinez@example.com",
    "phone": "+1234567890",
    "address_1": "111 Spruce Way",
    "city": "San Diego",
    "state": "CA",
    "postcode": "92101",
    "country": "US"
  },
  "line_items": [
    {
      "product_id": 600,
      "quantity": 1,
      "meta_data": [
        {
          "key": "gift_message",
          "value": "Happy Birthday!"
        },
        {
          "key": "gift_wrap",
          "value": "yes"
        }
      ]
    }
  ],
  "meta_data": [
    {
      "key": "order_source",
      "value": "mobile_app"
    },
    {
      "key": "customer_note_internal",
      "value": "VIP Customer"
    }
  ]
}
```

**Expected Result**: Order with custom meta data stored correctly

---

### Test 9: Out of Stock Product (Error Case)
**Description**: Try to create order with out-of-stock product

```json
{
  "consumer_key": "YOUR_CONSUMER_KEY",
  "consumer_secret": "YOUR_CONSUMER_SECRET",
  "status": "pending",
  "billing": {
    "first_name": "Test",
    "last_name": "User",
    "email": "test@example.com",
    "phone": "+1234567890",
    "address_1": "123 Test St",
    "city": "Test City",
    "state": "NY",
    "postcode": "10001",
    "country": "US"
  },
  "line_items": [
    {
      "product_id": 999,
      "quantity": 1
    }
  ]
}
```

**Expected Result**: Order created with warning note about out-of-stock product
**Note**: Product ID 999 should be out of stock for this test

---

### Test 10: Invalid Product ID (Error Case)
**Description**: Try to create order with non-existent product

```json
{
  "consumer_key": "YOUR_CONSUMER_KEY",
  "consumer_secret": "YOUR_CONSUMER_SECRET",
  "status": "pending",
  "billing": {
    "first_name": "Test",
    "last_name": "User",
    "email": "test@example.com",
    "phone": "+1234567890",
    "address_1": "123 Test St",
    "city": "Test City",
    "state": "NY",
    "postcode": "10001",
    "country": "US"
  },
  "line_items": [
    {
      "product_id": 99999,
      "quantity": 1
    }
  ]
}
```

**Expected Result**: Order created with warning note about invalid product ID

---

## Validation Checklist

After each test, verify:

✅ **Order Created**
- Order ID returned in response
- Order appears in WooCommerce > Orders

✅ **Customer Information**
- Billing details saved correctly
- Shipping details saved (if provided)
- Customer linked to existing account (if email matches)

✅ **Line Items**
- All products added correctly
- Quantities match request
- Prices calculated correctly
- Custom prices applied (if specified)

✅ **Calculations**
- Subtotal correct
- Tax calculated (if applicable)
- Shipping charges added
- Fees included
- Coupon discounts applied
- Total matches sum of all components

✅ **Meta Data**
- Order meta data saved
- Line item meta data saved
- LazyChat tracking meta present (`created_via` = "lazychat")

✅ **Error Handling**
- Out-of-stock products logged in order notes
- Invalid products logged in order notes
- Order still created if at least one valid product exists

✅ **Response Format**
- All expected fields present
- `plugin_version` included
- `created_via` = "lazychat"
- Custom price info in line items (if used)
- Price fallback info in line items (if used)

---

## Common Issues & Solutions

### Issue 1: Order Created but Empty
**Symptom**: Order created with no products
**Cause**: All products failed validation (out of stock, invalid ID, etc.)
**Check**: Review order notes for error messages

### Issue 2: Wrong Price Used
**Symptom**: Product price differs from expected
**Solution**: 
- Use `price` field in line items to override product price
- Check response for `custom_price` or `price_fallback` objects

### Issue 3: Customer Not Linked
**Symptom**: Order created as guest, but customer exists
**Cause**: Email doesn't match exactly (case-sensitive)
**Solution**: Ensure email in request matches exactly

### Issue 4: Tax Not Calculated
**Symptom**: `total_tax` is 0
**Cause**: 
- Tax not configured in WooCommerce
- Customer address not in taxable region
**Solution**: Check WooCommerce tax settings

### Issue 5: Coupon Not Applied
**Symptom**: Discount not reflected in order
**Cause**: 
- Coupon doesn't exist
- Coupon restrictions not met
- Coupon expired
**Solution**: Verify coupon exists and is valid in WooCommerce

---

## Performance Notes

- **Single Product Order**: ~0.5-1s
- **Multiple Products Order**: ~1-2s
- **Order with New Customer Creation**: ~1.5-2.5s
- **Order with Variations**: ~1-2s

Times may vary based on server resources and number of installed plugins.

---

## API Response Structure

Successful order creation returns:

```json
{
  "id": 1234,
  "order_number": "1234",
  "status": "pending",
  "total": "99.99",
  "subtotal": "89.99",
  "total_tax": "0.00",
  "shipping_total": "10.00",
  "discount_total": "0.00",
  "currency": "USD",
  "date_created": "2025-12-24T10:30:00",
  "date_modified": "2025-12-24T10:30:00",
  "customer_id": 5,
  "created_via": "lazychat",
  "billing": { ... },
  "shipping": { ... },
  "payment_method": "cod",
  "payment_method_title": "Cash on Delivery",
  "transaction_id": "",
  "customer_note": "",
  "line_items": [
    {
      "id": 1,
      "name": "Product Name",
      "product_id": 123,
      "variation_id": 0,
      "quantity": 2,
      "subtotal": "79.98",
      "total": "79.98",
      "tax": "0.00",
      "sku": "PROD-123",
      "price": "39.99",
      "custom_price": {
        "used": false
      },
      "price_fallback": {
        "used": false
      }
    }
  ],
  "shipping_lines": [],
  "fee_lines": [],
  "coupon_lines": [],
  "plugin_version": "1.0.0"
}
```

---

## Additional Resources

- [WooCommerce REST API Documentation](https://woocommerce.github.io/woocommerce-rest-api-docs/)
- [LazyChat Plugin README](../README.md)
