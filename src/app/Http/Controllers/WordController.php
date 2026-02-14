<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use PhpOffice\PhpWord\IOFactory;
use Smalot\PdfParser\Parser;
use App\Models\Document;

class WordController extends Controller
{
    public function index()
    {
        return view('app');
    }

    public function convert(Request $request)
    {
        $request->validate([
            'file' => 'required|mimes:docx,pdf|max:10240'
        ]);

        $file = $request->file('file');
        $extension = $file->getClientOriginalExtension();
        $html = "";

        if ($extension === 'docx') {
            // Load Word file
            $phpWord = IOFactory::load($file->getPathname());
            
            // Save to HTML in memory
            $xmlWriter = IOFactory::createWriter($phpWord, 'HTML');
            
            // Capture output
            ob_start();
            $xmlWriter->save("php://output");
            $content = ob_get_contents();
            ob_end_clean();
            
            // Extract body content only
            if (preg_match('/<body[^>]*>(.*?)<\/body>/is', $content, $matches)) {
                $html = $matches[1];
            } else {
                $html = $content;
            }

            // Post-process HTML to improve formatting
            // 1. Ensure tables have proper borders and styling
            $html = preg_replace(
                '/<table([^>]*)>/',
                '<table$1 style="border-collapse: collapse; width: 100%; margin-bottom: 1em; font-family: \'Sarabun New\', sans-serif;">',
                $html
            );
            
            // 2. Add border to table cells if not present
            $html = preg_replace(
                '/<(td|th)([^>]*)>/',
                '<$1$2 style="border: 1px solid #000; padding: 8px; font-family: \'Sarabun New\', sans-serif;">',
                $html
            );

            // 3. Preserve tabs by converting them to proper spacing
            $html = str_replace("\t", '&nbsp;&nbsp;&nbsp;&nbsp;', $html);
            
            // 4. Wrap all content to set default font
            $html = '<div style="font-family: \'Sarabun New\', sans-serif; font-size: 16pt; line-height: 1.5;">' . $html . '</div>';

        } else if ($extension === 'pdf') {
            // Parse PDF
            $parser = new Parser();
            $pdf = $parser->parseFile($file->getPathname());
            $text = $pdf->getText();
            
            // Enhanced layout preservation for PDF
            $lines = explode("\n", $text);
            $processedLines = [];
            
            foreach ($lines as $line) {
                // Detect if line starts with spaces/tabs (indentation)
                if (preg_match('/^(\s+)(.*)$/', $line, $matches)) {
                    $spaces = $matches[1];
                    $content = $matches[2];
                    
                    // Convert leading spaces to non-breaking spaces
                    $indentCount = strlen($spaces);
                    $indent = str_repeat('&nbsp;', $indentCount);
                    $processedLines[] = $indent . htmlspecialchars($content);
                } else {
                    // Regular line
                    // Convert multiple spaces (potential tabs) to nbsp
                    $line = preg_replace('/  +/', function($match) {
                        return str_repeat('&nbsp;', strlen($match[0]));
                    }, $line);
                    $processedLines[] = htmlspecialchars($line);
                }
            }
            
            $html = implode('<br>', $processedLines);
            
            // Wrap in div with Thai-friendly font
            $html = '<div style="font-family: \'Sarabun New\', sans-serif; font-size: 16pt; line-height: 1.5; white-space: pre-wrap;">' . $html . '</div>';
        }

        return response()->json([
            'content' => $html,
            'type' => $extension
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'content' => 'required',
            'title' => 'nullable|string|max:255'
        ]);

        $document = Document::create([
            'title' => $request->input('title', 'Untitled Document'),
            'content' => $request->input('content')
        ]);

        return response()->json([
            'message' => 'Document saved successfully',
            'id' => $document->id
        ]);
    }
}