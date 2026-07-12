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
                'document_types' => [['title', 'value']],
                'statuses' => [['title', 'value']],
                'change_statuses' => [['title', 'value']],
                'agencies' => [['title', 'value', 'subtitle']],
                'law_groups' => [['title', 'value']],
            ]);

        $data = $response->json();
        foreach (['document_types', 'statuses', 'change_statuses', 'agencies', 'law_groups'] as $key) {
            $this->assertNotEmpty($data[$key], "Lookup list '{$key}' must not be empty");
        }

        $this->assertContains('พ.ร.บ.', array_column($data['document_types'], 'value'));
        $this->assertContains('มหาวิทยาลัยบูรพา', array_column($data['agencies'], 'value'));
        $this->assertContains('มีผลบังคับใช้', array_column($data['statuses'], 'value'));
        $this->assertContains('กฎหมายใหม่', array_column($data['change_statuses'], 'value'));
        $this->assertContains('ด้านการวิจัย นวัตกรรม และการนำไปใช้ประโยชน์', array_column($data['law_groups'], 'value'));
    }

    public function test_sample_seeder_law_types_exist_in_lookups(): void
    {
        $allowed = array_column(config('lookups.document_types'), 'value');
        $seededTypes = ['พ.ร.บ.', 'ระเบียบ', 'ประกาศ'];

        foreach ($seededTypes as $type) {
            $this->assertContains($type, $allowed, "Seeder law_type '{$type}' is not a canonical document type");
        }
    }
}
