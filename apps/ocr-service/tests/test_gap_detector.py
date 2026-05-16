import pytest
from app.utils.gap_detector import (
    GapInfo,
    detect_gaps,
    join_cells_with_gaps,
    create_cells_with_gaps,
    analyze_gap_distribution,
    estimate_optimal_thresholds,
    render_text_with_gaps,
    detect_line_structure
)


class MockCell:
    """Mock cell for testing."""
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


class TestDetectGaps:
    """Test gap detection between cells."""
    
    def test_no_gaps_single_cell(self):
        cells = [MockCell("Hello", (0, 0, 30, 10))]
        gaps = detect_gaps(cells, tab_threshold=10.0, space_threshold=3.0)
        
        assert len(gaps) == 0
        
    def test_tab_gap(self):
        cells = [
            MockCell("Hello", (0, 0, 30, 10)),
            MockCell("World", (50, 0, 80, 10))  # 20pt gap
        ]
        gaps = detect_gaps(cells, tab_threshold=10.0, space_threshold=3.0)
        
        assert len(gaps) == 1
        assert gaps[0].gap_width == 20.0
        assert gaps[0].gap_type == "tab"
        assert gaps[0].position == 30.0
        
    def test_space_gap(self):
        cells = [
            MockCell("Hello", (0, 0, 30, 10)),
            MockCell("World", (35, 0, 65, 10))  # 5pt gap
        ]
        gaps = detect_gaps(cells, tab_threshold=10.0, space_threshold=3.0)
        
        assert len(gaps) == 1
        assert gaps[0].gap_width == 5.0
        assert gaps[0].gap_type == "space"
        
    def test_no_gap_touching(self):
        cells = [
            MockCell("Hello", (0, 0, 30, 10)),
            MockCell("World", (30, 0, 60, 10))  # 0pt gap (touching)
        ]
        gaps = detect_gaps(cells, tab_threshold=10.0, space_threshold=3.0)
        
        assert len(gaps) == 1
        assert gaps[0].gap_width == 0.0
        assert gaps[0].gap_type == "none"
        
    def test_overlapping_cells(self):
        cells = [
            MockCell("Hello", (0, 0, 35, 10)),
            MockCell("World", (30, 0, 60, 10))  # -5pt gap (overlapping)
        ]
        gaps = detect_gaps(cells, tab_threshold=10.0, space_threshold=3.0)
        
        assert len(gaps) == 1
        assert gaps[0].gap_width == -5.0
        assert gaps[0].gap_type == "none"


class TestJoinCellsWithGaps:
    """Test joining cells with appropriate spacing."""
    
    def test_join_with_tab(self):
        cells = [MockCell("Hello", (0, 0, 30, 10)), MockCell("World", (50, 0, 80, 10))]
        gaps = [GapInfo(position=30.0, gap_width=20.0, gap_type="tab")]
        
        result = join_cells_with_gaps(cells, gaps, tab_char="\t", space_char=" ")
        assert result == "Hello\tWorld"
        
    def test_join_with_space(self):
        cells = [MockCell("Hello", (0, 0, 30, 10)), MockCell("World", (35, 0, 65, 10))]
        gaps = [GapInfo(position=30.0, gap_width=5.0, gap_type="space")]
        
        result = join_cells_with_gaps(cells, gaps, tab_char="\t", space_char=" ")
        assert result == "Hello World"
        
    def test_join_no_gap(self):
        cells = [MockCell("Hello", (0, 0, 30, 10)), MockCell("World", (30, 0, 60, 10))]
        gaps = [GapInfo(position=30.0, gap_width=0.0, gap_type="none")]
        
        result = join_cells_with_gaps(cells, gaps, tab_char="\t", space_char=" ")
        assert result == "HelloWorld"
        
    def test_single_cell(self):
        cells = [MockCell("Hello", (0, 0, 30, 10))]
        gaps = []
        
        result = join_cells_with_gaps(cells, gaps, tab_char="\t", space_char=" ")
        assert result == "Hello"


class TestCreateCellsWithGaps:
    """Test creating cells with gap information."""
    
    def test_cells_with_gaps(self):
        cells = [
            MockCell("Hello", (0, 0, 30, 10)),
            MockCell("World", (50, 0, 80, 10))
        ]
        gaps = [GapInfo(position=30.0, gap_width=20.0, gap_type="tab")]
        
        cells_with_gaps = create_cells_with_gaps(cells, gaps)
        
        assert len(cells_with_gaps) == 2
        assert cells_with_gaps[0].text == "Hello"
        assert cells_with_gaps[0].gap_after.gap_type == "tab"
        assert cells_with_gaps[1].text == "World"
        assert cells_with_gaps[1].gap_after is None
        
    def test_last_cell_no_gap(self):
        cells = [MockCell("Hello", (0, 0, 30, 10))]
        gaps = []
        
        cells_with_gaps = create_cells_with_gaps(cells, gaps)
        
        assert len(cells_with_gaps) == 1
        assert cells_with_gaps[0].gap_after is None


class TestAnalyzeGapDistribution:
    """Test gap distribution analysis."""
    
    def test_basic_analysis(self):
        gaps = [
            GapInfo(30.0, 20.0, "tab"),
            GapInfo(80.0, 5.0, "space"),
            GapInfo(120.0, 0.0, "none")
        ]
        
        analysis = analyze_gap_distribution(gaps)
        
        assert analysis["total_gaps"] == 3
        assert analysis["tab_gaps"] == 1
        assert analysis["space_gaps"] == 1
        assert analysis["none_gaps"] == 1
        assert analysis["avg_gap_width"] == (20.0 + 5.0 + 0.0) / 3
        assert analysis["min_gap_width"] == 0.0
        assert analysis["max_gap_width"] == 20.0
        
    def test_empty_gaps(self):
        analysis = analyze_gap_distribution([])
        
        assert analysis["total_gaps"] == 0
        assert analysis["tab_gaps"] == 0
        assert analysis["space_gaps"] == 0
        assert analysis["none_gaps"] == 0
        assert analysis["avg_gap_width"] == 0.0


class TestEstimateOptimalThresholds:
    """Test optimal threshold estimation."""
    
    def test_threshold_estimation(self):
        gaps = [
            GapInfo(0, 2.0, "space"),
            GapInfo(0, 3.0, "space"),
            GapInfo(0, 8.0, "tab"),
            GapInfo(0, 12.0, "tab")
        ]
        
        thresholds = estimate_optimal_thresholds(gaps)
        
        assert "tab_threshold" in thresholds
        assert "space_threshold" in thresholds
        assert thresholds["tab_threshold"] >= 5.0  # minimum enforced
        assert thresholds["space_threshold"] >= 1.0  # minimum enforced
        
    def test_empty_gaps_thresholds(self):
        thresholds = estimate_optimal_thresholds([])
        
        assert thresholds["tab_threshold"] == 10.0  # default
        assert thresholds["space_threshold"] == 3.0   # default


class TestRenderTextWithGaps:
    """Test HTML rendering with gaps."""
    
    def test_render_with_tab(self):
        cells = [MockCell("Hello", (0, 0, 30, 10)), MockCell("World", (50, 0, 80, 10))]
        gaps = [GapInfo(position=30.0, gap_width=20.0, gap_type="tab")]
        
        html = render_text_with_gaps(cells, gaps, css_tab_width=48.0)
        
        assert "Hello" in html
        assert "World" in html
        assert 'class="doc-tab"' in html
        assert 'width:48.0pt' in html
        
    def test_render_with_space(self):
        cells = [MockCell("Hello", (0, 0, 30, 10)), MockCell("World", (35, 0, 65, 10))]
        gaps = [GapInfo(position=30.0, gap_width=5.0, gap_type="space")]
        
        html = render_text_with_gaps(cells, gaps, css_tab_width=48.0)
        
        assert "Hello" in html
        assert "World" in html
        assert 'class="doc-space"' in html
        
    def test_render_no_gap(self):
        cells = [MockCell("Hello", (0, 0, 30, 10)), MockCell("World", (30, 0, 60, 10))]
        gaps = [GapInfo(position=30.0, gap_width=0.0, gap_type="none")]
        
        html = render_text_with_gaps(cells, gaps, css_tab_width=48.0)
        
        assert html == "HelloWorld"  # No spacing inserted


class TestDetectLineStructure:
    """Test line structure detection."""
    
    def test_basic_structure(self):
        cells = [
            MockCell("Hello", (0, 0, 30, 10)),
            MockCell("World", (50, 0, 80, 10))
        ]
        
        structure = detect_line_structure(cells, tab_threshold=10.0, space_threshold=3.0)
        
        assert structure["cells"] == cells
        assert structure["has_tabs"] == True
        assert structure["has_spaces"] == False
        assert structure["alignment"] == "left"
        assert structure["total_width"] == 80.0
        assert "gap_analysis" in structure
        
    def test_no_cells(self):
        structure = detect_line_structure([], tab_threshold=10.0, space_threshold=3.0)
        
        assert structure["cells"] == []
        assert structure["has_tabs"] == False
        assert structure["has_spaces"] == False
        assert structure["total_width"] == 0.0
        
    def test_center_alignment(self):
        cells = [MockCell("Centered", (150, 0, 210, 10))]  # Far from left margin
        
        structure = detect_line_structure(cells, tab_threshold=10.0, space_threshold=3.0)
        
        assert structure["alignment"] == "center"  # Based on x0 > 100 threshold


if __name__ == "__main__":
    pytest.main([__file__])
