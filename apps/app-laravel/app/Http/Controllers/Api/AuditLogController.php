<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Audit\AuditExportCache;
use App\Services\Audit\AuditLogger;
use App\Services\Audit\AuditLogStore;
use App\Services\Auth\CurrentUser;
use App\Services\MasterData\UnitStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Settings;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AuditLogController extends Controller
{
    public function __construct(private readonly AuditLogStore $logs, private readonly CurrentUser $current) {}

    private function scope(Request $request): ?array
    {
        $user = $this->current->user($request);
        abort_if($user === null, 401, 'กรุณาเข้าสู่ระบบ');
        if ($user['is_super_admin'] ?? false) {
            return null;
        }
        $codes = array_values(array_column(array_filter($user['memberships'] ?? [], fn ($m) => $m['role'] === 'inner_admin' && $m['unit_code'] === $request->session()->get(CurrentUser::ACTIVE_UNIT_KEY)), 'unit_code'));
        abort_if($codes === [], 403, 'ไม่มีสิทธิ์ดูประวัติการใช้งาน');

        return $codes;
    }

    private function filter(Request $request, array $input, ?array $units): array
    {
        $filter = $this->logs->filter($input, $units);
        if ($units === null) return $filter;
        $access = app(\App\Services\Auth\DocumentAccess::class);
        $blocked = [];
        foreach (app(\App\Services\ReviewStore::class)->listLawMeta() as $document) {
            if (! $access->adminScope($request, $document)) $blocked[] = $document['document_id'];
        }
        return $blocked === [] ? $filter : ['$and' => [$filter, ['document_id' => ['$nin' => $blocked]]]];
    }

    private function redact(Request $request, array $row): array
    {
        if ($this->current->user($request)['is_super_admin'] ?? false) return $row;
        $access = app(\App\Services\Auth\DocumentAccess::class);
        $redact = function ($value) use (&$redact, $access, $request) {
            if (! is_array($value)) return $value;
            if (! empty($value['target_document_id'])) $value = $access->relatedRow($request, $value);
            foreach ($value as $key => $item) $value[$key] = $redact($item);
            return $value;
        };
        foreach (['before', 'after'] as $field) {
            $row[$field] = $redact($row[$field] ?? []);
            // AuditLogger stores dotted leaf paths; historical target identifiers may be absent from a diff.
            foreach (array_keys($row[$field]) as $key) {
                if (preg_match('/(^|\.)(relations|parent_document_id|parent_document_ids|repealed_laws|co_editors|co_editor_usernames)(\.|$)/', (string) $key)) unset($row[$field][$key]);
            }
        }
        if (($row['resource'] ?? '') === 'relation') {
            $review = app(\App\Services\Storage\MongoBlobStore::class)->read('review', $row['document_id'] ?? '');
            $relation = collect($review['relations'] ?? [])->firstWhere('id', $row['resource_id']);
            $target = $relation['target_document_id'] ?? null;
            $document = is_string($target) ? $access->readDocument($target) : null;
            $row['changes'] = [];
            $row['before'] = $row['after'] = [];
            $row['label'] = $document['title'] ?? 'เอกสารจำกัดสิทธิ์';
            if ($document === null || ! $access->canRead($request, $document)) {
                $row['label'] = 'เอกสารจำกัดสิทธิ์';
                $row['changes'] = [];
                $row['before'] = $row['after'] = [];
            }
        }
        return $row;
    }

    private function input(Request $request): array
    {
        return $request->validate([
            'resource' => ['nullable', function ($attribute, $value, $fail) {
                if (! is_string($value) && (! is_array($value) || count(array_filter($value, 'is_string')) !== count($value))) {
                    $fail('เรื่องที่เลือกไม่ถูกต้อง');
                }
            }], 'action' => ['nullable', 'string', 'max:64'],
            'unit' => ['nullable', 'string', 'max:128'], 'actor' => ['nullable', 'string', 'max:128'],
            'document' => ['nullable', 'string', 'max:200'], 'q' => ['nullable', 'string', 'max:240'],
            'from' => ['nullable', 'date_format:Y-m-d'], 'to' => array_filter(['nullable', 'date_format:Y-m-d', $request->filled('from') ? 'after_or_equal:from' : null]),
            'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $units = $this->scope($request);
        $input = $this->input($request);
        $result = $this->logs->page($this->filter($request, $input, $units), (int) ($input['page'] ?? 1), (int) ($input['per_page'] ?? 25));
        $result['items'] = array_map(fn ($row) => $this->redact($request, $row), $result['items']);
        $result['units'] = array_values(array_filter(app(UnitStore::class)->all(), fn ($u) => $units === null || in_array($u['code'], $units, true)));

        return response()->json($result);
    }

    private function roleLabel(string $role): string
    {
        return match ($role) {
            'super_admin' => 'Super Admin', 'inner_admin' => 'Admin', 'outer_admin' => 'Outer Admin',
            'editor' => 'Editor', 'user' => 'User', default => $role,
        };
    }

    public function export(Request $request): StreamedResponse
    {
        $units = $this->scope($request);
        $filter = $this->filter($request, $this->input($request), $units);

        return response()->streamDownload(function () use ($filter, $request): void {
            $previousCache = Settings::getCache();
            $cache = new AuditExportCache;
            Settings::setCache($cache);
            $book = new Spreadsheet;
            try {
                $sheet = $book->getActiveSheet();
                $sheet->setTitle('ประวัติการใช้งาน');
                $headers = ['เวลา', 'ผู้ทำ', 'ชื่อผู้ทำ', 'บทบาท', 'หน่วยงาน', 'การกระทำ', 'เรื่อง', 'รายการ', 'เอกสาร', 'สรุป', 'รายละเอียดการเปลี่ยนแปลง', 'ผล', 'IP'];
                $sheet->fromArray($headers);
                $line = 2;
                foreach ($this->logs->rows($filter) as $row) {
                    $row = $this->redact($request, $row);
                    $values = [$row['at'], $row['actor_username'] ?? '', $row['actor_name'], $this->roleLabel($row['actor_role']), $row['unit_code'] ?? '',
                        $row['action_label'], $row['resource_label'], $row['label'], $row['document_id'] ?? '', $row['summary'],
                        json_encode(['before' => $row['before'] ?? [], 'after' => $row['after'] ?? [], 'changes' => $row['changes']], JSON_UNESCAPED_UNICODE),
                        match ($row['result'] ?? 'success') {
                            'denied' => 'ปฏิเสธ', 'failed' => 'ไม่สำเร็จ', default => 'สำเร็จ'
                        }, $row['ip']];
                    foreach ($values as $column => $value) {
                        $sheet->setCellValueExplicit([$column + 1, $line], (string) $value, DataType::TYPE_STRING);
                    }
                    $line++;
                }
                $sheet->freezePane('A2');
                $writer = new Xlsx($book);
                $writer->setUseDiskCaching(true, storage_path('app'));
                $writer->save('php://output');
                app(AuditLogger::class)->record('export', 'audit_log', 'audit-logs', 'ส่งออกประวัติการใช้งาน', ['จำนวน '.($line - 2).' รายการ']);
            } finally {
                $book->disconnectWorksheets();
                Settings::setCache($previousCache);
                $cache->clear();
            }
        }, 'audit-logs-'.now()->format('Ymd-His').'.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'Cache-Control' => 'no-store']);
    }
}
