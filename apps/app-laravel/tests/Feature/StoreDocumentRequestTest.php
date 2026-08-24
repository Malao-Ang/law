<?php

namespace Tests\Feature;

use App\Http\Requests\StoreDocumentRequest;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class StoreDocumentRequestTest extends TestCase
{
    private function validate(array $data): \Illuminate\Contracts\Validation\Validator
    {
        return Validator::make($data, (new StoreDocumentRequest())->rules());
    }

    public function test_old_document_requires_source_and_law_type(): void
    {
        $v = $this->validate(['document_type' => 'old']);
        $this->assertTrue($v->errors()->has('source'));
        $this->assertTrue($v->errors()->has('law_type'));
    }

    public function test_law_type_must_match_source(): void
    {
        // พระราชบัญญัติ is external; declaring it internal is invalid
        $v = $this->validate([
            'document_type' => 'old',
            'source' => 'internal',
            'law_type' => 'พระราชบัญญัติ',
        ]);
        $this->assertTrue($v->errors()->has('law_type'));
    }

    public function test_valid_internal_old_document_passes_meta_rules(): void
    {
        $v = $this->validate([
            'document_type' => 'old',
            'source' => 'internal',
            'law_type' => 'ประกาศ',
        ]);
        $this->assertFalse($v->errors()->has('source'));
        $this->assertFalse($v->errors()->has('law_type'));
    }
}
