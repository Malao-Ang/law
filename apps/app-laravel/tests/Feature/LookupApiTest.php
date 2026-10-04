<?php

namespace Tests\Feature;

use Tests\TestCase;

class LookupApiTest extends TestCase
{
    public function test_lookups_endpoint_returns_all_lists(): void
    {
        $response = $this->getJson('/api/lookups');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'document_types' => [['title', 'value', 'code', 'family_code', 'source']],
                'document_types_all' => [['title', 'value', 'code', 'family_code', 'source']],
                'law_families' => [['title', 'value', 'code', 'source', 'color', 'sort_order']],
                'law_families_all' => [['title', 'value', 'code', 'source', 'color', 'sort_order']],
                'statuses' => [['title', 'value']],
                'change_status_types' => [['title', 'value', 'source']],
                'change_status_details' => [['title', 'value', 'source']],
                'agencies' => [['title', 'value', 'subtitle']],
                'law_groups' => [['title', 'value']],
            ]);

        $data = $response->json();
        foreach (['document_types', 'document_types_all', 'law_families', 'statuses', 'change_status_types', 'change_status_details', 'agencies', 'law_groups'] as $key) {
            $this->assertNotEmpty($data[$key], "Lookup list '{$key}' must not be empty");
        }
        $this->assertArrayNotHasKey('issuers', $data);
        $this->assertArrayNotHasKey('issuers_all', $data);

        $this->assertContains('LTY01', array_column($data['document_types'], 'value'));
        $announcement = collect($data['document_types'])->firstWhere('value', 'LTY01');
        $this->assertSame('LFM03', $announcement['family_code'] ?? null);
        $this->assertSame('internal', $announcement['source'] ?? null);
        $this->assertArrayNotHasKey('requires_issuer', $announcement);
        $this->assertContains('LFM04', array_column($data['law_families'], 'value'));
        $this->assertContains('มหาวิทยาลัยบูรพา', array_column($data['agencies'], 'value'));
        $this->assertContains('STA01', array_column($data['statuses'], 'value'));
        $inForce = collect($data['statuses'])->firstWhere('value', 'STA01');
        $this->assertSame('success', $inForce['color'] ?? null);
        $this->assertSame('in_force', $inForce['role'] ?? null);
        $this->assertContains('กฎหมายใหม่', array_column($data['change_status_types'], 'value'));
        $this->assertContains('ยกเลิกข้อ', array_column($data['change_status_details'], 'value'));
        $research = collect($data['law_groups'])->firstWhere('code', 'DCT003');
        $this->assertSame('ด้านการวิจัย นวัตกรรม และการนำไปใช้ประโยชน์', $research['title'] ?? null);
        $this->assertSame('DCT003', $research['value'] ?? null);
        $this->assertCount(12, $data['law_groups_all']);
    }
}
