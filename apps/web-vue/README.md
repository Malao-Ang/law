# Docling Thai POC - Vue.js Frontend

โครงการสำหรับจัดการเอกสารกฎหมายไทยด้วย Vue.js และ Vuetify

## คุณสมบัติหลัก

- **Home Page** - หน้าหลักสำหรับเริ่มต้นใช้งาน
- **Document Editor** - เครื่องมือแก้ไขเอกสาร Word/PDF ด้วย CKEditor 5
- **Regulation List** - รายการกฎระเบียบทั้งหมด
- **Regulation View** - ดูรายละเอียดกฎระเบียบแบบมีมาตรา

## โครงสร้างโปรเจค

```
src/
├── components/
│   └── AppHeader.vue          # Header component พร้อม navigation
├── views/
│   ├── Home.vue              # หน้าหลัก
│   ├── Editor.vue            # หน้าแก้ไขเอกสาร
│   ├── RegulationList.vue    # รายการกฎระเบียบ
│   └── RegulationView.vue    # ดูรายละเอียดกฎระเบียบ
├── router/
│   └── index.js              # Vue Router configuration
├── assets/
│   └── main.css              # Thai font styles
├── App.vue                   # Main Vue component
└── main.js                   # App entry point
```

## การติดตั้ง

```bash
npm install
```

## การรันโปรเจค

```bash
npm run dev
```

เซิร์ฟเวอร์จะรันที่ http://localhost:3000

## Dependencies หลัก

- **Vue 3** - Frontend framework
- **Vuetify 3** - Material Design component framework
- **Vue Router** - Routing
- **CKEditor 5** - Rich text editor
- **Axios** - HTTP client
- **Sarabun Font** - Thai font support

## Features ที่คัดลอกมาจาก Laravel

1. **AppHeader Component** - Header พร้อม breadcrumbs, back button, และ staff info
2. **Home Page** - Landing page พร้อมปุ่ม Create Document และ View Regulations
3. **Editor Page** - Document editor พร้อม CKEditor 5 และ preview mode
4. **Regulation List** - รายการกฎระเบียบพร้อม search และ filter
5. **Regulation View** - ดูกฎระเบียบแบบมี sidebar สำหรับมาตรา

## การปรับแต่ง

- รองรับภาษาไทยเต็มรูปแบบ
- ใช้ Sarabun font สำหรับเอกสารไทย
- Responsive design สำหรับ mobile และ desktop
- Thai legal document formatting support
