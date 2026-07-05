<template>
  <v-footer color="elaw-navy" class="py-10">
    <v-container style="max-width: 1280px">
      <v-row>
        <v-col cols="12" md="4" class="mb-6 mb-md-0">
          <div class="d-flex align-center ga-2 mb-3">
            <v-icon icon="mdi-scale-balance" color="elaw-gold" size="24" />
            <span class="text-h6 font-weight-black text-white">e-Law</span>
          </div>
          <p class="text-caption" style="color: rgba(255, 255, 255, 0.65); line-height: 1.8">
            ระบบจัดการฐานข้อมูลกฎหมายอัจฉริยะ<br>
            แขวงพระนครมหาราชวัง เขตพระนคร กรุงเทพมหานคร 10200<br>
            โทรศัพท์: 0-2222-0206<br>
            อีเมล: admin@lawspace.co.th
          </p>
        </v-col>

        <v-col cols="6" md="2">
          <p class="text-caption font-weight-bold text-white mb-3">กฎหมายโปร่งใส</p>
          <div class="d-flex flex-column ga-2">
            <a
              v-for="link in linksLeft"
              :key="link"
              href="#"
              class="text-caption text-decoration-none"
              style="color: rgba(255, 255, 255, 0.65)"
            >
              {{ link }}
            </a>
          </div>
        </v-col>

        <v-col cols="6" md="2">
          <p class="text-caption font-weight-bold text-white mb-3">สอบถามข้อมูล</p>
          <div class="d-flex flex-column ga-2">
            <a
              v-for="link in linksRight"
              :key="link"
              href="#"
              class="text-caption text-decoration-none"
              style="color: rgba(255, 255, 255, 0.65)"
            >
              {{ link }}
            </a>
          </div>
        </v-col>

        <v-col cols="12" md="4" class="d-flex flex-column align-md-end">
          <div class="d-flex ga-2 mt-md-auto">
            <v-btn icon="mdi-facebook" color="white" variant="tonal" size="small" rounded="circle" />
            <v-btn icon="mdi-youtube" color="white" variant="tonal" size="small" rounded="circle" />
          </div>
          <p class="text-caption mt-4" style="color: rgba(255, 255, 255, 0.4)">
            © 2567 ระบบฐานข้อมูลกฎหมาย มหาวิทยาลัยบูรพา
          </p>
        </v-col>
      </v-row>
    </v-container>
  </v-footer>
</template>

<script setup lang="ts">
const linksLeft = ['นโยบายการคุ้มครองข้อมูลส่วนบุคคล', 'ร่วมงานกับเรา', 'ร้องเรียนการทุจริต', 'Sitemap'];
const linksRight = ['ช่องทางขอรับบริการ', 'ข้อเสนอแนะ'];
</script>
