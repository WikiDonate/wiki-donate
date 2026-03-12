import { defineStore } from 'pinia'
import { ref } from 'vue'

export const useTalkStore = defineStore(
    'talk',
    () => {
        const talk = ref({})
        const history = ref([])

        function addTalk(entry) {
            talk.value = entry
        }

        function clearTalk() {
            talk.value = {}
        }

        function addHistory(entry) {
            history.value = entry
        }

        function clearHistory() {
            history.value = []
        }

        return {
            talk,
            history,
            addTalk,
            clearTalk,
            addHistory,
            clearHistory,
        }
    },
    {
        persist: { key: 'talkHistory' },
    }
)
