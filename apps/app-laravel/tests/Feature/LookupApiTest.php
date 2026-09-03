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
                'change_status_types' => [['title', 'value', 'source']],
                'change_status_details' => [['title', 'value', 'source']],
                'agencies' => [['title', 'value', 'subtitle']],
                'law_groups' => [['title', 'value']],
            ]);

        $data = $response->json();
        foreach (['document_types', 'statuses', 'change_status_types', 'change_status_details', 'agencies', 'law_groups'] as $key) {
            $this->assertNotEmpty($data[$key], "Lookup list '{$key}' must not be empty");
        }

        $this->assertNotContains('กฎหมายภายนอก', array_column($data['document_types'], 'value'));
        $this->assertContains('ประกาศที่ออกโดยมหาวิทยาลัย', array_column($data['document_types'], 'value'));
        $this->assertContains('ประกาศที่ออกโดยสภามหาวิทยาลัย', array_column($data['document_types'], 'value'));
        $this->assertContains('มหาวิทยาลัยบูรพา', array_column($data['agencies'], 'value'));
        $this->assertContains('มีผลบังคับใช้', array_column($data['statuses'], 'value'));
        $this->assertContains('กฎหมายใหม่', array_column($data['change_status_types'], 'value'));
        $this->assertContains('ยกเลิกข้อ', array_column($data['change_status_details'], 'value'));
        $this->assertContains('ด้านการวิจัย นวัตกรรม และการนำไปใช้ประโยชน์', array_column($data['law_groups'], 'value'));
    }
}
