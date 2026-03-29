from typing import List, Dict, Any, Tuple
from dataclasses import dataclass


@dataclass
class GapInfo:
    """Information about a gap between cells."""
    position: float  # x-coordinate where gap starts (end of left cell)
    gap_width: float  # Width of the gap in points
    gap_type: str  # "tab", "space", or "none"


@dataclass
class CellWithGap:
    """A cell with gap information for rendering."""
    text: str
    bbox: Tuple[float, float, float, float]
    gap_after: GapInfo = None


def detect_gaps(cells_in_line: List, tab_threshold: float = 10.0, 
               space_threshold: float = 3.0) -> List[GapInfo]:
    """Detect gaps between cells in a line.
    
    Args:
        cells_in_line: List of cells sorted by x-coordinate, must have bbox attribute
        tab_threshold: Gap width in points to classify as tab
        space_threshold: Gap width in points to classify as space
        
    Returns:
        List of GapInfo objects for gaps between cells
    """
    if len(cells_in_line) < 2:
        return []
        
    gaps = []
    
    for i in range(len(cells_in_line) - 1):
        current_cell = cells_in_line[i]
        next_cell = cells_in_line[i + 1]
        
        # Calculate gap: next cell's x0 - current cell's x1
        gap_width = next_cell.x0 - current_cell.x1
        
        # Determine gap type
        if gap_width <= 0:
            gap_type = "none"  # Overlapping cells
        elif gap_width < space_threshold:
            gap_type = "none"  # Too small, treat as touching
        elif gap_width < tab_threshold:
            gap_type = "space"
        else:
            gap_type = "tab"
            
        gap_info = GapInfo(
            position=current_cell.x1,  # Gap starts at end of current cell
            gap_width=gap_width,
            gap_type=gap_type
        )
        
        gaps.append(gap_info)
        
    return gaps


def join_cells_with_gaps(cells: List, gaps: List[GapInfo], 
                        tab_char: str = "\t", space_char: str = " ") -> str:
    """Join cell texts with appropriate spacing based on gaps.
    
    Args:
        cells: List of cells with text attribute
        gaps: List of GapInfo objects (should be len(cells) - 1)
        tab_char: Character to use for tab gaps
        space_char: Character to use for space gaps
        
    Returns:
        Joined text string with appropriate spacing
    """
    if not cells:
        return ""
        
    if len(cells) == 1:
        return cells[0].text
        
    result_parts = [cells[0].text]
    
    for i, gap in enumerate(gaps):
        if i + 1 < len(cells):
            # Add spacing based on gap type
            if gap.gap_type == "tab":
                result_parts.append(tab_char)
            elif gap.gap_type == "space":
                result_parts.append(space_char)
            # "none" gaps don't need spacing
            
            # Add next cell text
            result_parts.append(cells[i + 1].text)
            
    return "".join(result_parts)


def create_cells_with_gaps(cells: List, gaps: List[GapInfo]) -> List[CellWithGap]:
    """Create CellWithGap objects combining cells and gap information.
    
    Args:
        cells: List of cells with text and bbox attributes
        gaps: List of GapInfo objects
        
    Returns:
        List of CellWithGap objects
    """
    cells_with_gaps = []
    
    for i, cell in enumerate(cells):
        # Get gap after this cell (if any)
        gap_after = gaps[i] if i < len(gaps) else None
        
        cell_with_gap = CellWithGap(
            text=cell.text,
            bbox=cell.bbox,
            gap_after=gap_after
        )
        
        cells_with_gaps.append(cell_with_gap)
        
    return cells_with_gaps


def analyze_gap_distribution(gaps: List[GapInfo]) -> Dict[str, Any]:
    """Analyze the distribution of gaps in a line or document.
    
    Args:
        gaps: List of GapInfo objects
        
    Returns:
        Statistics about gap distribution
    """
    if not gaps:
        return {
            "total_gaps": 0,
            "tab_gaps": 0,
            "space_gaps": 0,
            "none_gaps": 0,
            "avg_gap_width": 0.0,
            "min_gap_width": 0.0,
            "max_gap_width": 0.0,
            "gap_widths": []
        }
        
    gap_widths = [gap.gap_width for gap in gaps]
    tab_gaps = [gap for gap in gaps if gap.gap_type == "tab"]
    space_gaps = [gap for gap in gaps if gap.gap_type == "space"]
    none_gaps = [gap for gap in gaps if gap.gap_type == "none"]
    
    return {
        "total_gaps": len(gaps),
        "tab_gaps": len(tab_gaps),
        "space_gaps": len(space_gaps),
        "none_gaps": len(none_gaps),
        "avg_gap_width": sum(gap_widths) / len(gap_widths) if gap_widths else 0.0,
        "min_gap_width": min(gap_widths) if gap_widths else 0.0,
        "max_gap_width": max(gap_widths) if gap_widths else 0.0,
        "gap_widths": gap_widths
    }


def estimate_optimal_thresholds(gaps: List[GapInfo]) -> Dict[str, float]:
    """Estimate optimal tab and space thresholds from gap data.
    
    Args:
        gaps: List of GapInfo objects
        
    Returns:
        Estimated optimal thresholds
    """
    if not gaps:
        return {"tab_threshold": 10.0, "space_threshold": 3.0}
        
    gap_widths = [gap.gap_width for gap in gaps]
    gap_widths.sort()
    
    # Simple heuristic: find natural breaks in gap sizes
    if len(gap_widths) < 3:
        return {"tab_threshold": 10.0, "space_threshold": 3.0}
        
    # Calculate differences between consecutive gap sizes
    differences = [gap_widths[i+1] - gap_widths[i] for i in range(len(gap_widths)-1)]
    
    # Find largest difference (likely the break between space and tab)
    if differences:
        max_diff_idx = differences.index(max(differences))
        if max_diff_idx < len(gap_widths) - 1:
            # Threshold is between the two gap sizes with largest difference
            space_threshold = (gap_widths[max_diff_idx] + gap_widths[max_diff_idx + 1]) / 2
            tab_threshold = space_threshold * 2  # Tab is roughly twice space
        else:
            space_threshold = 3.0
            tab_threshold = 10.0
    else:
        space_threshold = 3.0
        tab_threshold = 10.0
        
    return {
        "tab_threshold": max(5.0, tab_threshold),  # Minimum 5pt
        "space_threshold": max(1.0, space_threshold)  # Minimum 1pt
    }


def render_text_with_gaps(cells: List, gaps: List[GapInfo], 
                         css_tab_width: float = 48.0) -> str:
    """Render text with gaps as HTML/CSS with appropriate spacing.
    
    Args:
        cells: List of cells with text attribute
        gaps: List of GapInfo objects
        css_tab_width: Width of tab in CSS units (points)
        
    Returns:
        HTML string with CSS spacing
    """
    if not cells:
        return ""
        
    html_parts = []
    html_parts.append(cells[0].text)
    
    for i, gap in enumerate(gaps):
        if i + 1 < len(cells):
            if gap.gap_type == "tab":
                # Use CSS tab width
                html_parts.append(f'<span class="doc-tab" style="display:inline-block; width:{css_tab_width}pt;"></span>')
            elif gap.gap_type == "space":
                # Use actual gap width or default space
                gap_width_pt = min(gap.gap_width, css_tab_width / 2)  # Cap at half tab
                html_parts.append(f'<span class="doc-space" style="display:inline-block; width:{gap_width_pt}pt;"></span>')
            # "none" gaps don't need spacing
            
            html_parts.append(cells[i + 1].text)
            
    return "".join(html_parts)


def detect_line_structure(cells: List, tab_threshold: float = 10.0, 
                         space_threshold: float = 3.0) -> Dict[str, Any]:
    """Detect the structure of a line including gaps and alignment.
    
    Args:
        cells: List of cells sorted by x-coordinate
        tab_threshold: Threshold for tab detection
        space_threshold: Threshold for space detection
        
    Returns:
        Dictionary with line structure information
    """
    if not cells:
        return {
            "cells": [],
            "gaps": [],
            "has_tabs": False,
            "has_spaces": False,
            "alignment": "left",
            "total_width": 0.0
        }
        
    # Detect gaps
    gaps = detect_gaps(cells, tab_threshold, space_threshold)
    
    # Analyze gaps
    gap_analysis = analyze_gap_distribution(gaps)
    
    # Determine alignment (simple heuristic)
    first_cell_x = cells[0].x0
    if first_cell_x > 100:  # Rough threshold for center/right alignment
        alignment = "center" if first_cell_x > 200 else "right"
    else:
        alignment = "left"
        
    # Calculate total width
    total_width = cells[-1].x1 - cells[0].x0 if len(cells) > 1 else 0.0
    
    return {
        "cells": cells,
        "gaps": gaps,
        "has_tabs": gap_analysis["tab_gaps"] > 0,
        "has_spaces": gap_analysis["space_gaps"] > 0,
        "alignment": alignment,
        "total_width": total_width,
        "gap_analysis": gap_analysis
    }
