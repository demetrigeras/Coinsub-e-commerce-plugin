#!/usr/bin/env python3
"""
Test CoinSub Webhook
Simulates the webhook data you received to test the endpoint
"""

import requests
import json

# Simulate the webhook data you received
webhook_data = {
    "type": "payment",
    "merchant_id": "mrch_ca875a80-9b10-40ce-85c0-5af81856733a",
    "origin_id": "sess_0b3863d2-e5d5-4738-9135-a9a43c27e5b1",
    "origin": "purchase_sessions",
    "name": "WooCommerce Order: Premium T-Shirt + Wireless Headphones",
    "currency": "USDC",
    "amount": 0.03,
    "metadata": {
        "currency": "USD",
        "individual_products": ["Premium T-Shirt", "Wireless Headphones"],
        "product_count": 2,
        "products": [
            {
                "id": "bdca0f1f-6c14-4ffe-b8c7-b64f2ca7d94d",
                "name": "Premium T-Shirt",
                "price": 0.01
            },
            {
                "id": "bae95db1-46e1-4fd0-8f8b-a7c929e143ec",
                "name": "Wireless Headphones",
                "price": 0.02
            }
        ],
        "source": "woocommerce_plugin",
        "total_amount": 0.03,
        "total_items": 2,
        "woocommerce_order_id": "c53e29df-6a01-4fd4-a697-98c398c143f9"
    },
    "payment_date": "2025-10-03T15:33:59.91439Z",
    "last_updated": "2025-10-03T15:36:37.232596Z",
    "status": "completed",
    "transaction_details": {
        "transaction_id": 14415,
        "transaction_hash": "0x7a33e5708ea9dac1c9d26ca8cab586b7759fa7a1199f3918e6662524d45f336d",
        "chain_id": 80002
    },
    "user": {
        "first_name": "Testzsxdcfvgbhnj",
        "last_name": "Testdcfvgbhnjmkl",
        "email": "demetri+500@coinsub.io",
        "subscriber_id": "2d8aa67e-add5-4b7f-902f-a6d09f23270f"
    },
    "payment_id": "paym_50fb734f-5325-4482-b9a9-463339817c23",
    "agreement_id": "agre_36b3d327-78d6-4338-817b-2921275434de"
}

def test_webhook():
    """Test the webhook endpoint with the received data"""
    webhook_url = "https://webhook-test.com/bce9a8b61c28d115aa796fe270d40e9f"
    
    print("🧪 Testing CoinSub Webhook")
    print("=" * 30)
    print(f"📡 Webhook URL: {webhook_url}")
    print(f"📦 Event Type: {webhook_data['type']}")
    print(f"📦 Status: {webhook_data['status']}")
    print(f"📦 Amount: {webhook_data['amount']} {webhook_data['currency']}")
    print(f"📦 Origin ID: {webhook_data['origin_id']}")
    print(f"📦 WooCommerce Order ID: {webhook_data['metadata']['woocommerce_order_id']}")
    print()
    
    try:
        response = requests.post(
            webhook_url,
            json=webhook_data,
            headers={
                'Content-Type': 'application/json',
                'User-Agent': 'CoinSub-Webhook-Test/1.0'
            },
            timeout=30
        )
        
        print(f"📤 Webhook Response:")
        print(f"   Status Code: {response.status_code}")
        print(f"   Response: {response.text}")
        
        if response.status_code == 200:
            print("\n✅ Webhook test successful!")
        else:
            print(f"\n❌ Webhook test failed with status {response.status_code}")
            
    except Exception as e:
        print(f"\n❌ Webhook test error: {str(e)}")
    
    print(f"\n🔍 Key Information:")
    print(f"   Origin ID: {webhook_data['origin_id']}")
    print(f"   Event Type: {webhook_data['type']}")
    print(f"   Status: {webhook_data['status']}")
    print(f"   Amount: {webhook_data['amount']} {webhook_data['currency']}")
    print(f"   WooCommerce Order ID: {webhook_data['metadata']['woocommerce_order_id']}")
    print(f"   Transaction ID: {webhook_data['transaction_details']['transaction_id']}")
    print(f"   Transaction Hash: {webhook_data['transaction_details']['transaction_hash']}")
    
    print(f"\n📋 Product Details:")
    for i, product in enumerate(webhook_data['metadata']['products'], 1):
        print(f"   {i}. {product['name']} - ${product['price']} (ID: {product['id']})")

if __name__ == "__main__":
    test_webhook()
