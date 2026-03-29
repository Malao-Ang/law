#!/usr/bin/env python3
"""Test script for HTML preview feature"""

import json
import requests
import sys

def test_preview():
    """Test the preview endpoint"""
    url = "http://localhost:8010/preview"
    
    # Test data
    payload = {
        "document_id": "doc_20260329_154201_dbbc8d",
        "format": "html",
        "include_styles": True,
        "include_metadata": True
    }
    
    try:
        print("Testing preview endpoint...")
        response = requests.post(url, json=payload, timeout=30)
        
        if response.status_code == 200:
            data = response.json()
            print(f"✅ Preview generated successfully!")
            print(f"Document ID: {data.get('document_id')}")
            print(f"Format: {data.get('format')}")
            print(f"HTML length: {len(data.get('html', ''))}")
            print(f"CSS included: {'Yes' if data.get('css') else 'No'}")
            print(f"Metadata included: {'Yes' if data.get('metadata') else 'No'}")
            
            # Save HTML preview to file
            with open("preview_output.html", "w", encoding="utf-8") as f:
                f.write(data.get('html', ''))
            print(f"📄 HTML preview saved to 'preview_output.html'")
            
            return True
        else:
            print(f"❌ Error: {response.status_code}")
            print(f"Response: {response.text}")
            return False
            
    except Exception as e:
        print(f"❌ Exception: {e}")
        return False

if __name__ == "__main__":
    success = test_preview()
    sys.exit(0 if success else 1)
