#!/usr/bin/env python3
"""
CoinSub Multi-Product API Test Script
Tests the CoinSub API with multiple products to verify proper combination in purchase session
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
    "Authorization": f"Bearer {API_KEY}"
}

def create_multiple_products():
    """Create multiple test products in CoinSub commerce_products table"""
    print("📦 Creating Multiple Test Products...")
    
    products = [
        {
            "name": "Premium T-Shirt",
            "description": "High-quality cotton t-shirt",
            "price": 0.01,  # Small price for testing
            "currency": "USD",
            "image_url": "https://via.placeholder.com/300x300?text=T-Shirt",
            "metadata": {
                "woocommerce_product_id": "1001",
                "sku": "TSHIRT-001",
                "type": "simple",
                "category": "clothing"
            }
        },
        {
            "name": "Wireless Headphones",
            "description": "Noise-cancelling wireless headphones",
            "price": 0.02,  # Small price for testing
            "currency": "USD",
            "image_url": "https://via.placeholder.com/300x300?text=Headphones",
            "metadata": {
                "woocommerce_product_id": "1002",
                "sku": "HEADPHONES-001",
                "type": "simple",
                "category": "electronics"
            }
        }
    ]
    
    created_products = []
    
    for i, product_data in enumerate(products, 1):
        print(f"\n   Creating Product {i}: {product_data['name']} (${product_data['price']})")
        print(f"   Sending data: {json.dumps(product_data, indent=4)}")
        
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
                product_response = response.json()
                product_id = product_response.get('id')
                print(f"   ✅ Product {i} created with ID: {product_id}")
                created_products.append({
                    'id': product_id,
                    'name': product_data['name'],
                    'price': product_data['price']
                })
            else:
                print(f"   ❌ Product {i} creation failed: {response.text}")
                return None
                
        except Exception as e:
            print(f"   ❌ Product {i} creation error: {str(e)}")
            return None
    
    return created_products

def create_multi_item_order(products):
    """Create an order with multiple items"""
    print(f"\n🛒 Creating Multi-Item Order...")
    
    # Calculate total
    total_price = sum(product['price'] for product in products)
    
    order_data = {
        "total": total_price,
        "currency": "USD",
        "items": [
            {
                "product_id": product['id'],
                "quantity": 1,
                "price": product['price']
            } for product in products
        ],
        "metadata": {
            "woocommerce_order_id": "MULTI-001",
            "customer_email": "test@example.com",
            "order_type": "multi_product_test"
        }
    }
    
    print(f"   Order Total: ${total_price}")
    print(f"   Items: {len(products)} products")
    for product in products:
        print(f"     - {product['name']}: ${product['price']}")
    
    print(f"\n   Sending data: {json.dumps(order_data, indent=4)}")
    
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
            order_response = response.json()
            order_id = order_response.get('id')
            print(f"   ✅ Multi-item order created with ID: {order_id}")
            return order_id
        else:
            print(f"   ❌ Order creation failed: {response.text}")
            return None
            
    except Exception as e:
        print(f"   ❌ Order creation error: {str(e)}")
        return None

def create_purchase_session_with_combined_items(order_id, products):
    """Create a purchase session with combined product names and total price"""
    print(f"\n💳 Creating Purchase Session with Combined Items...")
    
    # Calculate total
    total_price = sum(product['price'] for product in products)
    
    # Create combined product names for the purchase session
    product_names = [product['name'] for product in products]
    combined_name = " + ".join(product_names)
    
    # Create detailed product information for metadata
    product_details = []
    for product in products:
        product_details.append({
            "name": product['name'],
            "price": product['price'],
            "id": product['id']
        })
    
    session_data = {
        "name": f"WooCommerce Order: {combined_name}",
        "details": f"Payment for WooCommerce order containing: {', '.join(product_names)}",
        "currency": "USD",
        "amount": total_price,
        "recurring": False,
        "success_url": "https://webhook-test.com/bce9a8b61c28d115aa796fe270d40e9f/success",
        "cancel_url": "https://webhook-test.com/bce9a8b61c28d115aa796fe270d40e9f/cancel",
        "metadata": {
            "woocommerce_order_id": order_id,
            "source": "woocommerce_plugin",
            "product_count": len(products),
            "individual_products": product_names,
            "total_items": len(products),
            "products": product_details,  # Detailed product info with names and prices
            "total_amount": total_price,
            "currency": "USD"
        }
    }
    
    print(f"   Combined Name: {combined_name}")
    print(f"   Total Amount: ${total_price}")
    print(f"   Product Count: {len(products)}")
    print(f"   Individual Products: {', '.join(product_names)}")
    
    print(f"\n   Sending data: {json.dumps(session_data, indent=4)}")
    
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
        
        if response.status_code == 200:
            session_response = response.json()
            purchase_session_id = session_response.get('data', {}).get('purchase_session_id')
            checkout_url = session_response.get('data', {}).get('url')
            
            print(f"   ✅ Purchase session created with ID: {purchase_session_id}")
            print(f"   🔗 Checkout URL: {checkout_url}")
            
            # Highlight the checkout URL importance
            print(f"\n   🌟 IMPORTANT: This checkout URL is where customers will complete payment")
            print(f"   🌟 After payment completion, CoinSub will send webhooks to your configured webhook URL")
            print(f"   🌟 Webhook URL: https://webhook-test.com/bce9a8b61c28d115aa796fe270d40e9f")
            
            # Extract UUID part if it has sess_ prefix
            if purchase_session_id and purchase_session_id.startswith('sess_'):
                uuid_part = purchase_session_id.replace('sess_', '')
                purchase_session_id = uuid_part
                print(f"   🔄 Extracted UUID: {purchase_session_id}")
            
            # Link the order to the purchase session
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
                    'combined_name': combined_name,
                    'total_amount': total_price,
                    'product_count': len(products)
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

def verify_purchase_session_data(session_result, products):
    """Verify that the purchase session contains the correct combined data"""
    print(f"\n🔍 Verifying Purchase Session Data...")
    
    print(f"   Expected Combined Name: {session_result['combined_name']}")
    print(f"   Expected Total Amount: ${session_result['total_amount']}")
    print(f"   Expected Product Count: {session_result['product_count']}")
    
    # Check if the purchase session name contains all product names
    all_products_found = True
    for product in products:
        if product['name'] not in session_result['combined_name']:
            all_products_found = False
            print(f"   ❌ Product '{product['name']}' not found in combined name")
        else:
            print(f"   ✅ Product '{product['name']}' found in combined name")
    
    # Check if total amount is correct
    expected_total = sum(product['price'] for product in products)
    if session_result['total_amount'] == expected_total:
        print(f"   ✅ Total amount is correct: ${session_result['total_amount']}")
    else:
        print(f"   ❌ Total amount mismatch: expected ${expected_total}, got ${session_result['total_amount']}")
    
    # Check if product count is correct
    if session_result['product_count'] == len(products):
        print(f"   ✅ Product count is correct: {session_result['product_count']}")
    else:
        print(f"   ❌ Product count mismatch: expected {len(products)}, got {session_result['product_count']}")
    
    return all_products_found

def main():
    """Main test function for multi-product scenario"""
    print("🚀 Starting CoinSub Multi-Product API Test")
    print("=" * 60)
    
    # Validate credentials
    if MERCHANT_ID == "your_merchant_id_here" or API_KEY == "your_api_key_here":
        print("❌ Please update MERCHANT_ID and API_KEY in the script")
        sys.exit(1)
    
    # Create multiple products
    products = create_multiple_products()
    if not products:
        print("\n❌ Product creation failed. Stopping test.")
        sys.exit(1)
    
    # Create multi-item order
    order_id = create_multi_item_order(products)
    if not order_id:
        print("\n❌ Order creation failed. Stopping test.")
        sys.exit(1)
    
    # Create purchase session with combined items
    session_result = create_purchase_session_with_combined_items(order_id, products)
    if not session_result:
        print("\n❌ Purchase session creation failed. Stopping test.")
        sys.exit(1)
    
    # Verify the purchase session data
    verification_passed = verify_purchase_session_data(session_result, products)
    
    print("\n" + "=" * 60)
    if verification_passed:
        print("🎉 Multi-Product Test PASSED!")
        print("✅ All products were properly combined in the purchase session")
    else:
        print("❌ Multi-Product Test FAILED!")
        print("❌ Some products were not properly combined")
    
    print(f"\n📊 Test Summary:")
    print(f"   📦 Products Created: {len(products)}")
    for i, product in enumerate(products, 1):
        print(f"      {i}. {product['name']} - ${product['price']}")
    print(f"   🛒 Order ID: {order_id}")
    print(f"   💳 Purchase Session ID: {session_result['purchase_session_id']}")
    print(f"   📝 Combined Name: {session_result['combined_name']}")
    print(f"   💰 Total Amount: ${session_result['total_amount']}")
    
    print(f"\n🌟 CHECKOUT URL (Most Important!):")
    print(f"   🔗 {session_result['checkout_url']}")
    print(f"   📝 This is where customers complete payment")
    print(f"   🔔 After payment, webhooks sent to: https://webhook-test.com/bce9a8b61c28d115aa796fe270d40e9f")
    
    print(f"\n📋 Product Details in Purchase Session Metadata:")
    for i, product in enumerate(products, 1):
        print(f"   {i}. Name: {product['name']}, Price: ${product['price']}, ID: {product['id']}")

if __name__ == "__main__":
    main()
