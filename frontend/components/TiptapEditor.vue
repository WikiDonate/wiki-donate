<template>
    <div v-if="editor" class="tiptap-editor">
        <div class="editor-toolbar">
            <!-- Basic formatting -->
            <button @click="setBold">Bold</button>
            <button @click="setItalic">Italic</button>
            <button @click="setUnderline">Underline</button>
            <button @click="setHighlight">Highlight</button>
            <button @click="setBlockquote">Blockquote</button>

            <!-- Link and image -->
            <button @click="setLink">Link</button>
            <button @click="setImage">Image</button>

            <!-- Lists -->
            <button @click="setBulletList">Bullet List</button>
            <button @click="setOrderedList">Ordered List</button>
            <button @click="setTaskList">Task List</button>

            <!-- Table -->
            <button @click="addTable">Insert Table</button>

            <!-- Text color -->
            <input type="color" @input="setColor($event.target.value)" >

            <!-- Undo/Redo -->
            <button @click="undo">Undo</button>
            <button @click="redo">Redo</button>
        </div>
        <EditorContent :editor="editor" />
    </div>
</template>

<script setup>
import Blockquote from '@tiptap/extension-blockquote'
import BulletList from '@tiptap/extension-bullet-list'
import Color from '@tiptap/extension-color'
import Highlight from '@tiptap/extension-highlight'
import Image from '@tiptap/extension-image'
import Link from '@tiptap/extension-link'
import OrderedList from '@tiptap/extension-ordered-list'
import Table from '@tiptap/extension-table'
import TableCell from '@tiptap/extension-table-cell'
import TableHeader from '@tiptap/extension-table-header'
import TableRow from '@tiptap/extension-table-row'
import Underline from '@tiptap/extension-underline'
import StarterKit from '@tiptap/starter-kit'
import { Editor, EditorContent } from '@tiptap/vue-3'
import { onBeforeUnmount, onMounted, ref } from 'vue'

const editor = ref(null)

onMounted(() => {
    editor.value = new Editor({
        content: '<p>Hello World!</p>',
        extensions: [
            StarterKit,
            Image,
            Link,
            BulletList,
            OrderedList,
            Underline,
            Highlight,
            Blockquote,
            Table.configure({
                resizable: true, // Enables table resizing
            }),
            TableRow,
            TableCell,
            TableHeader,
            Color,
        ],
    })
})

// Toolbar actions
const setBold = () => {
    editor.value.chain().focus().toggleBold().run()
}

const setItalic = () => {
    editor.value.chain().focus().toggleItalic().run()
}

const setUnderline = () => {
    editor.value.chain().focus().toggleUnderline().run()
}

const setHighlight = () => {
    editor.value.chain().focus().toggleHighlight().run()
}

const setBlockquote = () => {
    editor.value.chain().focus().toggleBlockquote().run()
}

const setLink = () => {
    const url = prompt('Enter the link')
    if (url) {
        editor.value.chain().focus().setLink({ href: url }).run()
    }
}

const setImage = () => {
    const url = prompt('Enter image URL')
    if (url) {
        editor.value.chain().focus().setImage({ src: url }).run()
    }
}

const setBulletList = () => {
    editor.value.chain().focus().toggleBulletList().run()
}

const setOrderedList = () => {
    editor.value.chain().focus().toggleOrderedList().run()
}

const addTable = () => {
    editor.value.chain().focus().insertTable({ rows: 3, cols: 3 }).run()
}

const setColor = (color) => {
    editor.value.chain().focus().setColor(color).run()
}

const undo = () => {
    editor.value.chain().focus().undo().run()
}

const redo = () => {
    editor.value.chain().focus().redo().run()
}

onBeforeUnmount(() => {
    editor.value?.destroy()
})
</script>

<style scoped>
.tiptap-editor {
    border: 1px solid #d7d9db;
    border-radius: 0.25rem;
    padding: 0.5rem;
}

.editor-toolbar {
    display: flex;
    gap: 8px;
    margin-bottom: 10px;
}

.editor-toolbar button {
    background-color: #e6edf4;
    /* color: white; */
    border: none;
    padding: 2px 6px;
    cursor: pointer;
    border-radius: 4px;
    font-size: 12px;
    color: black;
}

.editor-toolbar input[type='color'] {
    border: none;
    padding: 2px;
    cursor: pointer;
}
</style>
