<p>เรียนผู้รับผิดชอบ</p>

<p>กฎหมาย <strong>{{ $lawTitle }}</strong> ใกล้ถึงกำหนดดำเนินการ</p>

<ul>
@foreach ($hits as $hit)
    <li>{{ $hit['label'] }} วันที่ {{ $hit['display_date'] }} (เหลือ {{ $hit['days_left'] }} วัน)</li>
@endforeach
</ul>

<p>กรุณาเข้าตรวจสอบสถานะและวันแล้วเสร็จได้ที่ <a href="{{ $oversightUrl }}">ระบบติดตามการกำกับดูแล</a></p>
