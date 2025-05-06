<template>
    <div translate="no">
        <ClientOnly>
            <QuillEditor
                ref="quillEditor"
                v-model:content="content"
                :options="editorOptions"
                content-type="html"
                placeholder="Create an article..."
                @editor-change="handleInput"
            />
        </ClientOnly>
    </div>
</template>

<script setup>
import { QuillEditor } from '@vueup/vue-quill'
import '@vueup/vue-quill/dist/vue-quill.snow.css'
import interact from 'interactjs'
import { defineEmits, onMounted, ref } from 'vue'

const props = defineProps({
    initialContent: {
        type: String,
        default: '',
    },
})

// Emits the updated content to the parent
const emit = defineEmits(['update:content'])

// Toolbar options
const toolbarOptions = [
    ['bold', 'italic', 'underline', 'strike'],
    ['blockquote', 'code-block'],
    ['link', 'image', 'video', 'formula'],
    [{ header: 1 }, { header: 2 }],
    [{ list: 'ordered' }, { list: 'bullet' }, { list: 'check' }],
    [{ script: 'sub' }, { script: 'super' }],
    [{ indent: '-1' }, { indent: '+1' }],
    [{ direction: 'rtl' }],
    [{ size: ['small', false, 'large', 'huge'] }],
    [{ header: [1, 2, 3, 4, 5, 6, false] }],
    [{ color: [] }, { background: [] }],
    [{ font: [] }],
    [{ align: [] }],
    ['clean'],
]

// Editor options
const editorOptions = ref({
    modules: {
        toolbar: {
            container: toolbarOptions,
            handlers: {
                image: imageHandler,
            },
        },
    },
    placeholder: 'Compose an epic...',
    readOnly: false,
    theme: 'snow',
})

// Define content with default text
const content = ref('') // Editor content

const quillEditor = ref(null)

function imageHandler() {
    const input = document.createElement('input')
    input.setAttribute('type', 'file')
    input.setAttribute('accept', 'image/*')
    input.click()

    input.onchange = async () => {
        const file = input.files[0]
        if (file) {
            const reader = new FileReader()
            reader.onload = (e) => {
                const quill = quillEditor.value?.getQuill()
                if (quill) {
                    const range = quill.getSelection()
                    if (range) {
                        quill.insertEmbed(range.index, 'image', e.target.result)
                        quill.setSelection(range.index + 1)
                        enableImageManipulation()
                    }
                }
            }
            reader.readAsDataURL(file)
        }
    }
}

// Function to enable image dragging and resizing
function enableImageManipulation() {
    interact('.ql-editor img')
        .resizable({
            edges: { left: true, right: true, bottom: true, top: true },
            preserveAspectRatio: true,
            inertia: true,
        })
        .draggable({
            onmove: dragMoveListener,
        })
        .on('resizemove', (event) => {
            const target = event.target
            const width = event.rect.width
            const height = event.rect.height

            target.style.width = width + 'px'
            target.style.height = height + 'px'
            target.style.transform = `translate(${event.deltaRect.left}px, ${event.deltaRect.top}px)`
        })
}

// Function to move the image
function dragMoveListener(event) {
    const target = event.target
    const x = (parseFloat(target.getAttribute('data-x')) || 0) + event.dx
    const y = (parseFloat(target.getAttribute('data-y')) || 0) + event.dy

    // Translate the image position
    target.style.transform = `translate(${x}px, ${y}px)`

    // Store the new position
    target.setAttribute('data-x', x)
    target.setAttribute('data-y', y)
}

// Alignment handler
function alignmentHandler(alignment) {
    const quill = quillEditor.value?.getQuill()
    if (quill) {
        const range = quill.getSelection()
        if (range) {
            quill.formatText(range.index, 1, { align: alignment })
        }
    }
}

// Setup custom alignment buttons
function setupAlignmentButtons() {
    const toolbar = document.querySelector('.ql-toolbar')
    if (toolbar) {
        const createButton = (label, alignment) => {
            const button = document.createElement('button')
            button.innerText = label
            button.onclick = () => alignmentHandler(alignment)
            return button
        }
        toolbar.appendChild(createButton('Align Left', 'left'))
        toolbar.appendChild(createButton('Align Center', 'center'))
        toolbar.appendChild(createButton('Align Right', 'right'))
    }
}

function convertToH2(htmlString) {
    return htmlString.replace(/<p>==(.+?)==<\/p>/g, '<h2><u>$1</u></h2>')
}

// Emit updated content on input
function handleInput() {
    const quill = quillEditor.value?.getQuill()
    if (quill) {
        const currentContent = quill.root.innerHTML // Get the editor content as HTML
        emit('update:content', convertToH2(currentContent))
    }
}

function setupTitleListener() {
    const quill = quillEditor.value?.getQuill()

    if (!quill) {
        console.error('Quill instance not found')
        return
    }

    quill.on('text-change', () => {
        const text = quill.getText() // Get plain text of the editor
        const match = text.match(/==(.+?)==/) // Match pattern `===AnyText===`

        if (match) {
            const startIndex = text.indexOf(match[0])
            const sectionText = match[1]

            // Remove the markers (i.e., '==' part)
            quill.deleteText(startIndex, match[0].length)

            // Insert the section text
            quill.insertText(startIndex, sectionText)

            // Apply Header 2 formatting to the line containing the section text
            quill.formatLine(startIndex, 1, { header: 2 })

            // Apply underline to the section text
            quill.formatText(startIndex, sectionText.length, {
                underline: true,
            })

            // Set the cursor to the end of the inserted text
            quill.setSelection(startIndex + sectionText.length)
        }
    })
}

// Wait for QuillEditor to render
onMounted(async () => {
    await nextTick()
    content.value = props.initialContent
    // Check if editor is ready
    if (quillEditor.value) {
        setupTitleListener()
    } else {
        console.error('QuillEditor is not ready')
    }
})

// Initialize functionalities after mounting
onMounted(() => {
    enableImageManipulation()
    setupAlignmentButtons()
})
</script>

<style>
/* Quill Editor Base Styles */
.ql-container {
    @apply border border-gray-300 rounded-lg shadow-sm bg-white;
    min-height: 200px;
}

.ql-editor {
    @apply min-h-[150px] text-gray-800 leading-relaxed;
}

/* Toolbar Buttons */
.ql-toolbar {
    @apply bg-gray-100 border-b border-gray-300 p-2 rounded-t-lg;
}

/* Active Toolbar Buttons */
.ql-toolbar .ql-active {
    @apply bg-gray-200;
}

/* Styling for Headings */
.ql-editor h1 {
    @apply text-3xl font-bold my-2;
}

.ql-editor h2 {
    @apply text-2xl font-semibold my-2;
}

.ql-editor h3 {
    @apply text-xl font-semibold my-2;
}

/* Lists */
.ql-editor ul {
    @apply list-disc list-inside pl-4;
}

.ql-editor ol {
    @apply list-decimal list-inside pl-4;
}

/* Blockquotes */
.ql-editor blockquote {
    @apply border-l-4 border-gray-400 pl-4 italic text-gray-600;
}

/* Code Block */
.ql-editor pre {
    @apply bg-gray-900 text-white p-3 rounded-md overflow-x-auto;
}

/* Inline Code */
.ql-editor code {
    @apply bg-gray-200 text-sm p-1 rounded;
}

/* Links */
.ql-editor a {
    @apply text-blue-600 underline hover:text-blue-800;
}

/* Images */
.ql-editor img {
    @apply max-w-full rounded-lg shadow;
}

/* Video */
.ql-editor iframe {
    @apply w-full h-64 rounded-lg;
}
</style>
