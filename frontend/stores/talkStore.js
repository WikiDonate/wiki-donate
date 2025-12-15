import { defineStore } from 'pinia'

export const useTalkStore = defineStore('talk', {
    persist: {
        enabled: true,
        strategies: [
            {
                key: 'talkHistory',
                storage: localStorage,
            },
        ],
    },
    state: () => ({
        talk: {},
        history: [],
    }),
    actions: {
        addTalk(entry) {
            this.talk = entry
        },
        clearTalk() {
            this.talk = {}
        },
        addHistory(entry) {
            this.history = entry
        },
        clearHistory() {
            this.history = []
        },
    },
})
