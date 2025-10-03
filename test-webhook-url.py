#!/usr/bin/env python3
"""
Test webhook URL accessibility
"""

import requests
import json

def test_webhook_url(domain):
    """Test if the webhook URL is accessible"""
    webhook_url = f"https://{domain}/wp-json/coinsub/v1/webhook"
    
    print(f"🧪 Testing Webhook URL: {webhook_url}")
    print("=" * 50)
    
    # Test with a simple GET request first
    try:
        response = requests.get(webhook_url, timeout=10)
        print(f"📡 GET Response:")
        print(f"   Status Code: {response.status_code}")
        print(f"   Response: {response.text[:200]}...")
    except Exception as e:
        print(f"❌ GET Request Error: {str(e)}")
    
    print()
    
    # Test with a simple POST request
    test_data = {
        "type": "test",
        "message": "Webhook URL test"
    }
    
    try:
        response = requests.post(
            webhook_url,
            json=test_data,
            headers={'Content-Type': 'application/json'},
            timeout=10
        )
        print(f"📤 POST Response:")
        print(f"   Status Code: {response.status_code}")
        print(f"   Response: {response.text}")
        
        if response.status_code == 200:
            print("\n✅ Webhook URL is accessible!")
        else:
            print(f"\n⚠️ Webhook URL returned status {response.status_code}")
            
    except Exception as e:
        print(f"❌ POST Request Error: {str(e)}")
        print("\n💡 This might be normal if the webhook expects specific data")

if __name__ == "__main__":
    # Replace with your actual domain
    domain = input("Enter your WordPress domain (e.g., mysite.com): ").strip()
    
    if not domain:
        print("❌ Please enter a valid domain")
        exit(1)
    
    test_webhook_url(domain)
