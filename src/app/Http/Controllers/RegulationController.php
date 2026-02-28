<?php

namespace App\Http\Controllers;

use App\Models\Regulation;
use App\Models\RegulationSection;
use App\Services\SectionSplitter;
use App\Services\DocumentConvert\DocumentConvertService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RegulationController extends Controller
{
    public function __construct(
        private readonly DocumentConvertService $convertService,
        private readonly SectionSplitter $sectionSplitter
    ) {}

    public function index()
    {
        $regulations = Regulation::with('categories')
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return response()->json($regulations);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:500',
            'regulation_type' => 'required|in:regulation,announcement,rule,guideline,order',
            'enacted_date' => 'nullable|date',
            'effective_date' => 'nullable|date',
            'file' => 'required|file|mimes:docx,pdf|max:10240',
        ]);

        DB::beginTransaction();
        try {
            $file = $request->file('file');
            $ext = strtolower($file->getClientOriginalExtension());
            
            $html = $this->convertService->convertToHtml($file->getPathname(), $ext);

            $regulation = Regulation::create([
                'title' => $validated['title'],
                'regulation_type' => $validated['regulation_type'],
                'enacted_date' => $validated['enacted_date'] ?? null,
                'effective_date' => $validated['effective_date'] ?? null,
                'full_html' => $html,
                'original_filename' => $file->getClientOriginalName(),
                'file_type' => $ext,
                'created_by' => auth()->id(),
            ]);

            $sections = $this->sectionSplitter->splitHtmlIntoSections($html);

            foreach ($sections as $sectionData) {
                RegulationSection::create([
                    'regulation_id' => $regulation->id,
                    'parent_id' => null,
                    'section_type' => $sectionData['section_type'],
                    'section_number' => $sectionData['section_number'],
                    'section_label' => $sectionData['section_label'],
                    'content_html' => $sectionData['content_html'],
                    'content_text' => strip_tags($sectionData['content_text']),
                    'sort_order' => $sectionData['sort_order'],
                ]);
            }

            DB::commit();

            return response()->json([
                'message' => 'Regulation created successfully',
                'regulation' => $regulation->load('sections'),
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'error' => 'Failed to create regulation',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function show($id)
    {
        $regulation = Regulation::with(['sections' => function($query) {
            $query->orderBy('sort_order');
        }, 'categories', 'amendments'])->findOrFail($id);

        return response()->json($regulation);
    }

    public function update(Request $request, $id)
    {
        $regulation = Regulation::findOrFail($id);

        $validated = $request->validate([
            'title' => 'sometimes|string|max:500',
            'regulation_type' => 'sometimes|in:regulation,announcement,rule,guideline,order',
            'enacted_date' => 'nullable|date',
            'effective_date' => 'nullable|date',
            'status' => 'sometimes|in:active,amended,repealed',
        ]);

        $regulation->update($validated);

        return response()->json([
            'message' => 'Regulation updated successfully',
            'regulation' => $regulation,
        ]);
    }

    public function destroy($id)
    {
        $regulation = Regulation::findOrFail($id);
        $regulation->delete();

        return response()->json([
            'message' => 'Regulation deleted successfully',
        ]);
    }

    public function search(Request $request)
    {
        $query = $request->input('q');
        $type = $request->input('type');

        $sections = RegulationSection::query()
            ->when($query, function($q) use ($query) {
                $q->whereRaw('MATCH(content_text, section_number, section_label) AGAINST(? IN NATURAL LANGUAGE MODE)', [$query]);
            })
            ->when($type, function($q) use ($type) {
                $q->whereHas('regulation', function($subQ) use ($type) {
                    $subQ->where('regulation_type', $type);
                });
            })
            ->with('regulation')
            ->orderByRaw('MATCH(content_text, section_number, section_label) AGAINST(? IN NATURAL LANGUAGE MODE) DESC', [$query])
            ->paginate(20);

        return response()->json($sections);
    }

    public function getSections($regulationId)
    {
        $sections = RegulationSection::where('regulation_id', $regulationId)
            ->with(['children', 'parent'])
            ->whereNull('parent_id')
            ->orderBy('sort_order')
            ->get();

        return response()->json($sections);
    }

    public function updateSection(Request $request, $sectionId)
    {
        $section = RegulationSection::findOrFail($sectionId);

        $validated = $request->validate([
            'content_html' => 'required|string',
            'section_label' => 'nullable|string',
        ]);

        $section->update([
            'content_html' => $validated['content_html'],
            'content_text' => strip_tags($validated['content_html']),
            'section_label' => $validated['section_label'] ?? $section->section_label,
        ]);

        return response()->json([
            'message' => 'Section updated successfully',
            'section' => $section,
        ]);
    }
}
