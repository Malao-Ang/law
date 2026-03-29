import pytest
from app.utils.bbox import (
    bbox_overlap_ratio,
    merge_bboxes,
    merge_text_into_table_cells,
    filter_text_outside_tables,
    bbox_center,
    bbox_area,
    bbox_contains_point
)


class MockTextCell:
    """Mock text cell for testing."""
    def __init__(self, text: str, bbox: tuple):
        self.text = text
        self.bbox = bbox
        
    @property
    def x0(self):
        return self.bbox[0]
        
    @property
    def y0(self):
        return self.bbox[1]
        
    @property
    def x1(self):
        return self.bbox[2]
        
    @property
    def y1(self):
        return self.bbox[3]


class MockTableCell:
    """Mock table cell for testing."""
    def __init__(self, text: str, bbox: tuple, row: int, col: int):
        self.text = text
        self.bbox = bbox
        self.row = row
        self.col = col
        self.colspan = 1
        self.rowspan = 1


class TestBboxOverlap:
    """Test bbox overlap ratio calculation."""
    
    def test_no_overlap(self):
        rect1 = [0, 0, 10, 10]
        rect2 = [20, 20, 30, 30]
        assert bbox_overlap_ratio(rect1, rect2) == 0.0
        
    def test_partial_overlap(self):
        rect1 = [0, 0, 10, 10]
        rect2 = [5, 5, 15, 15]
        # Intersection is 5x5 = 25, smaller rect is 10x10 = 100
        # Ratio = 25/100 = 0.25
        assert abs(bbox_overlap_ratio(rect1, rect2) - 0.25) < 0.01
        
    def test_complete_overlap(self):
        rect1 = [0, 0, 10, 10]
        rect2 = [2, 2, 8, 8]
        # Intersection is 6x6 = 36, smaller rect is 6x6 = 36
        # Ratio = 36/36 = 1.0
        assert bbox_overlap_ratio(rect1, rect2) == 1.0
        
    def test_invalid_inputs(self):
        assert bbox_overlap_ratio([], [0, 0, 10, 10]) == 0.0
        assert bbox_overlap_ratio([0, 0, 10], [0, 0, 10, 10]) == 0.0


class TestMergeBboxes:
    """Test bbox merging functionality."""
    
    def test_merge_multiple_bboxes(self):
        bboxes = [
            [0, 0, 10, 10],
            [5, 5, 15, 15],
            [20, 0, 30, 10]
        ]
        result = merge_bboxes(bboxes)
        expected = [0, 0, 30, 15]  # min x0, min y0, max x1, max y1
        assert result == expected
        
    def test_merge_empty_list(self):
        assert merge_bboxes([]) == [0.0, 0.0, 0.0, 0.0]
        
    def test_merge_invalid_bboxes(self):
        bboxes = [
            [0, 0, 10, 10],
            [5, 5],  # Invalid bbox
            [20, 0, 30, 10]
        ]
        result = merge_bboxes(bboxes)
        expected = [0, 0, 30, 10]  # Should ignore invalid bbox
        assert result == expected


class TestTextIntoTableMerge:
    """Test merging text cells into table cells."""
    
    def test_simple_merge(self):
        text_cells = [
            MockTextCell("Hello", [0, 0, 20, 10]),
            MockTextCell("World", [25, 0, 45, 10])
        ]
        
        table_cells = [
            MockTableCell("", [0, 0, 50, 10], 0, 0)
        ]
        
        merged = merge_text_into_table_cells(text_cells, table_cells, threshold=0.3)
        
        assert len(merged) == 1
        assert merged[0]["text"] == "Hello World"
        assert merged[0]["row"] == 0
        assert merged[0]["col"] == 0
        
    def test_no_overlap_merge(self):
        text_cells = [
            MockTextCell("Hello", [0, 0, 20, 10]),
            MockTextCell("World", [100, 0, 120, 10])  # Far away
        ]
        
        table_cells = [
            MockTableCell("", [0, 0, 50, 10], 0, 0)
        ]
        
        merged = merge_text_into_table_cells(text_cells, table_cells, threshold=0.3)
        
        assert len(merged) == 1
        assert merged[0]["text"] == "Hello"  # Only overlapping text
        assert len(merged[0]["source_texts"]) == 1
        
    def test_multiple_table_cells(self):
        text_cells = [
            MockTextCell("A", [0, 0, 10, 10]),
            MockTextCell("B", [20, 0, 30, 10]),
            MockTextCell("C", [40, 0, 50, 10])
        ]
        
        table_cells = [
            MockTableCell("", [0, 0, 15, 10], 0, 0),  # Should get "A"
            MockTableCell("", [20, 0, 35, 10], 0, 1), # Should get "B"
            MockTableCell("", [40, 0, 55, 10], 0, 2)  # Should get "C"
        ]
        
        merged = merge_text_into_table_cells(text_cells, table_cells, threshold=0.3)
        
        assert len(merged) == 3
        assert merged[0]["text"] == "A"
        assert merged[1]["text"] == "B"
        assert merged[2]["text"] == "C"


class TestFilterTextOutsideTables:
    """Test filtering text cells outside table regions."""
    
    def test_filter_outside_tables(self):
        text_cells = [
            MockTextCell("Outside", [0, 0, 20, 10]),
            MockTextCell("Inside", [50, 50, 70, 60])
        ]
        
        table_bboxes = [
            [45, 45, 75, 65]  # Contains "Inside" text
        ]
        
        outside = filter_text_outside_tables(text_cells, table_bboxes, threshold=0.3)
        
        assert len(outside) == 1
        assert outside[0].text == "Outside"
        
    def test_no_tables(self):
        text_cells = [
            MockTextCell("Text1", [0, 0, 20, 10]),
            MockTextCell("Text2", [30, 0, 50, 10])
        ]
        
        outside = filter_text_outside_tables(text_cells, [], threshold=0.3)
        
        assert len(outside) == 2  # All text should be outside
        
    def test_all_inside_tables(self):
        text_cells = [
            MockTextCell("Text1", [0, 0, 20, 10]),
            MockTextCell("Text2", [30, 0, 50, 10])
        ]
        
        table_bboxes = [
            [0, 0, 60, 20]  # Large table covering all text
        ]
        
        outside = filter_text_outside_tables(text_cells, table_bboxes, threshold=0.3)
        
        assert len(outside) == 0  # No text outside tables


class TestBboxUtilities:
    """Test bbox utility functions."""
    
    def test_bbox_center(self):
        bbox = [0, 0, 10, 10]
        center = bbox_center(bbox)
        assert center == (5.0, 5.0)
        
    def test_bbox_area(self):
        bbox = [0, 0, 10, 10]
        area = bbox_area(bbox)
        assert area == 100.0
        
    def test_bbox_contains_point(self):
        bbox = [0, 0, 10, 10]
        
        # Point inside
        assert bbox_contains_point(bbox, (5, 5)) == True
        
        # Point on edge
        assert bbox_contains_point(bbox, (0, 0)) == True
        assert bbox_contains_point(bbox, (10, 10)) == True
        
        # Point outside
        assert bbox_contains_point(bbox, (11, 5)) == False
        assert bbox_contains_point(bbox, (5, 11)) == False
        
    def test_invalid_bbox_utilities(self):
        invalid_bbox = []
        
        assert bbox_center(invalid_bbox) == (0.0, 0.0)
        assert bbox_area(invalid_bbox) == 0.0
        assert bbox_contains_point(invalid_bbox, (5, 5)) == False


if __name__ == "__main__":
    pytest.main([__file__])
