<script setup>
import { ref, onMounted, computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import axios from 'axios'
import AppHeader from '../components/AppHeader.vue'
import { formatThaiDate } from '../utils/thaiDate'

const route = useRoute()
const router = useRouter()
const regulation = ref(null)
const sections = ref([])
const loading = ref(true)
const searchQuery = ref('')
const selectedSection = ref(null)
const useMockData = import.meta.env.VITE_USE_MOCK === 'true'

const mockRegulation = {
    id: 1,
    title: 'ระเบียบการปฏิบัติงานฝ่ายวิชาการ พ.ศ. 2569',
    regulation_type: 'rule',
    status: 'active',
    enacted_date: '2026-01-15',
}

const mockSections = [
    {
        id: 101,
        parent_id: null,
        section_type: 'chapter',
        section_number: 'หมวด 1',
        section_label: 'บททั่วไป',
        content_text: 'กำหนดหลักเกณฑ์ทั่วไปในการดำเนินการ',
        content_html: '<p><strong>หมวด 1 บททั่วไป</strong></p><p>กำหนดหลักเกณฑ์ทั่วไปในการดำเนินการ</p>',
    },
    {
        id: 102,
        parent_id: null,
        section_type: 'section',
        section_number: 'ข้อ 1',
        section_label: 'วัตถุประสงค์',
        content_text: 'เพื่อกำหนดแนวทางปฏิบัติให้เป็นมาตรฐานเดียวกัน',
        content_html: '<p><strong>ข้อ 1 วัตถุประสงค์</strong></p><p>เพื่อกำหนดแนวทางปฏิบัติให้เป็นมาตรฐานเดียวกัน</p>',
    },
    {
        id: 103,
        parent_id: null,
        section_type: 'section',
        section_number: 'ข้อ 2',
        section_label: 'ขอบเขต',
        content_text: 'ระเบียบนี้ใช้บังคับกับหน่วยงานที่เกี่ยวข้องทั้งหมด',
        content_html: '<p><strong>ข้อ 2 ขอบเขต</strong></p><p>ระเบียบนี้ใช้บังคับกับหน่วยงานที่เกี่ยวข้องทั้งหมด</p>',
    },
]

onMounted(async () => {
    await loadRegulation()
})

const loadRegulation = async () => {
    loading.value = true
    if (useMockData) {
        regulation.value = mockRegulation
        sections.value = mockSections
        selectedSection.value = mockSections[0] ?? null
        loading.value = false
        return
    }

    try {
        const id = route.params.id
        const response = await axios.get(`/api/regulations/${id}`)
        regulation.value = response.data
        sections.value = response.data.sections || []
        selectedSection.value = sections.value[0] ?? null
    } catch (error) {
        console.error('Failed to load regulation:', error)
        // Frontend-only fallback: keep page usable when API is unavailable.
        regulation.value = mockRegulation
        sections.value = mockSections
        selectedSection.value = mockSections[0] ?? null
    } finally {
        loading.value = false
    }
}

const getSectionIcon = (type) => {
    const icons = {
        chapter: 'mdi-book-open-variant',
        part: 'mdi-file-document-outline',
        section: 'mdi-file-document',
        clause: 'mdi-format-list-numbered',
        sub_clause: 'mdi-subdirectory-arrow-right',
        schedule: 'mdi-calendar-text',
    }
    return icons[type] || 'mdi-file-document'
}

const getSectionColor = (type) => {
    const colors = {
        chapter: 'primary',
        part: 'secondary',
        section: 'info',
        clause: 'success',
        sub_clause: 'warning',
        schedule: 'error',
    }
    return colors[type] || 'grey'
}

const selectSection = (section) => {
    selectedSection.value = section
}

const filteredSections = computed(() => {
    if (!searchQuery.value) return sections.value
    
    const query = searchQuery.value.toLowerCase()
    return sections.value.filter(section => 
        section.section_number.toLowerCase().includes(query) ||
        section.section_label?.toLowerCase().includes(query) ||
        section.content_text.toLowerCase().includes(query)
    )
})
</script>

<template>
    <v-app>
        <AppHeader
            title="คลังกฎหมาย"
            :breadcrumbs="[
                { text: 'คลังกฎหมาย', to: '/regulations' },
                'ค้นหาข้อมูลกฎหมาย'
            ]"
            show-back
            back-to="/regulations"
            right-subtitle="เจ้าหน้าที่ : แขก ทดสอบ นางสาวทดสอบ"
        >
            <template #actions>
                <v-chip
                    v-if="regulation"
                    :color="regulation.status === 'active' ? 'success' : 'warning'"
                    variant="flat"
                >
                    {{ regulation.status }}
                </v-chip>
            </template>
        </AppHeader>

        <v-main>
            <v-container fluid class="pa-0">
                <v-row no-gutters style="height: calc(100vh - 92px);">
                    <!-- Sidebar: Section List -->
                    <v-col cols="12" md="4" lg="3" class="section-sidebar">
                        <v-card flat tile height="100%" class="d-flex flex-column">
                            <v-card-title class="py-3">
                                <v-text-field
                                    v-model="searchQuery"
                                    prepend-inner-icon="mdi-magnify"
                                    label="ค้นหาข้อ"
                                    hide-details
                                    dense
                                    outlined
                                    clearable
                                ></v-text-field>
                            </v-card-title>

                            <v-divider></v-divider>

                            <v-card-text class="flex-grow-1 overflow-y-auto pa-0">
                                <v-list dense>
                                    <v-list-item
                                        v-for="section in filteredSections"
                                        :key="section.id"
                                        @click="selectSection(section)"
                                        :class="{ 'v-list-item--active': selectedSection?.id === section.id }"
                                        :style="{ paddingLeft: (section.parent_id ? 32 : 16) + 'px' }"
                                    >
                                        <template v-slot:prepend>
                                            <v-icon :color="getSectionColor(section.section_type)" size="small">
                                                {{ getSectionIcon(section.section_type) }}
                                            </v-icon>
                                        </template>

                                        <v-list-item-title>
                                            <strong>{{ section.section_number }}</strong>
                                            <span v-if="section.section_label" class="ml-2 text-caption">
                                                {{ section.section_label }}
                                            </span>
                                        </v-list-item-title>
                                    </v-list-item>
                                </v-list>

                                <v-alert v-if="filteredSections.length === 0" type="info" class="ma-4">
                                    ไม่พบข้อที่ค้นหา
                                </v-alert>
                            </v-card-text>
                        </v-card>
                    </v-col>

                    <!-- Main Content: Section Detail -->
                    <v-col cols="12" md="8" lg="9" class="section-content">
                        <v-card flat tile height="100%" class="overflow-y-auto">
                            <v-card-text v-if="loading" class="text-center py-12">
                                <v-progress-circular indeterminate color="primary" size="64"></v-progress-circular>
                                <p class="mt-4">กำลังโหลดข้อมูล...</p>
                            </v-card-text>

                            <v-card-text v-else-if="!selectedSection && sections.length > 0" class="pa-8">
                                <div class="text-center py-12">
                                    <v-icon size="80" color="grey-lighten-1">mdi-file-document-outline</v-icon>
                                    <h2 class="mt-4 text-h5">{{ regulation.title }}</h2>
                                    <p class="mt-2 text-body-1">
                                        <v-chip size="small" class="mr-2">{{ regulation.regulation_type }}</v-chip>
                                        <span v-if="regulation.enacted_date">
                                            ประกาศใช้: {{ formatThaiDate(regulation.enacted_date) }}
                                        </span>
                                    </p>
                                    <p class="mt-6 text-grey">เลือกข้อจากรายการด้านซ้ายเพื่อดูรายละเอียด</p>
                                </div>
                            </v-card-text>

                            <v-card-text v-else-if="selectedSection" class="pa-8">
                                <div class="section-header mb-6">
                                    <v-chip :color="getSectionColor(selectedSection.section_type)" dark class="mb-2">
                                        {{ selectedSection.section_type }}
                                    </v-chip>
                                    <h1 class="text-h4 mb-2">
                                        {{ selectedSection.section_number }}
                                        <span v-if="selectedSection.section_label">{{ selectedSection.section_label }}</span>
                                    </h1>
                                </div>

                                <v-divider class="mb-6"></v-divider>

                                <div class="section-body legal-document" v-html="selectedSection.content_html"></div>
                            </v-card-text>

                            <v-card-text v-else class="text-center py-12">
                                <v-icon size="80" color="grey-lighten-1">mdi-file-alert-outline</v-icon>
                                <p class="mt-4">ไม่พบข้อมูลข้อ</p>
                            </v-card-text>
                        </v-card>
                    </v-col>
                </v-row>
            </v-container>
        </v-main>
    </v-app>
</template>

<style scoped>
.section-sidebar {
    border-right: 1px solid #e0e0e0;
    background: #fafafa;
}

.section-content {
    background: white;
}

.v-list-item--active {
    background-color: rgba(25, 118, 210, 0.12);
    border-left: 4px solid #1976d2;
}

.section-header {
    border-bottom: 2px solid #e0e0e0;
    padding-bottom: 16px;
}

.legal-document {
    font-family: 'Sarabun', 'Sarabun New', sans-serif;
    font-size: 16pt;
    line-height: 1.75;
    max-width: 100%;
    overflow-wrap: break-word;
}

.legal-document p {
    margin-bottom: 0.75em;
    text-align: justify;
}

.legal-document table {
    width: 100%;
    border-collapse: collapse;
    margin: 1em 0;
}

.legal-document td,
.legal-document th {
    border: 1px solid #000;
    padding: 8px;
}

.legal-document strong {
    font-weight: 700;
}
</style>
