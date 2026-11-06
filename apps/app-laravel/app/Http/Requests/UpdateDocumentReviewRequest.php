<?php

namespace App\Http\Requests;

use App\Services\ReviewStore;
use App\Services\MasterData\EnforcementStatuses;
use App\Services\MasterData\ChangeStatuses;
use App\Services\MasterData\LawCategories;
use App\Services\MasterData\LawTypes;
use App\Services\MasterData\MasterDataKind;
use App\Services\MasterData\MasterDataStore;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UpdateDocumentReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $lawMeta = $this->input('law_meta');
        if (is_array($lawMeta)) {
            foreach (\App\Services\Auth\DocumentOwnership::FIELDS as $field) {
                unset($lawMeta[$field]);
            }
        }
        if (is_array($lawMeta) && array_key_exists('status', $lawMeta)) {
            $lawMeta['status'] = app(EnforcementStatuses::class)->resolve($lawMeta['status'])['code'] ?? trim((string) $lawMeta['status']);
        }
        if (is_array($lawMeta) && array_key_exists('law_type', $lawMeta)) {
            $resolved = app(LawTypes::class)->resolve($lawMeta['law_type']);
            if ($resolved !== null) {
                $lawMeta['law_type'] = (string) $resolved['code'];
            }
        }
        if (is_array($lawMeta) && array_key_exists('issuer', $lawMeta)) {
            unset($lawMeta['issuer']);
        }
        if (is_array($lawMeta) && (array_key_exists('law_groups', $lawMeta) || array_key_exists('law_group', $lawMeta))) {
            $lawMeta = $this->normalizeLawCategoryFields($lawMeta);
        }
        if (is_array($lawMeta) && (array_key_exists('change_status', $lawMeta) || array_key_exists('change_details', $lawMeta))) {
            $lawMeta = $this->normalizeChangeStatusFields($lawMeta);
        }

        $payload = [
            'reset_to_generated' => filter_var($this->input('reset_to_generated', false), FILTER_VALIDATE_BOOL),
        ];
        if (is_array($lawMeta)) {
            $payload['law_meta'] = $lawMeta;
        }

        $this->merge($payload);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'draft_html' => ['nullable', 'string'],
            'approved_by' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'reset_to_generated' => ['boolean'],
            'font_family' => ['nullable', 'string', 'in:sarabun,psk-sarabun,angsana'],
            'font_size_pt' => ['nullable', 'integer', 'min:8', 'max:72'],
            'page_margins' => ['nullable', 'array'],
            'page_margins.top' => ['nullable', 'integer', 'min:0', 'max:5760'],
            'page_margins.bottom' => ['nullable', 'integer', 'min:0', 'max:5760'],
            'page_margins.left' => ['nullable', 'integer', 'min:0', 'max:5760'],
            'page_margins.right' => ['nullable', 'integer', 'min:0', 'max:5760'],
            'metadata' => ['nullable', 'array'],
            'metadata.department' => ['nullable', 'string', 'max:255'],
            'metadata.doc_number' => ['nullable', 'string', 'max:120'],
            'metadata.date' => ['nullable', 'string', 'max:120'],
            'metadata.subject' => ['nullable', 'string', 'max:255'],
            'metadata.recipient' => ['nullable', 'string', 'max:255'],
            'metadata.reference' => ['nullable', 'string', 'max:255'],
            'metadata.attachments' => ['nullable', 'string', 'max:255'],
            'metadata.urgency' => ['nullable', 'string', 'max:120'],
            'metadata.confidentiality' => ['nullable', 'string', 'max:120'],
            'metadata.signatory_name' => ['nullable', 'string', 'max:255'],
            'metadata.signatory_position' => ['nullable', 'string', 'max:255'],
            'law_meta' => ['nullable', 'array'],
            'law_meta.uploaded_by' => ['prohibited'],
            'law_meta.responsible' => ['prohibited'],
            'law_meta.owner_unit_code' => ['prohibited'],
            'law_meta.status' => ['nullable', 'string', 'max:120', Rule::in(array_column(app(MasterDataStore::class)->all(MasterDataKind::EnforcementStatus), 'code'))],
            'law_meta.law_type' => ['nullable', 'string', 'max:120'],
            'law_meta.source' => ['nullable', 'string', 'in:internal,external'],
            'law_meta.law_group' => ['nullable', 'string', 'max:120'],
            'law_meta.change_status' => ['nullable', 'string', 'max:120'],
            'law_meta.change_details' => ['nullable', 'array'],
            'law_meta.change_details.*' => ['nullable', 'string', 'max:120'],
            'law_meta.agency' => ['nullable', 'string', 'max:255'],
            'law_meta.signer_group' => ['nullable', 'string', 'max:255'],
            'law_meta.promulgation_date' => ['nullable', 'string', 'max:120'],
            'law_meta.effective_date' => ['nullable', 'string', 'max:120'],
            'law_meta.gazette_reference' => ['nullable', 'string', 'max:255'],
            'law_meta.royal_command' => ['nullable', 'string', 'max:255'],
            'law_meta.repealed_laws' => ['nullable', 'array'],
            'law_meta.repealed_laws.*' => ['nullable', 'string', 'max:255'],
            'law_meta.keywords' => ['nullable', 'array', 'max:30'],
            'law_meta.keywords.*' => ['nullable', 'string', 'max:80'],
            'law_meta.law_groups' => ['nullable', 'array'],
            'law_meta.law_groups.*' => ['nullable', 'string', 'max:120'],
            'law_meta.agencies' => ['nullable', 'array'],
            'law_meta.agencies.*' => ['nullable', 'string', 'max:255'],
            'law_meta.section_count' => ['nullable', 'integer', 'min:0'],
            'law_meta.published_date' => ['nullable', 'string', 'max:120'],
            'law_meta.expiry_date' => ['nullable', 'string', 'max:120'],
            'law_meta.title' => ['nullable', 'string', 'max:500'],
            'law_meta.imported_by' => ['nullable', 'string', 'max:255'],
            'law_meta.parent_document_id' => ['nullable', 'string', 'max:128'],
            'law_meta.parent_document_ids' => ['nullable', 'array'],
            'law_meta.parent_document_ids.*' => ['nullable', 'string', 'max:128'],
            'law_meta.access_scope' => ['nullable', 'string', 'in:public,private'],
            'law_meta.permission_group_ids' => ['nullable', 'array'],
            'law_meta.permission_group_ids.*' => ['nullable', 'string', 'max:128'],
            'relations' => ['nullable', 'array'],
            'relations.*.id' => ['nullable', 'string', 'max:64'],
            'relations.*.scope' => ['nullable', 'string', 'in:document,section'],
            'relations.*.block_id' => ['nullable', 'string', 'max:64'],
            'relations.*.type' => ['nullable', 'string', 'in:related,repeals,amends,issued_under,supersedes'],
            'relations.*.target_document_id' => ['nullable', 'string', 'max:128'],
            'relations.*.target_title' => ['nullable', 'string', 'max:255'],
            'relations.*.target_section' => ['nullable', 'string', 'max:120'],
            'relations.*.target_block_id' => ['nullable', 'string', 'max:64'],
            'relations.*.note' => ['nullable', 'string', 'max:500'],
            'relations.*.url' => ['nullable', 'url', 'max:500'],
            'relations.*.change_detail' => ['nullable', 'string', 'max:120'],
            'relations.*.requires_new_proclamation' => ['nullable', 'boolean'],
            'relations.*.proclamation_scope' => ['nullable', 'string', 'in:whole,sections'],
            'relations.*.proclamation_sections' => ['nullable', 'array', 'max:30'],
            'relations.*.proclamation_sections.*' => ['nullable', 'string', 'max:120'],
            'relations.*.proclamation_due_date' => ['nullable', 'date_format:Y-m-d'],
            'relations.*.proclamation_issued_on' => ['nullable', 'date_format:Y-m-d'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $lawMeta = $this->input('law_meta');
            if (! is_array($lawMeta)) {
                return;
            }

            $store = app(MasterDataStore::class);
            $document = app(ReviewStore::class)->getReviewDocument((string) $this->route('documentId'));
            $current = $document['law_meta'] ?? [];
            $scope = $lawMeta['access_scope'] ?? $current['access_scope'] ?? 'public';
            if ($scope === 'private') {
                $groupIds = array_key_exists('permission_group_ids', $lawMeta)
                    ? $lawMeta['permission_group_ids'] : ($current['permission_group_ids'] ?? []);
                $groups = array_values(array_filter((array) $groupIds, fn ($id) => is_string($id) && trim($id) !== ''));
                if ($groups === [] || count($groups) !== count((array) $groupIds)) {
                    $validator->errors()->add('law_meta.permission_group_ids', 'กรุณาเลือกกลุ่มสิทธิ์อย่างน้อยหนึ่งกลุ่มสำหรับเอกสารจำกัดสิทธิ์');
                } else {
                    try {
                        app(\App\Services\Permissions\PermissionStore::class)->validateGroupIds($groups);
                    } catch (ValidationException $exception) {
                        $validator->errors()->add('law_meta.permission_group_ids', 'พบกลุ่มสิทธิ์ที่ไม่อยู่ในระบบ');
                    }
                }
            }
            foreach ([
                'status' => MasterDataKind::EnforcementStatus,
                'law_type' => MasterDataKind::LawType,
                'law_groups' => MasterDataKind::LawCategory,
                'change_status' => MasterDataKind::ChangeStatus,
            ] as $field => $kind) {
                $oldValues = (array) ($current[$field] ?? ($field === 'law_groups' ? ($current['law_group'] ?? []) : []));
                $oldCodes = array_map(fn ($value) => $store->resolve($kind, (string) $value)['code'] ?? '', $oldValues);
                foreach ((array) ($lawMeta[$field] ?? []) as $value) {
                    $item = $store->resolve($kind, (string) $value);
                    if ($item !== null && ! $item['is_active'] && ! in_array($item['code'], $oldCodes, true)) {
                        $validator->errors()->add('law_meta.'.$field, 'รายการนี้ถูกปิดใช้งาน กรุณาเลือกรายการอื่น');
                    }
                    if ($kind === MasterDataKind::LawType && $item !== null) {
                        $family = $store->find(MasterDataKind::LawFamily, $item['attrs']['family_code']);
                        if ($family !== null && ! $family['is_active'] && ! in_array($item['code'], $oldCodes, true)) {
                            $validator->errors()->add('law_meta.'.$field, 'รายการนี้ถูกปิดใช้งาน กรุณาเลือกรายการอื่น');
                        }
                    }
                }
            }

            $lawTypeValue = $lawMeta['law_type'] ?? null;
            if ($lawTypeValue === null || $lawTypeValue === '') {
                return;
            }

            /** @var LawTypes $lawTypes */
            $lawTypes = app(LawTypes::class);
            $lawType = $lawTypes->resolve($lawTypeValue);
            if ($lawType === null) {
                $validator->errors()->add('law_meta.law_type', 'ไม่พบประเภทเอกสารที่เลือก');

                return;
            }

            $source = $lawMeta['source'] ?? null;
            if ($source !== null && $source !== '' && $lawTypes->sourceOf($lawTypeValue) !== $source) {
                $validator->errors()->add('law_meta.source', 'ประเภทเอกสารไม่ตรงกับแหล่งที่มา');
            }

        });
    }

    public function messages(): array
    {
        return [
            'string' => 'กรุณาระบุข้อมูลเป็นข้อความ',
            'array' => 'รูปแบบรายการข้อมูลไม่ถูกต้อง',
            'integer' => 'กรุณาระบุข้อมูลเป็นจำนวนเต็ม',
            'boolean' => 'ค่าสถานะไม่ถูกต้อง',
            'in' => 'ค่าที่เลือกไม่ถูกต้อง',
            'min' => 'ข้อมูลต้องมีค่าไม่น้อยกว่า :min',
            'max' => 'ข้อมูลเกินขนาดที่กำหนด (:max)',
            'prohibited' => 'ไม่อนุญาตให้แก้ไขข้อมูลผู้รับผิดชอบหรือหน่วยงานผ่านรายการนี้',
            'url' => 'รูปแบบลิงก์ไม่ถูกต้อง',
            'date_format' => 'รูปแบบวันที่ไม่ถูกต้อง กรุณาระบุปี-เดือน-วัน',
            'law_meta.permission_group_ids.array' => 'รูปแบบรายการกลุ่มสิทธิ์ไม่ถูกต้อง',
            'law_meta.permission_group_ids.*.string' => 'รหัสกลุ่มสิทธิ์ต้องเป็นข้อความ',
            'law_meta.permission_group_ids.*.max' => 'รหัสกลุ่มสิทธิ์ยาวเกินกำหนด',
        ];
    }

    protected function failedValidation(\Illuminate\Contracts\Validation\Validator $validator)
    {
        throw new \Illuminate\Http\Exceptions\HttpResponseException(response()->json([
            'message' => 'ข้อมูลไม่ถูกต้อง กรุณาตรวจสอบรายการที่ระบุ',
            'errors' => $validator->errors()->messages(),
        ], 422));
    }

    /**
     * @param  array<string, mixed>  $lawMeta
     * @return array<string, mixed>
     */
    private function normalizeLawCategoryFields(array $lawMeta): array
    {
        /** @var LawCategories $categories */
        $categories = app(LawCategories::class);
        $values = [];
        if (array_key_exists('law_groups', $lawMeta)) {
            $values = is_array($lawMeta['law_groups']) ? $lawMeta['law_groups'] : [$lawMeta['law_groups']];
        }
        if ($values === [] && array_key_exists('law_group', $lawMeta)) {
            $values = [$lawMeta['law_group']];
        }

        $codes = [];
        foreach ($values as $value) {
            $raw = trim((string) $value);
            if ($raw === '') {
                continue;
            }

            $resolved = $categories->resolve($raw);
            if ($resolved === null) {
                throw ValidationException::withMessages([
                    'law_meta.law_groups' => ['ไม่พบหมวดเอกสารที่เลือก'],
                ]);
            }

            $code = (string) ($resolved['code'] ?? '');
            if ($code !== '' && ! in_array($code, $codes, true)) {
                $codes[] = $code;
            }
        }

        $lawMeta['law_groups'] = $codes;
        $lawMeta['law_group'] = $codes[0] ?? '';

        return $lawMeta;
    }

    /**
     * @param  array<string, mixed>  $lawMeta
     * @return array<string, mixed>
     */
    private function normalizeChangeStatusFields(array $lawMeta): array
    {
        /** @var ChangeStatuses $changeStatuses */
        $changeStatuses = app(ChangeStatuses::class);

        if (array_key_exists('change_status', $lawMeta)) {
            $raw = trim((string) ($lawMeta['change_status'] ?? ''));
            if ($raw !== '') {
                $resolved = $changeStatuses->resolve($raw);
                if ($resolved === null) {
                    throw ValidationException::withMessages([
                        'law_meta.change_status' => ['ไม่พบสถานะการเปลี่ยนแปลงที่เลือก'],
                    ]);
                }
                $lawMeta['change_status'] = (string) ($resolved['code'] ?? '');
            }
        }

        if (array_key_exists('change_details', $lawMeta)) {
            $details = is_array($lawMeta['change_details']) ? $lawMeta['change_details'] : [$lawMeta['change_details']];
            $codes = [];
            foreach ($details as $detail) {
                $raw = trim((string) $detail);
                if ($raw === '') {
                    continue;
                }

                $resolved = $changeStatuses->resolveDetail($raw);
                if ($resolved === null) {
                    throw ValidationException::withMessages([
                        'law_meta.change_details' => ['ไม่พบรายละเอียดการเปลี่ยนแปลงที่เลือก'],
                    ]);
                }

                $code = (string) ($resolved['code'] ?? '');
                if ($code !== '' && ! in_array($code, $codes, true)) {
                    $codes[] = $code;
                }
            }
            $lawMeta['change_details'] = $codes;
        }

        return $lawMeta;
    }
}
