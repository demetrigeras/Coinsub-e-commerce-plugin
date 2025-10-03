#!/usr/bin/env python3
"""
CoinSub API Test Script
Tests the CoinSub API endpoints for WooCommerce integration
"""

import requests
import json
import sys

# Configuration
API_BASE_URL = "https://dev-api.coinsub.io/v1"  # Development environment
MERCHANT_ID = "ca875a80-9b10-40ce-85c0-5af81856733a"  # Merchant ID
API_KEY = "abf3e9e5-0140-4fda-abc9-7dd87a358852"  # API key

# Headers for API requests
HEADERS = {
    "Content-Type": "application/json",
    "Merchant-ID": MERCHANT_ID,
    "API-Key": API_KEY,
    "Authorization": f"Bearer {API_KEY}"  # Added both headers for robustness
}

def test_api_connection():
    """Test basic API connection by trying to create a product"""
    print("🔗 Testing API Connection...")
    
    # Test with a simple product creation to verify API access
    test_data = {
        "name": "API Connection Test",
        "price": 1.00,
        "currency": "USD"
    }
    
    try:
        response = requests.post(f"{API_BASE_URL}/commerce/products", headers=HEADERS, json=test_data, timeout=10)
        print(f"   Status: {response.status_code}")
        if response.status_code in [200, 201]:
            print("   ✅ API Connection successful")
            return True
        else:
            print(f"   ❌ API Connection failed: {response.text}")
            return False
    except Exception as e:
        print(f"   ❌ API Connection error: {str(e)}")
        return False

def create_test_product():
    """Create a test product in CoinSub commerce_products table"""
    print("\n📦 Creating Test Product...")
    
    product_data = {
        "name": "Test WooCommerce Product",
        "description": "A test product created from WooCommerce integration",
        "price": 25.99,  # Numeric price
        "currency": "USD",
        "image_url": "https://via.placeholder.com/300x300",
        "metadata": {
            "woocommerce_product_id": "12345",
            "sku": "TEST-SKU-001",
            "type": "simple"
        }
    }
    
    print(f"   Sending data: {json.dumps(product_data, indent=2)}")
    
    try:
        response = requests.post(
            f"{API_BASE_URL}/commerce/products",
            headers=HEADERS,
            json=product_data,
            timeout=10
        )
        
        print(f"   Status: {response.status_code}")
        print(f"   Response: {response.text}")
        
        if response.status_code in [200, 201]:
            product_data = response.json()
            product_id = product_data.get('id')  # Direct access to id, not nested in data
            print(f"   ✅ Product created with ID: {product_id}")
            return product_id
        else:
            print(f"   ❌ Product creation failed: {response.text}")
            return None
            
    except Exception as e:
        print(f"   ❌ Product creation error: {str(e)}")
        return None

def create_test_order(product_id):
    """Create a test order in CoinSub commerce_orders table"""
    print(f"\n🛒 Creating Test Order for Product {product_id}...")
    
    order_data = {
        "total": 25.99,  # Changed from total_amount to total (numeric)
        "currency": "USD",
        "items": [
            {
                "product_id": product_id,
                "quantity": 1,
                "price": 25.99  # Numeric price
            }
        ],
        "metadata": {
            "woocommerce_order_id": "67890",
            "customer_email": "test@example.com"
        }
    }
    
    print(f"   Sending data: {json.dumps(order_data, indent=2)}")
    
    try:
        response = requests.post(
            f"{API_BASE_URL}/commerce/orders",
            headers=HEADERS,
            json=order_data,
            timeout=10
        )
        
        print(f"   Status: {response.status_code}")
        print(f"   Response: {response.text}")
        
        if response.status_code in [200, 201]:
            order_data = response.json()
            order_id = order_data.get('id')  # Direct access to id, not nested in data
            print(f"   ✅ Order created with ID: {order_id}")
            return order_id
        else:
            print(f"   ❌ Order creation failed: {response.text}")
            return None
            
    except Exception as e:
        print(f"   ❌ Order creation error: {str(e)}")
        return None

def create_purchase_session(order_id):
    """Create a purchase session and link it to the order"""
    print(f"\n💳 Creating Purchase Session for Order {order_id}...")
    
    session_data = {
        "name": "WooCommerce Order Payment",
        "details": "Payment for WooCommerce order",
        "currency": "USD",
        "amount": 25.99,
        "recurring": False,
        "success_url": "https://webhook-test.com/bce9a8b61c28d115aa796fe270d40e9f/success",
        "cancel_url": "https://webhook-test.com/bce9a8b61c28d115aa796fe270d40e9f/cancel",
        "metadata": {
            "woocommerce_order_id": order_id,
            "source": "woocommerce_plugin"
        }
    }
    
    print(f"   Sending data: {json.dumps(session_data, indent=2)}")
    
    try:
        # First, create the purchase session
        response = requests.post(
            f"{API_BASE_URL}/purchase/session/start",
            headers=HEADERS,
            json=session_data,
            timeout=10
        )
        
        print(f"   Status: {response.status_code}")
        print(f"   Response: {response.text}")
        
        if response.status_code == 200:  # Expect 200 for purchase session creation
            session_data = response.json()
            purchase_session_id = session_data.get('data', {}).get('purchase_session_id')
            checkout_url = session_data.get('data', {}).get('url')
            
            print(f"   ✅ Purchase session created with ID: {purchase_session_id}")
            print(f"   🔗 Checkout URL: {checkout_url}")
            
            # Try to extract UUID part if it has sess_ prefix
            if purchase_session_id and purchase_session_id.startswith('sess_'):
                uuid_part = purchase_session_id.replace('sess_', '')
                purchase_session_id = uuid_part
                print(f"   🔄 Extracted UUID: {purchase_session_id}")
            
            # Now link the order to the purchase session
            print(f"\n🔗 Linking Order {order_id} to Purchase Session {purchase_session_id}...")
            
            checkout_data = {
                "purchase_session_id": purchase_session_id
            }
            
            checkout_response = requests.put(
                f"{API_BASE_URL}/commerce/orders/{order_id}/checkout",
                headers=HEADERS,
                json=checkout_data,
                timeout=10
            )
            
            print(f"   Checkout Status: {checkout_response.status_code}")
            print(f"   Checkout Response: {checkout_response.text}")
            
            if checkout_response.status_code == 200:
                print(f"   ✅ Order successfully linked to purchase session")
                return {
                    'purchase_session_id': purchase_session_id,
                    'checkout_url': checkout_url,
                    'original_id': session_data.get('data', {}).get('purchase_session_id')
                }
            else:
                print(f"   ❌ Order checkout failed: {checkout_response.text}")
                return None
        else:
            print(f"   ❌ Purchase session creation failed: {response.text}")
            return None
            
    except Exception as e:
        print(f"   ❌ Purchase session creation error: {str(e)}")
        return None

def test_webhook_endpoint():
    """Test if webhook endpoint is accessible"""
    print(f"\n🔔 Testing webhook endpoint...")
    
    webhook_url = "https://webhook-test.com/bce9a8b61c28d115aa796fe270d40e9f"
    
    try:
        response = requests.get(webhook_url, timeout=10)
        print(f"   Status: {response.status_code}")
        if response.status_code == 200:
            print(f"   ✅ Webhook endpoint accessible")
            return True
        else:
            print(f"   ⚠️ Webhook endpoint returned status {response.status_code}")
            return False
    except Exception as e:
        print(f"   ❌ Webhook endpoint not accessible: {str(e)}")
        return False

def main():
    """Main test function"""
    print("🚀 Starting CoinSub API Test")
    print("=" * 50)
    
    # Validate credentials
    if MERCHANT_ID == "your_merchant_id_here" or API_KEY == "your_api_key_here":
        print("❌ Please update MERCHANT_ID and API_KEY in the script")
        sys.exit(1)
    
    # Test API connection
    if not test_api_connection():
        print("\n❌ API connection failed. Please check your credentials and network.")
        sys.exit(1)
    
    # Create product
    product_id = create_test_product()
    if not product_id:
        print("\n❌ Product creation failed. Stopping test.")
        sys.exit(1)
    
    # Create order
    order_id = create_test_order(product_id)
    if not order_id:
        print("\n❌ Order creation failed. Stopping test.")
        sys.exit(1)
    
    # Create purchase session
    session_result = create_purchase_session(order_id)
    if not session_result:
        print("\n❌ Purchase session creation failed. Stopping test.")
        sys.exit(1)
    
    # Test webhook
    test_webhook_endpoint()
    
    print("\n" + "=" * 50)
    print("🎉 All tests completed!")
    print(f"📦 Product ID: {product_id}")
    print(f"🛒 Order ID: {order_id}")
    print(f"💳 Purchase Session ID: {session_result['purchase_session_id']}")
    print(f"🔗 Checkout URL: {session_result['checkout_url']}")
    print(f"🔔 Webhook URL: https://webhook-test.com/bce9a8b61c28d115aa796fe270d40e9f")

if __name__ == "__main__":
    main()
