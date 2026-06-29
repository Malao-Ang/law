from app.api.schemas import (
    NormalizeBlock,
    NormalizeRequest,
    NormalizeBlockResult,
    NormalizeResponse,
)


def test_normalize_request_defaults_min_confidence_to_one():
    req = NormalizeRequest(
        document_id="doc_1",
        blocks=[NormalizeBlock(block_id="b1", text="abc")],
    )
    assert req.autocorrect_min_confidence == 1.0
    assert req.blocks[0].block_id == "b1"


def test_normalize_block_text_defaults_to_empty():
    block = NormalizeBlock(block_id="b1")
    assert block.text == ""


def test_normalize_response_round_trips_results():
    resp = NormalizeResponse(
        document_id="doc_1",
        results=[
            NormalizeBlockResult(
                block_id="b1",
                normalized_text="x",
                approved_text="y",
                auto_corrected=True,
                flags=["auto_corrected"],
                spell_suggestions=[],
            )
        ],
    )
    assert resp.results[0].approved_text == "y"
    assert resp.results[0].auto_corrected is True
