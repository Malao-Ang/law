<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\DocumentConvert\DocumentConvertService;
use App\Models\Document;
use ZipArchive;

class WordController extends Controller
{
    public function __construct(
        private readonly DocumentConvertService $convertService
    ) {}

    public function index()
    {
        return view('app');
    }


    private function isRealDocx($path): bool
    {
        $zip = new ZipArchive;
        if ($zip->open($path) === TRUE) {
            $hasWordFolder = $zip->locateName('word/document.xml') !== false;
            $zip->close();
            return $hasWordFolder;
        }
        return false;
    }

    public function convert(Request $request)
    {
        // Debug: Check if file was uploaded
        if (!$request->hasFile('file')) {
            return response()->json(['error' => 'No file uploaded'], 422);
        }

        $file = $request->file('file');

        // Debug file info
        $fileInfo = [
            'originalName' => $file->getClientOriginalName(),
            'mimeType' => $file->getMimeType(),
            'extension' => $file->getClientOriginalExtension(),
            'size' => $file->getSize(),
            'isValid' => $file->isValid(),
            'error' => $file->getError()
        ];

        // Try validation with explicit rules
        try {
            $request->validate([
                'file' => 'required|mimes:docx,pdf|max:10240'
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'error' => 'Validation failed',
                'fileInfo' => $fileInfo,
                'validation_errors' => $e->errors()
            ], 422);
        }

        $extension = strtolower($file->getClientOriginalExtension());

        if ($extension === 'docx' && !$this->isRealDocx($file->getPathname())) {
            return response()->json(['error' => 'Corrupted DOCX file'], 400);
        }


        $ext = strtolower($file->getClientOriginalExtension());

        if (!in_array($ext, ['docx', 'pdf'])) {
            return response()->json(['error' => "Unsupported file extension: $ext"], 422);
        }

        try {
            $html = $this->convertService->convertToHtml($file->getPathname(), $ext);

            return response()->json([
                'content' => $html,
                'type' => $ext,
                'fileInfo' => $fileInfo
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Conversion failed',
                'message' => $e->getMessage(),
                'fileInfo' => $fileInfo
            ], 500);
        }
    }

    public function store(Request $request)
    {
        $request->validate([
            'content' => 'required',
            'title' => 'nullable|string|max:255',
        ]);

        $document = Document::create([
            'title' => $request->input('title', 'Untitled Document'),
            'content' => $request->input('content'),
        ]);

        return response()->json([
            'message' => 'Document saved successfully',
            'id' => $document->id,
        ]);
    }
}
