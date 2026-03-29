import pytest
from app.utils.indent_detector import (
    cluster_x_positions,
    detect_indent_level,
    create_indent_clusters,
    analyze_indent_distribution,
    normalize_indent_for_css,
    get_indent_statistics_by_level
)


class TestIndentClustering:
    """Test indent level clustering functionality."""
    
    def test_basic_clustering(self):
        x_positions = [72, 90, 108, 126]  # Typical Thai document indents
        clusters = cluster_x_positions(x_positions, step=18.0, margin_x=72.0)
        
        assert clusters[72] == 0    # margin level
        assert clusters[90] == 1    # 18pt indent
        assert clusters[108] == 2   # 36pt indent
        assert clusters[126] == 3   # 54pt indent
        
    def test_empty_positions(self):
        clusters = cluster_x_positions([], step=18.0, margin_x=72.0)
        assert clusters == {}
        
    def test_single_position(self):
        clusters = cluster_x_positions([72], step=18.0, margin_x=72.0)
        assert clusters[72] == 0
        
    def test_irregular_positions(self):
        x_positions = [72, 85, 110, 125]  # Not perfectly aligned
        clusters = cluster_x_positions(x_positions, step=18.0, margin_x=72.0)
        
        # Should round to nearest indent level
        assert clusters[72] == 0    # margin
        assert clusters[85] == 1    # ~13pt, rounds to level 1
        assert clusters[110] == 2   # ~38pt, rounds to level 2
        assert clusters[125] == 3   # ~53pt, rounds to level 3
        
    def test_different_step_size(self):
        x_positions = [72, 96, 120]  # 24pt steps
        clusters = cluster_x_positions(x_positions, step=24.0, margin_x=72.0)
        
        assert clusters[72] == 0    # margin
        assert clusters[96] == 1    # 24pt indent
        assert clusters[120] == 2   # 48pt indent


class TestDetectIndentLevel:
    """Test indent level detection for individual positions."""
    
    def test_exact_match(self):
        clusters = {72: 0, 90: 1, 108: 2}
        
        assert detect_indent_level(72, clusters) == 0
        assert detect_indent_level(90, clusters) == 1
        assert detect_indent_level(108, clusters) == 2
        
    def test_nearest_match(self):
        clusters = {72: 0, 90: 1, 108: 2}
        
        # Close to 90 (within half step)
        assert detect_indent_level(88, clusters) == 1
        
        # Close to 108
        assert detect_indent_level(110, clusters) == 2
        
    def test_far_from_clusters(self):
        clusters = {72: 0, 90: 1}
        
        # Far from any cluster, should calculate new level
        level = detect_indent_level(150, clusters)
        # (150 - 72) / 18 = 4.33, rounds to 4
        assert level == 4
        
    def test_empty_clusters(self):
        assert detect_indent_level(100, {}) == 0  # default level
        
    def test_default_level(self):
        clusters = {72: 0, 90: 1}
        assert detect_indent_level(200, clusters, default_level=5) == 5


class TestCreateIndentClusters:
    """Test detailed indent cluster creation."""
    
    def test_cluster_creation(self):
        x_positions = [72, 90, 90, 108, 108, 108, 126]
        clusters = create_indent_clusters(x_positions, step=18.0, margin_x=72.0)
        
        assert len(clusters) == 4  # 4 different levels
        
        # Check cluster details
        level_0 = next(c for c in clusters if c.indent_level == 0)
        assert level_0.center_x == 72.0
        assert len(level_0.x_positions) == 1
        
        level_2 = next(c for c in clusters if c.indent_level == 2)
        assert level_2.center_x == 108.0
        assert len(level_2.x_positions) == 3
        
    def test_empty_positions(self):
        clusters = create_indent_clusters([])
        assert clusters == []


class TestAnalyzeIndentDistribution:
    """Test indent distribution analysis."""
    
    def test_basic_analysis(self):
        x_positions = [72, 90, 108, 126]
        analysis = analyze_indent_distribution(x_positions)
        
        assert analysis["count"] == 4
        assert analysis["min_x"] == 72
        assert analysis["max_x"] == 126
        assert analysis["range"] == 54
        assert analysis["mean"] == 99.0  # (72+90+108+126)/4
        assert analysis["estimated_levels"] == 3  # (126-72)/18 + 1
        
    def test_empty_positions(self):
        analysis = analyze_indent_distribution([])
        
        assert analysis["count"] == 0
        assert analysis["min_x"] == 0.0
        assert analysis["max_x"] == 0.0
        assert analysis["estimated_levels"] == 0


class TestNormalizeIndentForCss:
    """Test CSS indent normalization."""
    
    def test_css_conversion(self):
        assert normalize_indent_for_css(0) == "0em"
        assert normalize_indent_for_css(1) == "1.5em"
        assert normalize_indent_for_css(2) == "3.0em"
        assert normalize_indent_for_css(3) == "4.5em"
        
    def test_custom_base_indent(self):
        assert normalize_indent_for_css(2, base_indent=2.0) == "4.0em"
        assert normalize_indent_for_css(3, base_indent=1.0) == "3.0em"


class TestGetIndentStatisticsByLevel:
    """Test indent statistics by level."""
    
    class MockCell:
        def __init__(self, x0):
            self.x0 = x0
    
    def test_level_statistics(self):
        cells = [
            self.MockCell(72),   # level 0
            self.MockCell(90),   # level 1
            self.MockCell(108),  # level 2
            self.MockCell(90),   # level 1 (duplicate)
            self.MockCell(126),  # level 3
        ]
        
        stats = get_indent_statistics_by_level(cells)
        
        assert len(stats) == 4  # 4 levels
        
        # Check level 1 stats (should have 2 cells)
        level_1_stats = stats[1]
        assert level_1_stats["count"] == 2
        assert level_1_stats["min_x"] == 90
        assert level_1_stats["max_x"] == 90
        assert level_1_stats["mean_x"] == 90.0
        
        # Check level 0 stats (should have 1 cell)
        level_0_stats = stats[0]
        assert level_0_stats["count"] == 1
        assert level_0_stats["min_x"] == 72
        assert level_0_stats["max_x"] == 72
        
    def test_empty_cells(self):
        stats = get_indent_statistics_by_level([])
        assert stats == {}


if __name__ == "__main__":
    pytest.main([__file__])
