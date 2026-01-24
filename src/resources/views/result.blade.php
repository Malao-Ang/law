<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Word to HTML Result</title>
    
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;700&display=swap" rel="stylesheet">

    <style>

        /* จำลองแผ่นกระดาษ A4 */
        .paper-container {
            background-color: white;
            width: var(--paper-width);
            min-height: var(--paper-height);
            padding: 10mm 10mm; /* Margin เหมือนใน Word */
            box-sizing: border-box;
            position: relative;
            overflow: hidden;
        }

    </style>
</head>
<body>

    <div class="paper-container">
        <div class="word-content">
            {!! $html !!}
        </div>
    </div>

</body>
</html>