from typing import List, Dict, Tuple
import numpy as np


class IndentCluster:
    """Represents a cluster of x-positions with the same indent level."""
    def __init__(self, center_x: float, indent_level: int, x_positions: List[float]):
        self.center_x = center_x
        self.indent_level = indent_level
        self.x_positions = x_positions
        self.min_x = min(x_positions) if x_positions else center_x
        self.max_x = max(x_positions) if x_positions else center_x


def cluster_x_positions(x_positions: List[float], step: float = 18.0, 
                       margin_x: float = 72.0) -> Dict[float, int]:
    """Cluster x-positions into indent levels.
    
    Args:
        x_positions: List of x-coordinates (in points)
        step: Step size for clustering in points (default 18pt ~ 6mm)
        margin_x: Default left margin to normalize against
        
    Returns:
        Dictionary mapping x_position -> indent_level (0, 1, 2, 3...)
    """
    if not x_positions:
        return {}
        
    # Remove duplicates and sort
    unique_x = sorted(list(set(x_positions)))
    
    # Normalize positions relative to margin
    normalized_x = [x - margin_x for x in unique_x]
    
    # Simple binning clustering
    clusters = {}
    
    for i, x_norm in enumerate(normalized_x):
        # Calculate indent level by dividing by step size
        indent_level = max(0, int(round(x_norm / step)))
        
        # Map back to original x position
        original_x = unique_x[i]
        clusters[original_x] = indent_level
        
    return clusters


def detect_indent_level(x0: float, clusters: Dict[float, int], 
                       default_level: int = 0) -> int:
    """Detect indent level for a given x0 position.
    
    Args:
        x0: X-coordinate to classify
        clusters: Mapping from x_position to indent_level
        default_level: Default level if no exact match found
        
    Returns:
        Indent level (0, 1, 2, 3...)
    """
    # Try exact match first
    if x0 in clusters:
        return clusters[x0]
        
    # Find nearest cluster
    if not clusters:
        return default_level
        
    nearest_x = min(clusters.keys(), key=lambda x: abs(x - x0))
    distance = abs(nearest_x - x0)
    
    # If within reasonable distance (half step), use nearest cluster
    step_size = 18.0  # Default step size
    if distance <= step_size / 2:
        return clusters[nearest_x]
        
    # Otherwise, calculate new level based on position
    margin_x = 72.0  # Default margin
    x_norm = x0 - margin_x
    return max(0, int(round(x_norm / step_size)))


def create_indent_clusters(x_positions: List[float], step: float = 18.0, 
                          margin_x: float = 72.0) -> List[IndentCluster]:
    """Create detailed indent cluster objects.
    
    Args:
        x_positions: List of x-coordinates
        step: Step size for clustering
        margin_x: Default left margin
        
    Returns:
        List of IndentCluster objects
    """
    if not x_positions:
        return []
        
    # Get basic clustering
    level_mapping = cluster_x_positions(x_positions, step, margin_x)
    
    # Group positions by level
    level_groups: Dict[int, List[float]] = {}
    for x_pos, level in level_mapping.items():
        if level not in level_groups:
            level_groups[level] = []
        level_groups[level].append(x_pos)
        
    # Create cluster objects
    clusters = []
    for level, positions in sorted(level_groups.items()):
        center_x = np.mean(positions) if positions else margin_x + (level * step)
        cluster = IndentCluster(center_x=center_x, indent_level=level, 
                              x_positions=positions)
        clusters.append(cluster)
        
    return clusters


def analyze_indent_distribution(x_positions: List[float]) -> Dict[str, any]:
    """Analyze the distribution of indent positions.
    
    Args:
        x_positions: List of x-coordinates
        
    Returns:
        Dictionary with analysis statistics
    """
    if not x_positions:
        return {
            "count": 0,
            "min_x": 0.0,
            "max_x": 0.0,
            "range": 0.0,
            "mean": 0.0,
            "median": 0.0,
            "std": 0.0,
            "estimated_step": 18.0,
            "estimated_levels": 0
        }
        
    # Basic statistics
    min_x = min(x_positions)
    max_x = max(x_positions)
    mean_x = np.mean(x_positions)
    median_x = np.median(x_positions)
    std_x = np.std(x_positions)
    
    # Estimate step size using differences between sorted positions
    sorted_x = sorted(set(x_positions))
    if len(sorted_x) > 1:
        differences = [sorted_x[i+1] - sorted_x[i] for i in range(len(sorted_x)-1)]
        # Use median of differences as estimated step
        estimated_step = np.median(differences) if differences else 18.0
    else:
        estimated_step = 18.0
        
    # Estimate number of levels
    margin_x = 72.0  # Default margin
    if estimated_step > 0:
        estimated_levels = int((max_x - margin_x) / estimated_step) + 1
    else:
        estimated_levels = 1
        
    return {
        "count": len(x_positions),
        "min_x": min_x,
        "max_x": max_x,
        "range": max_x - min_x,
        "mean": mean_x,
        "median": median_x,
        "std": std_x,
        "estimated_step": estimated_step,
        "estimated_levels": estimated_levels
    }


def normalize_indent_for_css(indent_level: int, base_indent: float = 1.5) -> str:
    """Convert indent level to CSS margin-left value.
    
    Args:
        indent_level: Indent level (0, 1, 2, 3...)
        base_indent: Base indent in em units
        
    Returns:
        CSS margin-left value (e.g., "0em", "1.5em", "3.0em")
    """
    return f"{indent_level * base_indent}em"


def get_indent_statistics_by_level(text_cells) -> Dict[int, Dict[str, any]]:
    """Get statistics for each indent level.
    
    Args:
        text_cells: List of text cells with x0 attribute
        
    Returns:
        Dictionary mapping indent_level -> statistics
    """
    if not text_cells:
        return {}
        
    # Get x positions
    x_positions = [cell.x0 for cell in text_cells]
    
    # Cluster positions
    clusters = cluster_x_positions(x_positions)
    
    # Group cells by indent level
    level_groups: Dict[int, List] = {}
    for cell in text_cells:
        level = detect_indent_level(cell.x0, clusters)
        if level not in level_groups:
            level_groups[level] = []
        level_groups[level].append(cell)
        
    # Calculate statistics for each level
    level_stats = {}
    for level, cells in level_groups.items():
        x_vals = [cell.x0 for cell in cells]
        level_stats[level] = {
            "count": len(cells),
            "min_x": min(x_vals),
            "max_x": max(x_vals),
            "mean_x": np.mean(x_vals),
            "median_x": np.median(x_vals),
            "std_x": np.std(x_vals)
        }
        
    return level_stats
