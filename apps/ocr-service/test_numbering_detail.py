#!/usr/bin/env python3
import sys
import os
sys.path.append('/app')

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
        
        numbering_blocks = 0
        total_blocks = 0
        
        for page in blocks:
            page_blocks = page.get('blocks', [])
            total_blocks += len(page_blocks)
            
            for i, block in enumerate(page_blocks):
                block_type = block.get('type')
                raw_text = str(block.get('raw_text', ''))
                
                # Check for numbering metadata
                numbering = block.get('meta', {}).get('numbering')
                if numbering:
                    numbering_blocks += 1
                    print(f"\nBlock {i+1} (numbered):")
                    print(f"  Type: {block_type}")
                    print(f"  Raw text: \"{raw_text[:100]}...\"")
                    print(f"  Numbering: {numbering}")
                    
                    layout = block.get('meta', {}).get('layout', {})
                    print(f"  Layout: indent_left={layout.get('indent_left')}, indent_hanging={layout.get('indent_hanging')}")
                elif block_type == 'list_item':
                    numbering_blocks += 1
                    print(f"\nBlock {i+1} (list_item):")
                    print(f"  Raw text: \"{raw_text[:100]}...\"")
                    print(f"  No numbering metadata found")
                elif i < 15:  # Show first few blocks for context
                    print(f"\nBlock {i+1} ({block_type}): \"{raw_text[:80]}...\"")
        
        print(f"\nSummary:")
        print(f"  Total blocks: {total_blocks}")
        print(f"  Blocks with numbering: {numbering_blocks}")
        print(f"  Percentage: {numbering_blocks/total_blocks*100:.1f}%")
        
    except Exception as e:
        print(f"Error: {e}")
        import traceback
        traceback.print_exc()

if __name__ == "__main__":
    test_numbering_extraction()
