<template>
  <div class="public-home">
    <ELawNavbar @go-admin="router.push('/admin')" />
    <ELawHeroSearch @search="onSearch" />

    <div class="public-home__body">
      <div class="public-home__inner">
        <section class="public-home__section">
          <div class="public-home__section-header">
            <h2 class="public-home__section-title">กฎหมายล่าสุด</h2>
            <a class="public-home__section-link" href="#">ดูทั้งหมด →</a>
          </div>
          <div class="public-home__cards">
            <ELawLawCard
              v-for="doc in sampleDocs"
              :key="doc.id"
              :title="doc.title"
              :doc-type="doc.docType"
              :doc-number="doc.number"
              :department="doc.department"
              :date="doc.date"
              :status="doc.status"
            />
          </div>
        </section>

        <div class="public-home__two-col">
          <section class="public-home__section">
            <div class="public-home__section-header">
              <h2 class="public-home__section-title">ระเบียบ</h2>
              <a class="public-home__section-link" href="#">ดูทั้งหมด →</a>
            </div>
            <div class="public-home__list">
              <a v-for="item in sampleRabiap" :key="item.id" class="public-home__list-item" href="#">
                <span class="elaw-badge elaw-badge--rabiap public-home__list-badge">ระเบียบ</span>
                <span class="public-home__list-title">{{ item.title }}</span>
              </a>
            </div>
          </section>

          <section class="public-home__section">
            <div class="public-home__section-header">
              <h2 class="public-home__section-title">ประกาศ</h2>
              <a class="public-home__section-link" href="#">ดูทั้งหมด →</a>
            </div>
            <div class="public-home__list">
              <a v-for="item in samplePrakat" :key="item.id" class="public-home__list-item" href="#">
                <span class="elaw-badge elaw-badge--prakat public-home__list-badge">ประกาศ</span>
                <span class="public-home__list-title">{{ item.title }}</span>
              </a>
            </div>
          </section>
        </div>
      </div>
    </div>

    <footer class="public-home__footer">
      <div class="public-home__inner">
        <p>© 2567 ระบบฐานข้อมูลกฎหมาย — มหาวิทยาลัยบูรพา</p>
      </div>
    </footer>
  </div>
</template>

<script setup lang="ts">
import { useRouter } from 'vue-router';
import ELawHeroSearch from '../../components/shared/ELawHeroSearch.vue';
import ELawLawCard from '../../components/shared/ELawLawCard.vue';
import ELawNavbar from '../../components/shared/ELawNavbar.vue';

const router = useRouter();

function onSearch(query: string): void {
  console.info('Search:', query);
}

const sampleDocs = [
  { id: '1', title: 'ระเบียบมหาวิทยาลัยบูรพา ว่าด้วยการบริหารงานบุคคล พ.ศ. 2566', docType: 'rabiap' as const, number: 'ร.มบ. 2566/01', department: 'สำนักงานอธิการบดี', date: '1 ม.ค. 2566', status: 'มีผลบังคับใช้' },
  { id: '2', title: 'ประกาศมหาวิทยาลัยบูรพา เรื่อง หลักเกณฑ์การจัดซื้อจัดจ้าง', docType: 'prakat' as const, number: 'ป.มบ. 2566/05', department: 'กองคลัง', date: '15 ก.พ. 2566', status: 'มีผลบังคับใช้' },
  { id: '3', title: 'ข้อบังคับมหาวิทยาลัยบูรพา ว่าด้วยการศึกษาระดับบัณฑิตศึกษา', docType: 'kho-bangkhab' as const, number: 'ข.มบ. 2565/12', department: 'บัณฑิตวิทยาลัย', date: '1 มิ.ย. 2565', status: 'มีผลบังคับใช้' },
  { id: '4', title: 'ระเบียบมหาวิทยาลัยบูรพา ว่าด้วยการใช้ยานพาหนะ พ.ศ. 2565', docType: 'rabiap' as const, number: 'ร.มบ. 2565/08', department: 'กองกลาง', date: '10 เม.ย. 2565', status: 'มีผลบังคับใช้' },
];

const sampleRabiap = [
  { id: 'r1', title: 'ระเบียบว่าด้วยการลา พ.ศ. 2566' },
  { id: 'r2', title: 'ระเบียบการเบิกค่าใช้จ่ายในการเดินทาง' },
  { id: 'r3', title: 'ระเบียบว่าด้วยเงินกองทุนสวัสดิการ' },
  { id: 'r4', title: 'ระเบียบการจัดซื้อจัดจ้างพัสดุ' },
];

const samplePrakat = [
  { id: 'p1', title: 'ประกาศ เรื่อง หลักเกณฑ์การให้ทุนการศึกษา' },
  { id: 'p2', title: 'ประกาศ เรื่อง การรับสมัครบุคลากร' },
  { id: 'p3', title: 'ประกาศ อัตราค่าธรรมเนียมการศึกษา' },
  { id: 'p4', title: 'ประกาศ เรื่อง ปฏิทินวิชาการ ปีการศึกษา 2567' },
];
</script>

<style scoped>
.public-home {
  min-height: 100vh;
  background: var(--elaw-bg);
  display: flex;
  flex-direction: column;
}

.public-home__body {
  flex: 1;
  padding: 40px 24px;
}

.public-home__inner {
  max-width: 1280px;
  margin: 0 auto;
  display: flex;
  flex-direction: column;
  gap: 40px;
}

.public-home__section {
  display: flex;
  flex-direction: column;
  gap: 16px;
}

.public-home__section-header {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 16px;
}

.public-home__section-title {
  font-size: 20px;
  font-weight: 700;
  color: var(--elaw-navy);
  margin: 0;
}

.public-home__section-link {
  font-size: 13px;
  color: var(--law-primary);
  text-decoration: none;
}

.public-home__section-link:hover {
  text-decoration: underline;
}

.public-home__cards {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
  gap: 16px;
}

.public-home__two-col {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 32px;
}

.public-home__list {
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.public-home__list-item {
  display: flex;
  align-items: flex-start;
  gap: 10px;
  padding: 10px 12px;
  border-radius: 8px;
  text-decoration: none;
  color: var(--elaw-text);
  font-size: 14px;
  transition: background 0.12s;
}

.public-home__list-item:hover {
  background: var(--elaw-cream);
}

.public-home__list-badge {
  flex-shrink: 0;
}

.public-home__list-title {
  line-height: 1.45;
}

.public-home__footer {
  background: var(--elaw-navy);
  color: rgba(255, 255, 255, 0.6);
  padding: 20px 24px;
  font-size: 13px;
  text-align: center;
}

@media (max-width: 720px) {
  .public-home__two-col,
  .public-home__cards {
    grid-template-columns: 1fr;
  }
}
</style>
