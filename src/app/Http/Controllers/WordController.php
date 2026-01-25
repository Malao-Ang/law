<?php

namespace App\Http\Controllers;

use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\Settings;
use Illuminate\Http\Request;
use Spatie\Browsershot\Browsershot;

class WordController extends Controller
{
    public function uploadForm()
    {
        return view('upload');
    }

   public function convert(Request $request)
{
    $request->validate([
        'file' => 'required|mimes:docx,pdf|max:10240' // รองรับทั้ง docx และ pdf
    ]);

    $file = $request->file('file');
    $extension = $file->getClientOriginalExtension();
    $html = "";
    $pdfUrl = "";

    if ($extension === 'docx') {
        // ถ้าเป็น Word: แปลงเป็น HTML
        $phpWord = \PhpOffice\PhpWord\IOFactory::load($file->getPathname());
        $writer = \PhpOffice\PhpWord\IOFactory::createWriter($phpWord, 'HTML');
        $html = $writer->getContent();
        
        // (Optional) หากมี PDF ต้นฉบับในระบบอยู่แล้ว ให้กำหนด path ที่นี่
        $pdfUrl = null; 
    } else {
        // ถ้าเป็น PDF: เก็บไฟล์ไว้ใน public เพื่อนำไป Preview
        $fileName = time() . '.pdf';
        $file->move(public_path('uploads'), $fileName);
        $pdfUrl = asset('uploads/' . $fileName);
        $html = "<p>เอกสาร PDF ต้นฉบับ (แก้ไขไม่ได้โดยตรง กรุณาพิมพ์เนื้อหาใหม่ที่นี่)</p>";
    }

    return view('result', compact('html', 'pdfUrl'));
}
    public function exportPdf(Request $request)
{
    $html = $request->input('content'); // รับค่าจาก TinyMCE

    // สร้างไฟล์ HTML ชั่วคราวที่มีสไตล์กระดาษ
    $fullHtml = view('pdf_template', ['content' => $html])->render();

    $pdfPath = storage_path('app/public/document.pdf');

    Browsershot::html($fullHtml)
        ->paperSize(210, 297) // ขนาด A4 (mm)
        ->margins(10, 10, 10, 10) // ขอบกระดาษ
        ->save($pdfPath);

    return response()->download($pdfPath);
}
}