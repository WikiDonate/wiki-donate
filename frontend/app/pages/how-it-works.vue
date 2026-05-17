<!-- eslint-disable vue/html-self-closing -->
<template>
    <main class="w-full mt-6">
        <div class="flex flex-col lg:flex-row gap-6">
            <!-- Left Sidebar -->
            <div
                class="lg:w-64 flex-shrink-0 bg-white rounded-2xl shadow-md overflow-hidden lg:sticky lg:top-6 lg:h-fit"
            >
                <div
                    class="bg-gradient-to-r from-indigo-600 to-purple-600 py-3 px-4 text-center"
                >
                    <span class="font-bold text-white tracking-wide"
                        >Steps</span
                    >
                </div>
                <nav class="py-2">
                    <button
                        v-for="(step, index) in steps"
                        :key="index"
                        class="w-full flex items-center gap-3 px-4 py-3 text-left hover:bg-indigo-50 transition"
                        :class="
                            activeSection === `step-${index}`
                                ? 'bg-indigo-50 text-indigo-600 border-r-2 border-indigo-600'
                                : 'text-gray-600'
                        "
                        @click="scrollToSection(`step-${index}`)"
                    >
                        <span
                            class="flex-shrink-0 w-8 h-8 rounded-full bg-gradient-to-br from-indigo-500 to-purple-600 flex items-center justify-center text-white text-sm font-bold"
                        >
                            {{ index + 1 }}
                        </span>
                        <span class="text-sm font-medium">
                            {{ step.title }}
                        </span>
                    </button>
                </nav>
            </div>

            <!-- Content -->
            <div class="flex-1 bg-white rounded-2xl shadow-md overflow-hidden">
                <!-- Hero Section -->
                <div
                    class="bg-gradient-to-r from-indigo-600 to-purple-600 py-12 px-6 text-center"
                >
                    <h1
                        class="text-xl sm:text-3xl md:text-4xl font-extrabold text-white mb-4"
                    >
                        How WikiDonate Works
                    </h1>
                    <p
                        class="text-lg sm:text-xl text-indigo-100 max-w-2xl mx-auto"
                    >
                        A collaborative donation platform. Get started in
                        minutes and start receiving support for your work.
                    </p>
                </div>

                <!-- Steps Section -->
                <div class="p-6 sm:p-10">
                    <!-- Step Details -->
                    <div class="space-y-12">
                        <div
                            v-for="(step, index) in steps"
                            :id="`step-${index}`"
                            :key="index"
                            class="flex flex-col md:flex-row items-center gap-6 md:gap-10 scroll-mt-6"
                        >
                            <!-- Icon -->
                            <div
                                class="flex-shrink-0 w-24 h-24 rounded-full bg-indigo-50 flex items-center justify-center"
                            >
                                <font-awesome-icon
                                    :icon="step.icon"
                                    class="w-10 h-10 text-indigo-600"
                                />
                            </div>

                            <!-- Content -->
                            <div class="flex-1 text-center md:text-left">
                                <h2
                                    class="text-xl sm:text-xl font-bold text-gray-900 mb-2"
                                >
                                    {{ step.title }}
                                </h2>
                                <p class="text-gray-600 text-base sm:text-lg">
                                    <template
                                        v-for="(
                                            seg, segIdx
                                        ) in step.description"
                                        :key="segIdx"
                                    >
                                        <span v-if="seg.type === 'text'">{{
                                            seg.value
                                        }}</span>
                                        <button
                                            v-else
                                            class="text-indigo-600 font-medium hover:underline focus:outline-none"
                                            @click="
                                                router.push(
                                                    '/article?title=' +
                                                        encodeURIComponent(
                                                            seg.value
                                                        )
                                                )
                                            "
                                        >
                                            {{ seg.value }}
                                        </button>
                                    </template>
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>
</template>

<script setup>
useHead({
    title: 'How to Use WikiDonate',
})

const router = useRouter()
const activeSection = ref('step-0')

const scrollToSection = (sectionId) => {
    const element = document.getElementById(sectionId)
    if (element) {
        activeSection.value = sectionId
        element.scrollIntoView({ behavior: 'smooth', block: 'start' })
    }
}

function parseDescription(text) {
    const segments = []
    const regex = /\[\[([^\]]+)\]\]/g
    let lastIndex = 0
    let match

    while ((match = regex.exec(text)) !== null) {
        if (match.index > lastIndex) {
            segments.push({
                type: 'text',
                value: text.slice(lastIndex, match.index),
            })
        }
        segments.push({ type: 'link', value: match[1] })
        lastIndex = regex.lastIndex
    }

    if (lastIndex < text.length) {
        segments.push({ type: 'text', value: text.slice(lastIndex) })
    }

    return segments
}

const steps = computed(() => [
    {
        title: 'Search for a cause or idea',
        icon: ['fas', 'search'],
        description: parseDescription(
            'On the search bar, you can search for a cause that you want to donate to like [[diabetes]]'
        ),
    },
    {
        title: 'Choose a donation formula',
        icon: ['fas', 'calculator'],
        description: parseDescription(
            "A donation formula defines what collection of recipients and in what proportions would be best to distribute the donation in order to help your selected cause or idea. The page may have a list of donation formulas from different editors, such as international diabetes associations. Click 'Donate' on the donation formula that you like best."
        ),
    },
    {
        title: 'Donate!',
        icon: ['fas', 'donate'],
        description: parseDescription(
            'Currently, only PayPal and Stripe is supported. On the payment page, confirm your donation.'
        ),
    },
    {
        title: 'Go further! Start your own cause',
        icon: ['fas', 'lightbulb'],
        description: parseDescription(
            "You can start a page for a cause or an idea, such as a new strategy to reduce deaths from air pollution. First search for it on the search bar. If it is not available, then click 'Save' to create the new page."
        ),
    },
    {
        title: 'Go further! Write your own donation formula',
        icon: ['fas', 'pen'],
        description: parseDescription(
            "Click the button 'Create donation formula' at the bottom of the page of the cause or idea that you are interested in. Fill in your specifications with the correct spelling. Any donation to a recipient who is unavailable will be refunded back to the donor. Click 'Save'. Now other donors could use your formula too! "
        ),
    },
])
</script>
