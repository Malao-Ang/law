<?php

namespace App\Http\Controllers;

use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\Settings;
use Illuminate\Http\Request;

class WordController extends Controller
{
    public function uploadForm()
    {
        return view('upload');
    }

    public function convert(Request $request)
    {
        $request->validate([
            'file' => 'required|mimes:docx'
        ]);

        $file = $request->file('file');

        // โหลดไฟล์ Word
        $phpWord = IOFactory::load($file->getPathname());

        // สร้าง HTML Writer
        $writer = IOFactory::createWriter($phpWord, 'HTML');

        // ดึงเนื้อหา HTML
        $content = $writer->getContent();

        /* * เทคนิคพิเศษ:
         * 1. แทนที่ Tab (\t) เป็นช่องว่าง HTML (&nbsp;) 4 ตัว
         * 2. ครอบเนื้อหาด้วย Class 'word-content' เพื่อจัดการ CSS ต่อ
         */
        $content = str_replace("\t", '&nbsp;&nbsp;&nbsp;&nbsp;', $content);

        return view('result', ['html' => $content]);
    }
}