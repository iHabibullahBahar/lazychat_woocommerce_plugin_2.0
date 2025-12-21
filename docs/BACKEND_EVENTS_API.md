# Backend Events API - Implementation Guide

## Endpoint Required

```
POST https://app.lazychat.io/api/woocommerce-plugin/events
```

## Events Sent by Plugin

### 1. plugin.updated
**When:** Plugin version changes  
**Data:**
```json
{
  "previous_version": "1.3.38",
  "new_version": "1.3.39",
  "update_time": "2025-12-22 10:30:00"
}
```

### 2. plugin.installed
**When:** First time plugin activated  
**Data:**
```json
{
  "version": "1.3.39",
  "install_time": "2025-12-22 10:30:00"
}
```

### 3. settings.checking_connection
**When:** User visits LazyChat settings page (once per visit)  
**Data:**
```json
{
  "user_id": 1,
  "user_email": "admin@example.com",
  "check_time": "2025-12-22 10:30:00"
}
```

### 4. diagnostic.rest_api_test
**When:** User clicks "Test REST API" button  
**Data:**
```json
{
  "user_id": 1,
  "user_email": "admin@example.com",
  "test_time": "2025-12-22 10:30:00"
}
```

## Request Format

**Headers:**
```
Authorization: Bearer {token}
Content-Type: application/json
X-Event-Type: {event_type}
X-Plugin-Version: 1.3.39
X-Lazychat-Shop-Id: {shop_id}
X-Event-Timestamp: {unix_timestamp}
```

**Body:**
```json
{
  "event_type": "plugin.updated",
  "event_data": {
    // Event-specific data (see above)
  },
  "site_info": {
    "site_url": "https://example.com",
    "site_name": "My Store",
    "wordpress_version": "6.4",
    "woocommerce_version": "8.5",
    "plugin_version": "1.3.39",
    "php_version": "8.1",
    "timestamp": "2025-12-22 10:30:00"
  }
}
```

## Backend Implementation

### Node.js Example
```javascript
app.post('/api/woocommerce-plugin/events', async (req, res) => {
  const { event_type, event_data, site_info } = req.body;
  const shopId = req.headers['x-lazychat-shop-id'];
  
  // Respond immediately
  res.json({ success: true });
  
  // Handle events
  switch(event_type) {
    case 'plugin.updated':
      await updatePluginVersion(shopId, event_data.new_version);
      break;
      
    case 'settings.checking_connection':
      await updateLastConnection(shopId);
      break;
  }
});
```

### Python Example
```python
@app.route('/api/woocommerce-plugin/events', methods=['POST'])
def handle_event():
    data = request.json
    event_type = data['event_type']
    shop_id = request.headers.get('X-Lazychat-Shop-Id')
    
    # Respond immediately
    response = {'success': True}
    
    # Handle events
    if event_type == 'plugin.updated':
        update_plugin_version(shop_id, data['event_data']['new_version'])
    elif event_type == 'settings.checking_connection':
        update_last_connection(shop_id)
    
    return jsonify(response), 200
```

## Response Expected

```json
{
  "success": true
}
```

## Custom Events

Send custom events from WordPress:
```php
lazychat_send_event_notification('custom.event_name', array(
    'custom_data' => 'value'
));
```

## Notes

- All requests are **non-blocking** (fire and forget)
- Use event_type to route different actions
- site_info is included in every event
- Authentication via Bearer token in headers
