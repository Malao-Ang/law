<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <title>Word & PDF Manager</title>
    <style>
        body { font-family: 'Sarabun', sans-serif; display: flex; justify-content: center; padding: 50px; background: #f4f4f4; }
        .card { background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
    </style>
</head>
<body>
    <div class="card">
        <h1>ระบบจัดการเอกสาร</h1>
        <p>อัปโหลด Word เพื่อแก้ไข หรือ PDF เพื่อดูต้นฉบับ</p>

        <form action="/convert" method="post" enctype="multipart/form-data">
            @csrf
            <input type="file" name="file" accept=".docx,.pdf" required>
            <br><br>
            <button type="submit">ดำเนินการ</button>
        </form>
    </div>
</body>
</html>