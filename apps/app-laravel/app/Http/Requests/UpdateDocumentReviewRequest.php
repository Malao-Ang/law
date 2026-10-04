<?php

namespace App\Http\Requests;

use App\Services\MasterData\EnforcementStatuses;
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
            $resolved = app(LawTypes::class)->issuerResolve($lawMeta['issuer']);
            if ($resolved !== null) {
                $lawMeta['issuer'] = (string) $resolved['code'];
            }
        }
        if (is_array($lawMeta) && (array_key_exists('law_groups', $lawMeta) || array_key_exists('law_group', $lawMeta))) {
            $lawMeta = $this->normalizeLawCategoryFields($lawMeta);
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
            'law_meta.status' => ['nullable', 'string', 'max:120', Rule::in($this->activeEnforcementStatusCodes())],
            'law_meta.law_type' => ['nullable', 'string', 'max:120'],
            'law_meta.source' => ['nullable', 'string', 'in:internal,external'],
            'law_meta.law_group' => ['nullable', 'string', 'max:120'],
            'law_meta.change_status' => ['nullable', 'string', 'max:120'],
            'law_meta.change_details' => ['nullable', 'array'],
            'law_meta.change_details.*' => ['nullable', 'string', 'max:120'],
            'law_meta.agency' => ['nullable', 'string', 'max:255'],
            'law_meta.signer_group' => ['nullable', 'string', 'max:255'],
            'law_meta.issuer' => ['nullable', 'string', 'max:120'],
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
            'law_meta.permission_group_ids' => ['nullable', 'required_if:law_meta.access_scope,private', 'array', 'min:1'],
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
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $lawMeta = $this->input('law_meta');
            if (! is_array($lawMeta)) {
                return;
            }

            $lawTypeValue = $lawMeta['law_type'] ?? null;
            if ($lawTypeValue === null || $lawTypeValue === '') {
                return;
            }

            /** @var LawTypes $lawTypes */
            $lawTypes = app(LawTypes::class);
            $lawType = $lawTypes->resolve($lawTypeValue);
            if ($lawType === null) {
                $validator->errors()->add('law_meta.law_type', 'Invalid law type.');

                return;
            }

            $source = $lawMeta['source'] ?? null;
            if ($source !== null && $source !== '' && $lawTypes->sourceOf($lawTypeValue) !== $source) {
                $validator->errors()->add('law_meta.source', 'Law type source does not match.');
            }

            $issuer = trim((string) ($lawMeta['issuer'] ?? ''));
            if ($lawTypes->requiresIssuer($lawTypeValue)) {
                if ($issuer === '') {
                    $validator->errors()->add('law_meta.issuer', 'Issuer is required for this law type.');
                } elseif ($lawTypes->issuerResolve($issuer) === null) {
                    $validator->errors()->add('law_meta.issuer', 'Invalid issuer.');
                }

                return;
            }

            if ($issuer !== '') {
                $validator->errors()->add('law_meta.issuer', 'Issuer must be empty for this law type.');
            }
        });
    }

    /**
     * @return list<string>
     */
    private function activeEnforcementStatusCodes(): array
    {
        /** @var MasterDataStore $store */
        $store = app(MasterDataStore::class);

        return array_values(array_map(
            static fn (array $item): string => (string) $item['code'],
            array_filter(
                $store->all(MasterDataKind::EnforcementStatus),
                static fn (array $item): bool => (bool) ($item['is_active'] ?? false),
            ),
        ));
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
}
