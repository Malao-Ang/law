#!/usr/bin/env python3
import sys
import os
sys.path.append('/app')

from pathlib import Path
from app.services.docling_service import DoclingService

def test_indent_fix():
    """Test that indentation values are now correct after the fix."""
    docling = DoclingService()
    file_path = Path("/app/data/ประกาศ")
    
    if not file_path.exists():
        print("Test document not found")
        return
    
    print("Testing indentation fix...")
    try:
        blocks = docling.extract(file_path, 'docx', 'test_doc')
        
        for page in blocks:
            for i, block in enumerate(page.get('blocks', [])):
                numbering = block.get('meta', {}).get('numbering')
                if numbering:
                    layout = block.get('meta', {}).get('layout', {})
                    indent_left = layout.get('indent_left')
                    indent_hanging = layout.get('indent_hanging')
                    
                    print(f"\nBlock {i+1} (numbered):")
                    print(f"  Numbering: {numbering}")
                    print(f"  Raw indent values: indent_left={indent_left}, indent_hanging={indent_hanging}")
                    
                    # Calculate what the CSS should be
                    if indent_left:
                        css_margin = indent_left / 20  # twips to points
                        print(f"  CSS margin-left: {css_margin:.1f}pt")
                    if indent_hanging:
                        css_indent = -indent_hanging / 20
                        print(f"  CSS text-indent: {css_indent:.1f}pt")
                    
                    # Show the actual text
                    raw_text = str(block.get('raw_text', ''))
                    print(f"  Text: \"{raw_text[:80]}...\"")
                    
                    if i >= 5:  # Show only first few numbered blocks
                        break
                        
    except Exception as e:
        print(f"Error: {e}")
        import traceback
        traceback.print_exc()

if __name__ == "__main__":
    test_indent_fix()
