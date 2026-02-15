<script setup>
import { ref, onMounted, onBeforeUnmount, nextTick, watch, markRaw } from 'vue'
import axios from 'axios'
// import ClassicEditor from '@ckeditor/ckeditor5-build-classic'
import {
    ClassicEditor,
    Essentials,
    Paragraph,
    Heading,
    Bold,
    Italic,
    Underline,
    List,
    Link,
    Table,
    TableToolbar,
    BlockQuote,
    Indent,
    IndentBlock,
    PasteFromOffice,
    GeneralHtmlSupport,
    Alignment,
    Autoformat
} from 'ckeditor5';
import 'ckeditor5/ckeditor5.css';
import { useRouter } from 'vue-router'

const router = useRouter()

const file = ref(null)
const content = ref('')
const title = ref('Untitled Document')
const loading = ref(false)
const saving = ref(false)
const showEditor = ref(false)
const editor = ref(null)
const editorElement = ref(null)
const activeTab = ref('preview') // 'preview' or 'edit'

const handleUpload = async () => {
    console.log('0: handleUpload called')

    if (!file.value) {
        console.log('0.1: no file, return')
        return
    }

    console.log('0.2: file ok', file.value)
    console.log('0.3: file details:', {
        name: file.value.name,
        type: file.value.type,
        size: file.value.size,
        lastModified: file.value.lastModified
    })

    loading.value = true
    const formData = new FormData()
    formData.append('file', file.value)

    try {
        console.log('A: before axios')
        const response = await axios.post('/convert', formData, {
            headers: { 'Content-Type': 'multipart/form-data' },
        })
        console.log('B: axios ok', response.status)

        content.value = response.data.content
        title.value = 'Converted Document'

        // Show content immediately in preview
        showEditor.value = true
        activeTab.value = 'preview'

        // Initialize editor but keep hidden until switched
        await nextTick()
        // We'll init editor when switching tab or just ready it
        // await initializeCKEditor()

    } catch (err) {
        console.log('CATCH:', err)
        console.log('CATCH details:', {
            response: err.response,
            status: err.response?.status,
            data: err.response?.data,
            message: err.message
        })

        let errorMessage = 'Failed to process document'
        if (err.response?.data?.message) {
            errorMessage = err.response.data.message
        } else if (err.response?.data?.errors?.file?.[0]) {
            errorMessage = err.response.data.errors.file[0]
        }

        alert(errorMessage)
    } finally {
        loading.value = false
    }
}

const initializeCKEditor = async () => {
    // Destroy existing editor instance to prevent memory leaks
    if (editor.value) {
        await editor.value.destroy()
        editor.value = null
    }

    if (editorElement.value) {
        try {
            const newEditor = await ClassicEditor.create(editorElement.value, {
                licenseKey: 'GPL', // Or 'your-license-key'
                plugins: [
                    Essentials, Paragraph, Heading, Bold, Italic, Underline, List, Link, Table, TableToolbar,
                    BlockQuote, Indent, IndentBlock, PasteFromOffice, GeneralHtmlSupport, Alignment, Autoformat
                ],
                toolbar: [
                    'heading', '|',
                    'bold', 'italic', 'underline', '|',
                    'bulletedList', 'numberedList', '|',
                    'outdent', 'indent', '|',
                    'alignment', '|',
                    'link', '|',
                    'insertTable', 'blockQuote', '|',
                    'undo', 'redo'
                ],
                alignment: {
                    options: ['left', 'center', 'right', 'justify']
                },
                htmlSupport: {
                    allow: [
                        {
                            name: /.*/,
                            attributes: true,
                            classes: true,
                            styles: true
                        }
                    ]
                },
                heading: {
                    options: [
                        { model: 'paragraph', title: 'Paragraph', class: 'ck-heading_paragraph' },
                        { model: 'heading1', view: 'h1', title: 'Heading 1', class: 'ck-heading_heading1' },
                        { model: 'heading2', view: 'h2', title: 'Heading 2', class: 'ck-heading_heading2' },
                        { model: 'heading3', view: 'h3', title: 'Heading 3', class: 'ck-heading_heading3' }
                    ]
                },
                table: {
                    contentToolbar: [
                        'tableColumn',
                        'tableRow',
                        'mergeTableCells'
                    ]
                },
                pasteFromOffice: {
                    keepFormat: true
                }
            })

            editor.value = markRaw(newEditor)
            editor.value.setData(content.value)

        } catch (error) {
            console.error('Failed to initialize CKEditor:', error)
            alert('Failed to initialize editor')
        }
    }
}

const handleSave = async () => {
    // If saving from preview mode, content.value is already current (unless we need to sync back from editor?)
    // If editor exists, get data from it
    if (editor.value) {
        content.value = editor.value.getData()
    }

    saving.value = true
    try {
        const htmlContent = content.value

        const response = await axios.post('/store', {
            title: title.value,
            content: htmlContent
        })

        console.log('Document saved:', response.data)
        alert('Document saved successfully!')

    } catch (error) {
        console.error('Save failed:', error)
        alert('Failed to save document')
    } finally {
        saving.value = false
    }
}

const destroyEditor = () => {
    if (editor.value) {
        editor.value.destroy()
        editor.value = null
    }
}

onBeforeUnmount(() => {
    destroyEditor()
})

watch(activeTab, async (newTab) => {
    if (newTab === 'edit') {
        if (!editor.value) {
            await nextTick()
            await initializeCKEditor()
        } else {
            // Sync content to editor if it changed externally (not implemented yet, assuming one-way for now)
            editor.value.setData(content.value)
        }
    } else if (newTab === 'preview') {
        // Sync back from editor to preview
        if (editor.value) {
            content.value = editor.value.getData()
        }
    }
})
</script>

<template>
    <v-app>
        <v-app-bar title="New Editor - High Fidelity">
            <template v-slot:prepend>
                <v-btn icon @click="router.push('/')">
                    <v-icon>mdi-arrow-left</v-icon>
                </v-btn>
            </template>
            <template v-slot:append>
                <v-btn color="primary" @click="handleSave" :loading="saving" :disabled="!showEditor">
                    <v-icon left>mdi-content-save</v-icon>
                    Save
                </v-btn>
            </template>
        </v-app-bar>

        <v-main>
            <v-container>
                <v-card v-if="!showEditor">
                    <v-card-title>
                        <h2>Convert Word/PDF to HTML</h2>
                    </v-card-title>

                    <v-card-text>
                        <v-file-input v-model="file" label="Select Word or PDF file" accept=".docx,.pdf"
                            prepend-icon="mdi-file-document" show-size outlined dense></v-file-input>

                        <v-btn color="primary" @click="handleUpload" :loading="loading" :disabled="!file" block
                            size="large">
                            <v-icon left>mdi-upload</v-icon>
                            Convert & Edit
                        </v-btn>
                    </v-card-text>
                </v-card>

                <v-card v-if="showEditor" class="mt-4">
                    <v-card-title class="d-flex justify-space-between align-center">
                        <v-text-field v-model="title" label="Document Title" variant="outlined" density="compact"
                            hide-details class="mr-4" style="max-width: 400px;"></v-text-field>

                        <v-btn-toggle v-model="activeTab" mandatory color="primary" variant="outlined"
                            density="compact">
                            <v-btn value="preview" prepend-icon="mdi-eye">Preview (HTML)</v-btn>
                            <v-btn value="edit" prepend-icon="mdi-pencil">Edit (CKEditor)</v-btn>
                        </v-btn-toggle>
                    </v-card-title>

                    <v-card-text class="pa-0">
                        <!-- Preview Mode -->
                        <div v-show="activeTab === 'preview'" class="preview-container pa-4">
                            <div class="legal-document paper-sheet" v-html="content"></div>
                        </div>

                        <!-- Editor Mode -->
                        <div v-show="activeTab === 'edit'" class="editor-container">
                            <div ref="editorElement"></div>
                        </div>
                    </v-card-text>
                </v-card>
            </v-container>
        </v-main>
    </v-app>
</template>

<style>
.ck-content {
    min-height: 500px;
    /* Taller editor */
    max-height: 800px;
    overflow-y: auto;
    padding: 2cm !important;
    /* Simulate page padding */
    background: white;
    box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
}

.ck.ck-editor__main>.ck-editor__editable {
    background: #f9f9f9;
    /* Outer bg */
}

/* Override editor container to look like a document page */
.editor-container {
    background: #e0e0e0;
    padding: 20px;
    display: flex;
    justify-content: center;
}

.ck-content {
    width: 21cm;
    /* A4 width */
    min-height: 29.7cm;
    /* A4 height */
    margin: auto;
    background: white;
}

.ck-content table {
    width: 100% !important;
    border-collapse: collapse;
    margin: 1em 0;
}

.ck-content td,
.ck-content th {
    border: 1px solid #ccc;
    padding: 8px;
    min-width: 50px;
}

.ck-content th {
    background-color: #f5f5f5;
    font-weight: bold;
}

.legal-document {
    font-family: 'Sarabun', sans-serif;
    font-size: 16pt;
    line-height: 1.5;
}

.ck-content .doc-tab {
    display: inline-block;
    min-width: 2em;
}

/* Border & Shading support from Backend */
.ck-content td[style*="background-color"] {
    -webkit-print-color-adjust: exact;
    print-color-adjust: exact;
}

/* Preview Styles matching hello.html */
.preview-container {
    background: #e0e0e0;
    min-height: 600px;
    display: flex;
    justify-content: center;
    overflow-y: auto;
    max-height: 800px;
}

.paper-sheet {
    width: 21cm;
    min-height: 29.7cm;
    background: white;
    box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
    margin: 20px auto;
    color: #000;
    /* Ensure text is black */
}

/* Ensure styles from hello.html work here */
.legal-document {
    font-family: "Sarabun", "Sarabun New", "TH Sarabun New", sans-serif;
    font-size: 16pt;
    line-height: 1.75;
    padding: 1in;
    word-break: break-word;
    overflow-wrap: anywhere;
}

.legal-document p {
    margin: 0 0 0.35em 0;
    text-align: justify;
}

.legal-document .doc-center-heading {
    width: 100%;
    text-align: center;
}

.legal-document .doc-right-heading {
    width: 100%;
    text-align: right;
}

.legal-document .doc-tab {
    display: inline-block;
    width: 2.2em;
}

.legal-document .doc-table {
    width: 100%;
    border-collapse: collapse;
    table-layout: fixed;
    margin: 0.75em 0;
}

.legal-document .doc-td {
    border: 1px solid #000;
    padding: 10px 12px;
    vertical-align: top;
}

.legal-document .cell-p {
    margin: 0;
    line-height: 1.6;
}

.legal-document strong {
    font-weight: 700;
}

.legal-document em {
    font-style: italic;
}
</style>
