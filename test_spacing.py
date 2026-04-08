#!/usr/bin/env python3
import sys
sys.path.append('/app')

from pathlib import Path
from app.services.docling_service import DoclingService

def test_spacing():
    """Test that spacing values are now extracted."""
    docling = DoclingService()
    file_path = Path("/app/data/ประกาศ")
    
    if not file_path.exists():
        print("Test document not found")
        return
    
    print("Testing spacing extraction...")
    try:
        blocks = docling.extract(file_path, 'docx', 'test_doc')
        
        spacing_found = 0
        for page in blocks:
            for i, block in enumerate(page.get('blocks', [])):
                layout = block.get('meta', {}).get('layout', {})
                spacing_before = layout.get('spacing_before')
                spacing_after = layout.get('spacing_after')
                line_spacing = layout.get('line_spacing')
                
                if spacing_before or spacing_after or line_spacing:
                    spacing_found += 1
                    if spacing_found <= 5:  # Show first 5
                        raw_text = str(block.get('raw_text', ''))[:60]
                        print(f"\nBlock {i+1}:")
                        print(f"  Text: \"{raw_text}...\"")
                        if spacing_before:
                            print(f"  spacing_before: {spacing_before} twips = {spacing_before/20:.1f}pt")
                        if spacing_after:
                            print(f"  spacing_after: {spacing_after} twips = {spacing_after/20:.1f}pt")
                        if line_spacing:
                            print(f"  line_spacing: {line_spacing} = {line_spacing/240:.2f}x")
        
        print(f"\nTotal blocks with spacing: {spacing_found}")
                    
    except Exception as e:
        print(f"Error: {e}")
        import traceback
        traceback.print_exc()

if __name__ == "__main__":
    test_spacing()
