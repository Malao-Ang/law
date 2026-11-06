<?php

namespace Tests\Unit;

use App\Services\MasterData\LegalStructures;
use App\Services\MasterData\MasterDataKind;
use App\Services\MasterData\MasterDataStore;
use Tests\TestCase;

class LegalStructuresTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        app('mongo.blob.master')->truncate();
    }

    public function test_resolves_code_export_key_legacy_alias_and_name_including_inactive_items(): void
    {
        /** @var MasterDataStore $store */
        $store = app(MasterDataStore::class);
        $created = $store->create(MasterDataKind::LegalStructure, [
            'name' => 'ภาคผนวก',
            'attrs' => [
                'family_codes' => ['LFM01'],
                'file_types' => ['word'],
            ],
        ]);

        /** @var LegalStructures $legalStructures */
        $legalStructures = app(LegalStructures::class);

        $this->assertSame('LST004', $legalStructures->resolve('LST004')['code']);
        $this->assertSame('LST004', $legalStructures->resolve('CLAUSE')['code']);
        $this->assertSame('LST004', $legalStructures->resolve('ARTICLE')['code']);
        $this->assertSame('LST004', $legalStructures->resolve('ข้อ')['code']);
        $this->assertSame('LST011', $legalStructures->resolve('SECTION')['code']);
        $this->assertSame('LST012', $legalStructures->resolve('BOOK')['code']);
        $this->assertSame($created['code'], $legalStructures->resolve('ภาคผนวก')['code']);
        $this->assertFalse($legalStructures->resolve('ภาคผนวก')['is_active']);
    }

    public function test_structure_flags_and_document_filtering(): void
    {
        /** @var LegalStructures $legalStructures */
        $legalStructures = app(LegalStructures::class);

        $this->assertTrue($legalStructures->isHead('SECTION'));
        $this->assertFalse($legalStructures->isHead('DEFINITION'));
        $this->assertTrue($legalStructures->countsAsSection('CLAUSE'));
        $this->assertTrue($legalStructures->countsAsSection('SECTION'));
        $this->assertFalse($legalStructures->countsAsSection('CHAPTER'));
        $this->assertFalse($legalStructures->isRequired('TITLE'));
        $this->assertSame('SECTION', $legalStructures->exportKey('LST011'));

        $externalPdf = $legalStructures->forDocument('LFM04', 'pdf');
        $this->assertContains('LST011', array_column($externalPdf, 'code'));
        $this->assertNotContains('LST003', array_column($externalPdf, 'code'));
        $this->assertNotContains('LST004', array_column($externalPdf, 'code'));

        $internalWord = $legalStructures->forDocument('LFM03', 'word');
        $this->assertContains('LST004', array_column($internalWord, 'code'));
        $this->assertNotContains('LST011', array_column($internalWord, 'code'));
    }

    public function test_file_type_of_source_type(): void
    {
        /** @var LegalStructures $legalStructures */
        $legalStructures = app(LegalStructures::class);

        $this->assertSame('word', $legalStructures->fileTypeOf('docx'));
        $this->assertSame('word', $legalStructures->fileTypeOf('doc'));
        $this->assertSame('pdf', $legalStructures->fileTypeOf('pdf_text'));
        $this->assertSame('pdf', $legalStructures->fileTypeOf('pdf_scan'));
        $this->assertSame('pdf', $legalStructures->fileTypeOf('pdf_mixed'));
        $this->assertNull($legalStructures->fileTypeOf('image'));
    }
}
