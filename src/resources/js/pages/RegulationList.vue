<script setup>
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import axios from 'axios'

const router = useRouter()
const regulations = ref([])
const loading = ref(true)
const searchQuery = ref('')
const selectedType = ref(null)

const regulationTypes = [
    { value: 'regulation', title: 'ข้อบังคับ' },
    { value: 'announcement', title: 'ประกาศ' },
    { value: 'rule', title: 'ระเบียบ' },
    { value: 'guideline', title: 'แนวปฏิบัติ' },
    { value: 'order', title: 'คำสั่ง' },
]

onMounted(async () => {
    await loadRegulations()
})

const loadRegulations = async () => {
    loading.value = true
    try {
        const response = await axios.get('/regulations')
        regulations.value = response.data.data || []
    } catch (error) {
        console.error('Failed to load regulations:', error)
    } finally {
        loading.value = false
    }
}

const viewRegulation = (id) => {
    router.push(`/regulations/${id}`)
}

const getStatusColor = (status) => {
    const colors = {
        active: 'success',
        amended: 'warning',
        repealed: 'error',
    }
    return colors[status] || 'grey'
}

const getTypeColor = (type) => {
    const colors = {
        regulation: 'primary',
        announcement: 'secondary',
        rule: 'info',
        guideline: 'success',
        order: 'warning',
    }
    return colors[type] || 'grey'
}
</script>

<template>
    <v-app>
        <v-app-bar color="primary" dark>
            <v-btn icon @click="router.push('/')">
                <v-icon>mdi-home</v-icon>
            </v-btn>
            <v-toolbar-title>ระบบจัดการกฎระเบียบ</v-toolbar-title>
            <v-spacer></v-spacer>
            <v-btn color="white" variant="outlined" prepend-icon="mdi-plus" @click="router.push('/editor')">
                เพิ่มกฎระเบียบใหม่
            </v-btn>
        </v-app-bar>

        <v-main>
            <v-container class="py-8">
                <v-row class="mb-6">
                    <v-col cols="12" md="8">
                        <v-text-field
                            v-model="searchQuery"
                            prepend-inner-icon="mdi-magnify"
                            label="ค้นหากฎระเบียบ"
                            variant="outlined"
                            hide-details
                            clearable
                        ></v-text-field>
                    </v-col>
                    <v-col cols="12" md="4">
                        <v-select
                            v-model="selectedType"
                            :items="regulationTypes"
                            label="ประเภท"
                            variant="outlined"
                            hide-details
                            clearable
                        ></v-select>
                    </v-col>
                </v-row>

                <v-row v-if="loading">
                    <v-col cols="12" class="text-center py-12">
                        <v-progress-circular indeterminate color="primary" size="64"></v-progress-circular>
                        <p class="mt-4">กำลังโหลดข้อมูล...</p>
                    </v-col>
                </v-row>

                <v-row v-else-if="regulations.length === 0">
                    <v-col cols="12">
                        <v-alert type="info" prominent>
                            <v-row align="center">
                                <v-col class="grow">
                                    ยังไม่มีกฎระเบียบในระบบ
                                </v-col>
                                <v-col class="shrink">
                                    <v-btn color="white" variant="outlined" @click="router.push('/editor')">
                                        เพิ่มกฎระเบียบแรก
                                    </v-btn>
                                </v-col>
                            </v-row>
                        </v-alert>
                    </v-col>
                </v-row>

                <v-row v-else>
                    <v-col v-for="regulation in regulations" :key="regulation.id" cols="12">
                        <v-card hover @click="viewRegulation(regulation.id)" class="regulation-card">
                            <v-card-title class="d-flex align-center">
                                <v-icon :color="getTypeColor(regulation.regulation_type)" class="mr-3">
                                    mdi-file-document
                                </v-icon>
                                <span class="flex-grow-1">{{ regulation.title }}</span>
                                <v-chip :color="getStatusColor(regulation.status)" size="small" dark>
                                    {{ regulation.status }}
                                </v-chip>
                            </v-card-title>

                            <v-card-text>
                                <v-row dense>
                                    <v-col cols="auto">
                                        <v-chip :color="getTypeColor(regulation.regulation_type)" size="small" variant="outlined">
                                            {{ regulation.regulation_type }}
                                        </v-chip>
                                    </v-col>
                                    <v-col v-if="regulation.enacted_date" cols="auto">
                                        <v-chip size="small" variant="outlined" prepend-icon="mdi-calendar">
                                            {{ new Date(regulation.enacted_date).toLocaleDateString('th-TH') }}
                                        </v-chip>
                                    </v-col>
                                    <v-col v-if="regulation.sections_count" cols="auto">
                                        <v-chip size="small" variant="outlined" prepend-icon="mdi-file-document-multiple">
                                            {{ regulation.sections_count }} มาตรา
                                        </v-chip>
                                    </v-col>
                                </v-row>
                            </v-card-text>

                            <v-card-actions>
                                <v-btn variant="text" color="primary" prepend-icon="mdi-eye">
                                    ดูรายละเอียด
                                </v-btn>
                                <v-spacer></v-spacer>
                                <span class="text-caption text-grey">
                                    อัปเดต: {{ new Date(regulation.updated_at).toLocaleDateString('th-TH') }}
                                </span>
                            </v-card-actions>
                        </v-card>
                    </v-col>
                </v-row>
            </v-container>
        </v-main>
    </v-app>
</template>

<style scoped>
.regulation-card {
    transition: all 0.3s ease;
    cursor: pointer;
}

.regulation-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 8px 16px rgba(0, 0, 0, 0.2);
}
</style>
