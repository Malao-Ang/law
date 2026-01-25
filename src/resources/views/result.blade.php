<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Editor & Preview</title>
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;700&display=swap" rel="stylesheet">
    <script src="https://cdn.tiny.cloud/1/no-api-key/tinymce/6/tinymce.min.js" referrerpolicy="origin"></script>

    <style>
        :root { --paper-width: 210mm; --header-height: 60px; }
        body, html { margin: 0; padding: 0; height: 100%; overflow: hidden; background-color: #333; font-family: 'Sarabun', sans-serif; }
        .top-bar { height: var(--header-height); background: #222; color: white; display: flex; align-items: center; padding: 0 20px; justify-content: space-between; }
        .main-container { display: flex; height: calc(100vh - var(--header-height)); }
        
        /* ปรับสัดส่วนตามการมีอยู่ของ PDF */
        .editor-section { flex: 1; overflow-y: auto; padding: 20px; display: flex; justify-content: center; }
        .preview-section { flex: {{ $pdfUrl ? '1' : '0' }}; display: {{ $pdfUrl ? 'flex' : 'none' }}; background: #525659; border-left: 2px solid #111; }
        
        iframe { width: 100%; height: 100%; border: none; }
        .btn-save { background-color: #28a745; color: white; border: none; padding: 10px 20px; border-radius: 4px; cursor: pointer; }
        .tox-tinymce { width: var(--paper-width) !important; box-shadow: 0 0 15px rgba(0,0,0,0.5); }
    </style>
</head>
<body>

    <div class="top-bar">
        <span>Document System: {{ $pdfUrl ? 'Mode Preview PDF' : 'Mode Word Editor' }}</span>
        <button type="button" class="btn-save" onclick="saveData()">บันทึกข้อมูล</button>
    </div>

    <div class="main-container">
        <div class="editor-section">
            <textarea id="word-editor">{!! $html !!}</textarea>
        </div>

        @if($pdfUrl)
        <div class="preview-section">
            <iframe src="{{ $pdfUrl }}#view=FitH"></iframe>
        </div>
        @endif
    </div>

    <script>
        tinymce.init({
            selector: '#word-editor',
            plugins: 'advlist autolink lists link image charmap preview anchor table code fullscreen',
            toolbar: 'undo redo | fontfamily fontsize | bold italic underline | alignleft aligncenter alignright alignjustify | bullist numlist outdent indent | table | code',
            height: '100%',
            branding: false,
            content_style: `
                @import url('https://fonts.googleapis.com/css2?family=Sarabun&display=swap');
                body { font-family: 'Sarabun', sans-serif; font-size: 16pt; padding: 20mm !important; background: white; }
                table { border-collapse: collapse; width: 100%; }
                table td, table th { border: 1px solid #000; padding: 5px; }
            `
        });

        function saveData() {
            let content = tinymce.get('word-editor').getContent();
            console.log("Saved Content:", content);
            alert("บันทึกข้อมูลแล้ว (เช็คได้ที่ Console)");
        }
    </script>
</body>
</html>