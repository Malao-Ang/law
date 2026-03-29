<template>
    <v-app>
        <v-main class="bg-grey-lighten-4">
            <v-container class="fill-height d-flex justify-center align-center">
                <v-card width="600" class="elevation-4 text-center pa-8 rounded-xl">
                    <div class="mb-8">
                        <v-icon size="80" color="primary" class="mb-4">mdi-file-document-edit</v-icon>
                        <h1 class="text-h4 font-weight-bold mb-2">Legal Document Editor</h1>
                        <p class="text-subtitle-1 text-grey-darken-1">
                            Professional Thai Official Document Formatting
                        </p>
                    </div>

                    <v-list class="bg-transparent text-left mb-6">
                        <v-list-item prepend-icon="mdi-check-circle"
                            title="Preserve Thai Formatting (Tabs, Indents)"></v-list-item>
                        <v-list-item prepend-icon="mdi-check-circle"
                            title="Support Complex Tables & Borders"></v-list-item>
                        <v-list-item prepend-icon="mdi-check-circle"
                            title="Automatic Font Adjustment (Sarabun)"></v-list-item>
                    </v-list>

                    <v-btn color="primary" size="x-large" block elevation="2"
                        class="text-none font-weight-bold text-h6 py-6 mb-3" to="/editor">
                        <v-icon left size="32" class="mr-2">mdi-plus-circle</v-icon>
                        Create New Document
                    </v-btn>

                    <v-btn color="secondary" size="large" block elevation="2"
                        class="text-none font-weight-bold py-4" to="/regulations">
                        <v-icon left size="24" class="mr-2">mdi-file-document-multiple</v-icon>
                        View All Regulations
                    </v-btn>

                    <div class="mt-4 text-caption text-grey">
                        Supports .docx and .pdf files
                    </div>
                </v-card>
            </v-container>
        </v-main>
    </v-app>
</template>

<script setup>
</script>
