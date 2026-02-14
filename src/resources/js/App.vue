<script setup>
import { ref, onBeforeUnmount } from 'vue';
import { useEditor, EditorContent } from '@tiptap/vue-3';
import StarterKit from '@tiptap/starter-kit';
import TextAlign from '@tiptap/extension-text-align';
import Underline from '@tiptap/extension-underline';
import { TextStyle } from '@tiptap/extension-text-style';
import { Color } from '@tiptap/extension-color';
import { FontFamily } from '@tiptap/extension-font-family';
import TaskList from '@tiptap/extension-task-list';
import TaskItem from '@tiptap/extension-task-item';
import Table from '@tiptap/extension-table';
import TableRow from '@tiptap/extension-table-row';
import TableCell from '@tiptap/extension-table-cell';
import TableHeader from '@tiptap/extension-table-header';

// Custom Indent logic could be an extension, but for simplicity we can use standard styles or custom commands
// But a community "Indent" usually just adds margin-left. 
// Tiptap doesn't have official "Indent" in core, but typical approach is to use `margin-left`.
// Let's implement a simple Indent approach using Paragraph attributes or just relying on TextStyle.
// Actually, `tiptap-extension-indent` is a common request, but avoiding another install if possible.
// We can use `TextAlign` for indent if we just want alignment, but for Tab-like behavior, margin is best.

const file = ref(null);
const content = ref('');
const title = ref('Untitled Document');
const loading = ref(false);
const saving = ref(false);
const showEditor = ref(false);

const editor = useEditor({
    content: '',
    extensions: [
        StarterKit,
        Underline,
        TextStyle,
        Color,
        FontFamily,
        TaskList,
        TaskItem.configure({
            nested: true,
        }),
        TextAlign.configure({
            types: ['heading', 'paragraph'],
            alignments: ['left', 'center', 'right', 'justify'],
        }),
        Table.configure({
            resizable: true,
        }),
        TableRow,
        TableHeader,
        TableCell,
    ],
    onUpdate: ({ editor }) => {
        content.value = editor.getHTML();
    },
    editorProps: {
        attributes: {
            class: 'prose prose-sm sm:prose lg:prose-lg xl:prose-2xl mx-auto focus:outline-none page-view',
        },
    },
});

const handleUpload = async () => {
    if (!file.value) return;

    loading.value = true;
    const formData = new FormData();
    const fileToUpload = Array.isArray(file.value) ? file.value[0] : file.value;
    formData.append('file', fileToUpload);

    try {
        const response = await axios.post('/convert', formData, {
            headers: {
                'Content-Type': 'multipart/form-data'
            }
        });

        content.value = response.data.content;
        title.value = fileToUpload.name.replace(/\.[^/.]+$/, "");
        editor.value.commands.setContent(content.value);
        showEditor.value = true;
    } catch (error) {
        console.error("Upload failed", error);
        alert("Failed to process document");
    } finally {
        loading.value = false;
    }
};

const handleSave = async () => {
    saving.value = true;
    try {
        await axios.post('/store', {
            title: title.value,
            content: content.value
        });
        alert("Document saved successfully!");
    } catch (error) {
        console.error("Save failed", error);
        alert("Failed to save document");
    } finally {
        saving.value = false;
    }
};

onBeforeUnmount(() => {
    editor.value?.destroy();
});
</script>

<template>
    <v-app>
        <v-app-bar color="white" density="compact" elevation="1">
            <v-app-bar-title class="text-primary font-weight-bold">
                <v-icon icon="mdi-file-document-edit-outline" class="mr-2"></v-icon>
                Legal Editor
            </v-app-bar-title>
            <v-spacer></v-spacer>
            <div class="mr-4" v-if="showEditor">
                <v-text-field v-model="title" density="compact" variant="outlined" hide-details class="title-input"
                    style="width: 300px"></v-text-field>
            </div>
            <v-btn v-if="showEditor" :loading="saving" @click="handleSave" color="primary" variant="flat">
                <v-icon icon="mdi-content-save" class="mr-1"></v-icon> Save
            </v-btn>
        </v-app-bar>

        <v-main class="bg-grey-lighten-4">
            <v-container class="fill-height justify-center" v-if="!showEditor">
                <v-card width="600" class="pa-8 text-center rounded-xl elevation-3">
                    <div class="mb-6">
                        <v-icon icon="mdi-cloud-upload" size="64" color="primary" class="mb-4"></v-icon>
                        <h2 class="text-h5 font-weight-bold mb-2">Upload Legal Document</h2>
                        <p class="text-grey-darken-1">Supports .docx and .pdf files</p>
                    </div>

                    <v-file-input v-model="file" label="Choose file..." accept=".docx,.pdf" prepend-icon=""
                        prepend-inner-icon="mdi-paperclip" variant="outlined" :disabled="loading"
                        class="mb-4"></v-file-input>

                    <v-btn color="primary" size="large" block @click="handleUpload" :loading="loading" :disabled="!file"
                        class="text-none font-weight-bold">
                        Start Editing
                    </v-btn>
                </v-card>
            </v-container>

            <div v-else class="d-flex flex-column fill-height">
                <!-- Toolbar -->
                <v-toolbar density="compact" color="white" class="border-b px-2 toolbar-sticky">
                    <v-btn-group variant="text" density="compact" class="mr-2 border-r pr-2">
                        <v-btn icon size="small" @click="editor.chain().focus().undo().run()"
                            :disabled="!editor.can().undo()">
                            <v-icon>mdi-undo</v-icon>
                        </v-btn>
                        <v-btn icon size="small" @click="editor.chain().focus().redo().run()"
                            :disabled="!editor.can().redo()">
                            <v-icon>mdi-redo</v-icon>
                        </v-btn>
                    </v-btn-group>

                    <v-btn-group variant="text" density="compact" class="mr-2 border-r pr-2">
                        <v-btn icon size="small" @click="editor.chain().focus().toggleBold().run()"
                            :color="editor.isActive('bold') ? 'primary' : ''">
                            <v-icon>mdi-format-bold</v-icon>
                        </v-btn>
                        <v-btn icon size="small" @click="editor.chain().focus().toggleItalic().run()"
                            :color="editor.isActive('italic') ? 'primary' : ''">
                            <v-icon>mdi-format-italic</v-icon>
                        </v-btn>
                        <v-btn icon size="small" @click="editor.chain().focus().toggleUnderline().run()"
                            :color="editor.isActive('underline') ? 'primary' : ''">
                            <v-icon>mdi-format-underline</v-icon>
                        </v-btn>
                        <v-btn icon size="small" @click="editor.chain().focus().toggleStrike().run()"
                            :color="editor.isActive('strike') ? 'primary' : ''">
                            <v-icon>mdi-format-strikethrough</v-icon>
                        </v-btn>
                    </v-btn-group>

                    <v-btn-group variant="text" density="compact" class="mr-2 border-r pr-2">
                        <v-btn icon size="small" @click="editor.chain().focus().setTextAlign('left').run()"
                            :color="editor.isActive({ textAlign: 'left' }) ? 'primary' : ''">
                            <v-icon>mdi-format-align-left</v-icon>
                        </v-btn>
                        <v-btn icon size="small" @click="editor.chain().focus().setTextAlign('center').run()"
                            :color="editor.isActive({ textAlign: 'center' }) ? 'primary' : ''">
                            <v-icon>mdi-format-align-center</v-icon>
                        </v-btn>
                        <v-btn icon size="small" @click="editor.chain().focus().setTextAlign('right').run()"
                            :color="editor.isActive({ textAlign: 'right' }) ? 'primary' : ''">
                            <v-icon>mdi-format-align-right</v-icon>
                        </v-btn>
                        <v-btn icon size="small" @click="editor.chain().focus().setTextAlign('justify').run()"
                            :color="editor.isActive({ textAlign: 'justify' }) ? 'primary' : ''">
                            <v-icon>mdi-format-align-justify</v-icon>
                        </v-btn>
                    </v-btn-group>

                    <v-btn-group variant="text" density="compact" class="mr-2 border-r pr-2">
                        <v-btn icon size="small" @click="editor.chain().focus().toggleBulletList().run()"
                            :color="editor.isActive('bulletList') ? 'primary' : ''">
                            <v-icon>mdi-format-list-bulleted</v-icon>
                        </v-btn>
                        <v-btn icon size="small" @click="editor.chain().focus().toggleOrderedList().run()"
                            :color="editor.isActive('orderedList') ? 'primary' : ''">
                            <v-icon>mdi-format-list-numbered</v-icon>
                        </v-btn>
                        <v-btn icon size="small" @click="editor.chain().focus().toggleTaskList().run()"
                            :color="editor.isActive('taskList') ? 'primary' : ''">
                            <v-icon>mdi-checkbox-marked-outline</v-icon>
                        </v-btn>
                    </v-btn-group>

                    <v-btn-group variant="text" density="compact">
                        <!-- Indent Simulation -->
                        <!-- Note: Proper Indent uses Margin logic, Tiptap requires specific command or structure. 
                     For now, we can use Blockquote as a poor-man's indent, or just skip if no extension.
                     Using sink/lift for lists works. For normal paragraphs, standard indent isn't in core.
                -->
                        <v-btn icon size="small" @click="editor.chain().focus().sinkListItem('listItem').run()"
                            :disabled="!editor.can().sinkListItem('listItem')">
                            <v-icon>mdi-format-indent-increase</v-icon>
                        </v-btn>
                        <v-btn icon size="small" @click="editor.chain().focus().liftListItem('listItem').run()"
                            :disabled="!editor.can().liftListItem('listItem')">
                            <v-icon>mdi-format-indent-decrease</v-icon>
                        </v-btn>
                    </v-btn-group>
                </v-toolbar>

                <!-- Editor Area -->
                <div class="editor-wrapper flex-grow-1 overflow-y-auto pa-4 bg-grey-lighten-4 d-flex justify-center">
                    <div class="paper-page elevation-2 bg-white pa-10">
                        <editor-content :editor="editor" />
                    </div>
                </div>
            </div>
        </v-main>
    </v-app>
</template>

<style>
.editor-wrapper {
    background-color: #f3f4f6;
}

.paper-page {
    width: 210mm;
    /* A4 width */
    min-height: 297mm;
    /* A4 height */
    padding: 2.54cm;
    /* Standard margins */
    margin-bottom: 2rem;
    box-sizing: border-box;
}

/* Tiptap Editor Styles to mimic Word */
.ProseMirror {
    outline: none;
    font-family: 'Sarabun New', 'Sarabun', 'TH Sarabun New', sans-serif;
    font-size: 16pt;
    line-height: 1.5;
}

.ProseMirror p {
    margin-bottom: 1em;
}

.ProseMirror ul,
.ProseMirror ol {
    padding-left: 2em;
    margin-bottom: 1em;
}

.ProseMirror h1 {
    font-size: 2em;
    font-weight: bold;
    margin-bottom: 0.5em;
}

.ProseMirror h2 {
    font-size: 1.5em;
    font-weight: bold;
    margin-bottom: 0.5em;
}

/* Table styles */
.ProseMirror table {
    border-collapse: collapse;
    width: 100%;
    margin-bottom: 1em;
}

.ProseMirror td,
.ProseMirror th {
    border: 1px solid #000;
    padding: 8px;
    min-width: 1em;
}

/* Task List styles */
ul[data-type="taskList"] {
    list-style: none;
    padding: 0;
}

ul[data-type="taskList"] li {
    display: flex;
}

ul[data-type="taskList"] li>label {
    flex: 0 0 auto;
    margin-right: 0.5rem;
    user-select: none;
}

ul[data-type="taskList"] li>div {
    flex: 1 1 auto;
}
</style>
