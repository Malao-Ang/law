from typing import Iterable, List, Tuple, Optional


def clamp_bbox(values: Iterable[float] | None) -> list[float] | None:
    if values is None:
        return None

    nums = [float(v) for v in values]
    if len(nums) != 4:
        return None

    return nums


def bbox_overlap_ratio(rect1: List[float] | Tuple[float, float, float, float], 
                      rect2: List[float] | Tuple[float, float, float, float]) -> float:
    """Calculate the overlap ratio between two bounding boxes.
    
    Returns the ratio of the intersection area to the smaller rectangle's area.
    This ensures we don't penalize small text cells overlapping large table cells.
    """
    if not rect1 or not rect2 or len(rect1) != 4 or len(rect2) != 4:
        return 0.0
        
    x0_1, y0_1, x1_1, y1_1 = rect1
    x0_2, y0_2, x1_2, y1_2 = rect2
    
    # Calculate intersection
    ix0 = max(x0_1, x0_2)
    iy0 = max(y0_1, y0_2)
    ix1 = min(x1_1, x1_2)
    iy1 = min(y1_1, y1_2)
    
    if ix1 <= ix0 or iy1 <= iy0:
        return 0.0
        
    intersection_area = (ix1 - ix0) * (iy1 - iy0)
    
    # Calculate areas
    area1 = (x1_1 - x0_1) * (y1_1 - y0_1)
    area2 = (x1_2 - x0_2) * (y1_2 - y0_2)
    
    if area1 <= 0 or area2 <= 0:
        return 0.0
        
    # Return ratio relative to smaller area
    min_area = min(area1, area2)
    return intersection_area / min_area


def merge_bboxes(bboxes: List[List[float] | Tuple[float, float, float, float]]) -> List[float]:
    """Merge multiple bounding boxes into one containing all of them."""
    if not bboxes:
        return [0.0, 0.0, 0.0, 0.0]
        
    valid_bboxes = [bbox for bbox in bboxes if bbox and len(bbox) == 4]
    if not valid_bboxes:
        return [0.0, 0.0, 0.0, 0.0]
        
    x_coords = [bbox[0] for bbox in valid_bboxes]
    y_coords = [bbox[1] for bbox in valid_bboxes]
    x1_coords = [bbox[2] for bbox in valid_bboxes]
    y1_coords = [bbox[3] for bbox in valid_bboxes]
    
    return [min(x_coords), min(y_coords), max(x1_coords), max(y1_coords)]


def merge_text_into_table_cells(text_cells, table_cells, threshold: float = 0.30) -> List[dict]:
    """Merge text cells into table cells based on bbox overlap.
    
    Args:
        text_cells: List of text cells with text and bbox attributes
        table_cells: List of table cells with row, col, bbox attributes
        threshold: Minimum overlap ratio to consider text as part of a cell
        
    Returns:
        List of merged cell dictionaries with combined text
    """
    merged_cells = []
    
    for table_cell in table_cells:
        # Find text cells that overlap with this table cell
        overlapping_texts = []
        
        for text_cell in text_cells:
            overlap = bbox_overlap_ratio(text_cell.bbox, table_cell.bbox)
            if overlap >= threshold:
                overlapping_texts.append(text_cell)
                
        # Sort overlapping texts by y-coordinate, then x-coordinate
        overlapping_texts.sort(key=lambda t: (t.y0, t.x0))
        
        # Join text content
        combined_text = " ".join(t.text for t in overlapping_texts)
        
        # Create merged cell
        merged_cell = {
            "row": table_cell.row,
            "col": table_cell.col,
            "text": combined_text,
            "bbox": table_cell.bbox,
            "colspan": table_cell.colspan,
            "rowspan": table_cell.rowspan,
            "source_texts": overlapping_texts  # Keep source for debugging
        }
        
        merged_cells.append(merged_cell)
        
    return merged_cells


def filter_text_outside_tables(text_cells, table_bboxes, threshold: float = 0.30) -> List:
    """Filter out text cells that are inside table regions.
    
    Args:
        text_cells: List of text cells
        table_bboxes: List of table bounding boxes
        threshold: Minimum overlap ratio to consider text as inside table
        
    Returns:
        List of text cells that are outside any table
    """
    if not table_bboxes:
        return text_cells
        
    outside_texts = []
    
    for text_cell in text_cells:
        is_inside_table = False
        
        for table_bbox in table_bboxes:
            overlap = bbox_overlap_ratio(text_cell.bbox, table_bbox)
            if overlap >= threshold:
                is_inside_table = True
                break
                
        if not is_inside_table:
            outside_texts.append(text_cell)
            
    return outside_texts


def bbox_center(bbox: List[float] | Tuple[float, float, float, float]) -> Tuple[float, float]:
    """Calculate the center point of a bounding box."""
    if not bbox or len(bbox) != 4:
        return (0.0, 0.0)
        
    x0, y0, x1, y1 = bbox
    return ((x0 + x1) / 2, (y0 + y1) / 2)


def bbox_area(bbox: List[float] | Tuple[float, float, float, float]) -> float:
    """Calculate the area of a bounding box."""
    if not bbox or len(bbox) != 4:
        return 0.0
        
    x0, y0, x1, y1 = bbox
    width = x1 - x0
    height = y1 - y0
    
    return max(0.0, width * height)


def bbox_contains_point(bbox: List[float] | Tuple[float, float, float, float], 
                       point: Tuple[float, float]) -> bool:
    """Check if a point is inside a bounding box."""
    if not bbox or len(bbox) != 4:
        return False
        
    x0, y0, x1, y1 = bbox
    px, py = point
    
    return x0 <= px <= x1 and y0 <= py <= y1
