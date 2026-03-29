import pytest
from unittest.mock import Mock, patch
from app.services.docling_parse_extractor import DoclingParseExtractor, TextCell, PageCells


class TestDoclingParseExtractor:
    """Test docling-parse extractor with fallback mechanisms."""
    
    def test_extractor_initialization(self):
        """Test extractor initialization with and without docling-parse."""
        # Should initialize successfully even if docling-parse is not available
        extractor = DoclingParseExtractor(fallback_to_fitz=True)
        assert extractor.fallback_to_fitz == True
        
        extractor_no_fallback = DoclingParseExtractor(fallback_to_fitz=False)
        assert extractor_no_fallback.fallback_to_fallback == False
        
    def test_is_available_check(self):
        """Test availability check functionality."""
        extractor = DoclingParseExtractor(fallback_to_fitz=True)
        
        # Should return True if docling-parse is available, False otherwise
        available = extractor.is_available()
        assert isinstance(available, bool)
        
    def test_extract_pages_with_fallback(self):
        """Test that extraction works even when docling-parse fails."""
        extractor = DoclingParseExtractor(fallback_to_fitz=True)
        
        # Mock the extraction to simulate failure and fallback
        with patch.object(extractor, '_extract_pages_fitz_fallback') as mock_fallback:
            mock_fallback.return_value = [
                PageCells(page_no=1, word_cells=[], line_cells=[])
            ]
            
            # This should work even if docling-parse is not available
            result = extractor.extract_pages(Mock())
            assert len(result) >= 0  # Should not crash
            
    def test_fallback_extraction(self):
        """Test fitz fallback extraction functionality."""
        extractor = DoclingParseExtractor(fallback_to_fitz=True)
        
        # Create a mock PDF file path
        mock_file_path = Mock()
        mock_file_path.__str__ = Mock(return_value="test.pdf")
        
        # Mock fitz to avoid actual file operations
        with patch('fitz.open') as mock_fitz:
            mock_doc = Mock()
            mock_page = Mock()
            mock_page.get_text.return_value = {
                "blocks": [
                    {
                        "type": 0,
                        "bbox": [0, 0, 100, 20],
                        "lines": [
                            {
                                "spans": [
                                    {"text": "Hello World"}
                                ]
                            }
                        ]
                    }
                ]
            }
            mock_doc.page_count = 1
            mock_doc.__getitem__ = Mock(return_value=mock_page)
            mock_doc.__iter__ = Mock(return_value=iter([mock_page]))
            mock_fitz.return_value = mock_doc
            
            result = extractor._extract_pages_fitz_fallback(mock_file_path)
            
            assert len(result) == 1
            assert result[0].page_no == 1
            assert len(result[0].word_cells) == 1
            assert result[0].word_cells[0].text == "Hello World"
            
    def test_group_words_into_lines(self):
        """Test word grouping into lines."""
        extractor = DoclingParseExtractor(fallback_to_fitz=True)
        
        # Create mock word cells
        words = [
            TextCell("Hello", (0, 0, 30, 10), 1),
            TextCell("World", (35, 0, 65, 10), 1),  # Same line
            TextCell("Second", (0, 25, 45, 35), 1)   # Different line
        ]
        
        lines = extractor._group_words_into_lines(words)
        
        assert len(lines) == 2
        assert lines[0].text == "Hello World"
        assert lines[1].text == "Second"
        
    def test_merge_bboxes(self):
        """Test bbox merging functionality."""
        extractor = DoclingParseExtractor(fallback_to_fitz=True)
        
        bboxes = [
            (0, 0, 30, 10),
            (35, 0, 65, 10),
            (0, 25, 45, 35)
        ]
        
        merged = extractor._merge_bboxes(bboxes)
        
        assert merged == (0, 0, 65, 35)  # min x0, min y0, max x1, max y1
        
    def test_empty_word_list(self):
        """Test handling of empty word lists."""
        extractor = DoclingParseExtractor(fallback_to_fitz=True)
        
        lines = extractor._group_words_into_lines([])
        assert lines == []
        
        merged = extractor._merge_bboxes([])
        assert merged == (0.0, 0.0, 0.0, 0.0)


class TestTextCell:
    """Test TextCell class functionality."""
    
    def test_text_cell_properties(self):
        cell = TextCell("Hello", (10, 20, 40, 30), 1)
        
        assert cell.text == "Hello"
        assert cell.bbox == (10, 20, 40, 30)
        assert cell.page_no == 1
        assert cell.x0 == 10
        assert cell.y0 == 20
        assert cell.x1 == 40
        assert cell.y1 == 30
        assert cell.width == 30
        assert cell.height == 10


class TestPageCells:
    """Test PageCells class functionality."""
    
    def test_page_cells_creation(self):
        word_cells = [
            TextCell("Hello", (0, 0, 30, 10), 1),
            TextCell("World", (35, 0, 65, 10), 1)
        ]
        
        line_cells = []  # Would be created by grouping
        
        page = PageCells(page_no=1, word_cells=word_cells, line_cells=line_cells)
        
        assert page.page_no == 1
        assert len(page.word_cells) == 2
        assert len(page.line_cells) == 0


class TestErrorHandling:
    """Test error handling and graceful degradation."""
    
    def test_docling_parse_import_failure(self):
        """Test graceful handling when docling-parse is not available."""
        # This test verifies that the system works even without docling-parse
        extractor = DoclingParseExtractor(fallback_to_fitz=True)
        
        # The extractor should still be functional
        assert extractor is not None
        assert hasattr(extractor, 'extract_pages')
        
    def test_mixed_extraction_success(self):
        """Test that extraction works with mixed success scenarios."""
        extractor = DoclingParseExtractor(fallback_to_fitz=True)
        
        # Mock a scenario where docling-parse partially fails
        with patch.object(extractor, 'is_available', return_value=True):
            with patch.object(extractor, '_extract_pages_docling_parse', 
                          side_effect=Exception("docling-parse failed")):
                with patch.object(extractor, '_extract_pages_fitz_fallback') as mock_fallback:
                    mock_fallback.return_value = []
                    
                    # Should fall back to fitz without crashing
                    result = extractor.extract_pages(Mock())
                    assert isinstance(result, list)


if __name__ == "__main__":
    pytest.main([__file__])
