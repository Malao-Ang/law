from typing import Iterable


def clamp_bbox(values: Iterable[float] | None) -> list[float] | None:
    if values is None:
        return None

    nums = [float(v) for v in values]
    if len(nums) != 4:
        return None

    return nums
