<template>
  <div class="d-flex flex-column" style="min-height:100vh">
    <ELawNavbar @go-admin="router.push('/admin')" />
    <v-main>
      <ELawHeroSearch @search="onSearch" />

      <v-container class="d-flex flex-column ga-10">
        <section>
          <div class="d-flex justify-space-between align-baseline mb-4">
            <h2 class="text-h6 font-weight-bold">กฎหมายล่าสุด</h2>
            <a href="#">ดูทั้งหมด →</a>
          </div>
          <v-row>
            <v-col
              v-for="doc in sampleDocs"
              :key="doc.id"
              cols="12"
              sm="6"
              md="3"
            >
              <ELawLawCard
                :title="doc.title"
                :doc-type="doc.docType"
                :doc-number="doc.number"
                :department="doc.department"
                :date="doc.date"
                :status="doc.status"
              />
            </v-col>
          </v-row>
        </section>

        <v-row>
          <v-col cols="12" md="6">
            <section>
              <div class="d-flex justify-space-between align-baseline mb-4">
                <h2 class="text-h6 font-weight-bold">ระเบียบ</h2>
                <a href="#">ดูทั้งหมด →</a>
              </div>
              <v-list>
                <v-list-item
                  v-for="item in sampleRabiap"
                  :key="item.id"
                  href="#"
                  :title="item.title"
                >
                  <template #prepend>
                    <v-chip size="x-small" color="success">ระเบียบ</v-chip>
                  </template>
                </v-list-item>
              </v-list>
            </section>
          </v-col>

          <v-col cols="12" md="6">
            <section>
              <div class="d-flex justify-space-between align-baseline mb-4">
                <h2 class="text-h6 font-weight-bold">ประกาศ</h2>
                <a href="#">ดูทั้งหมด →</a>
              </div>
              <v-list>
                <v-list-item
                  v-for="item in samplePrakat"
                  :key="item.id"
                  href="#"
                  :title="item.title"
                >
                  <template #prepend>
                    <v-chip size="x-small" color="warning">ประกาศ</v-chip>
                  </template>
                </v-list-item>
              </v-list>
            </section>
          </v-col>
        </v-row>
      </v-container>

      <v-footer color="elaw-navy" class="justify-center">
        <p>© 2567 ระบบฐานข้อมูลกฎหมาย — มหาวิทยาลัยบูรพา</p>
      </v-footer>
    </v-main>
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
