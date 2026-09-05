<template>
  <footer class="elaw-footer-figma">
    <div class="elaw-footer-figma__inner">
      <div class="elaw-footer-figma__brand">
        <div class="elaw-footer-figma__logo">
          <v-icon icon="mdi-scale-balance" color="#b68d40" size="22" />
          <span class="elaw-footer-figma__logo-text">e-Law</span>
        </div>
        <p class="elaw-footer-figma__address">
          ระบบจัดการฐานข้อมูลกฎหมายอัจฉริยะ<br>
          แขวงพระบรมมหาราชวัง เขตพระนคร กรุงเทพมหานคร 10200<br>
          โทรศัพท์: 0-2222-0206<br>
          อีเมล: admin@lawspace.co.th
        </p>
      </div>
      <div class="elaw-footer-figma__links">
        <a v-for="link in linksMid" :key="link" href="#">&#9656; {{ link }}</a>
      </div>
      <div class="elaw-footer-figma__links">
        <a v-for="link in linksRight" :key="link" href="#">&#9656; {{ link }}</a>
        <div class="elaw-footer-figma__social">
          <button type="button" class="elaw-social-btn"><v-icon icon="mdi-facebook" size="16" /></button>
          <button type="button" class="elaw-social-btn"><v-icon icon="mdi-youtube" size="16" /></button>
        </div>
      </div>
    </div>
  </footer>
</template>

<script setup lang="ts">
const linksMid = ['กฎหมายโปร่งใส', 'นโยบายการคุ้มครองข้อมูลส่วนบุคคล', 'ร่วมงานกับเรา', 'ร้องเรียนการทุจริต'];
const linksRight = ['สอบถามข้อมูล/ข้อเสนอแนะ', 'ช่องทางขอรับบริการ', 'Sitemap'];
</script>

<style scoped>
.elaw-footer-figma {
  background: #faf8f4;
  border-top: 1px solid #e7e2d9;
  padding: 48px 24px 36px;
}

.elaw-footer-figma__inner {
  max-width: 1215px;
  margin: 0 auto;
  display: grid;
  grid-template-columns: 1.2fr 1fr 1fr;
  gap: 40px;
}

.elaw-footer-figma__logo {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 14px;
}

.elaw-footer-figma__logo-text {
  font-family: 'Sarabun', 'Noto Sans Thai', sans-serif;
  font-size: 20px;
  font-weight: 700;
  color: #7b580d;
}

.elaw-footer-figma__address {
  font-family: 'Sarabun', 'Noto Sans Thai', sans-serif;
  font-size: 13px;
  color: #6b7280;
  line-height: 1.8;
  margin: 0;
}

.elaw-footer-figma__links {
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.elaw-footer-figma__links a {
  font-family: 'Sarabun', 'Noto Sans Thai', sans-serif;
  font-size: 14px;
  color: #4e4538;
  text-decoration: none;
  font-weight: 600;
}

.elaw-footer-figma__links a:hover {
  color: #b68d40;
}

.elaw-footer-figma__social {
  display: flex;
  gap: 10px;
  margin-top: 12px;
}

.elaw-social-btn {
  width: 36px;
  height: 36px;
  border-radius: 50%;
  background: #b68d40;
  color: #ffffff;
  border: none;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
}

@media (max-width: 768px) {
  .elaw-footer-figma__inner {
    grid-template-columns: 1fr;
    gap: 28px;
  }
}
</style>
