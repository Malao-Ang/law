#!/usr/bin/env python3
import sys
import os
sys.path.append(os.path.join(os.path.dirname(__file__), 'apps', 'ocr-service', 'app'))

from pathlib import Path
from app.services.docling_service import DoclingService

def test_numbering_extraction():
    """Test DOCX numbering extraction with the Thai document."""
    docling = DoclingService()
    file_path = Path("/app/data/ประกาศ")
    
    if not file_path.exists():
        print("Test document not found")
        return
    
    print("Testing DOCX numbering extraction...")
    try:
        blocks = docling.extract(file_path, 'docx', 'test_doc')
        print(f"Extracted {len(blocks)} pages")
        
        for page in blocks:
            print(f"Page {page.get('page_no')}: {len(page.get('blocks', []))} blocks")
            for i, block in enumerate(page.get('blocks', [])[:10]):  # Show first 10 blocks
                raw_text = str(block.get('raw_text', ''))
                print(f"  Block {i+1}: {block.get('type')} - \"{raw_text[:100]}...\"")
                
                # Check for numbering metadata
                if block.get('meta', {}).get('numbering'):
                    numbering = block['meta']['numbering']
                    print(f"    Numbering: {numbering}")
                
                # Check for indentation
                layout = block.get('meta', {}).get('layout', {})
                if layout.get('indent_left'):
                    print(f"    Indent left: {layout['indent_left']}")
                if layout.get('indent_hanging'):
                    print(f"    Indent hanging: {layout['indent_hanging']}")
                    
    except Exception as e:
        print(f"Error: {e}")
        import traceback
        traceback.print_exc()

if __name__ == "__main__":
    test_numbering_extraction()
