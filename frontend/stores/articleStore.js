import { defineStore } from 'pinia'

export const useArticleStore = defineStore('article', {
    persist: {
        enabled: true,
        strategies: [
            {
                key: 'articleHistory',
                storage: localStorage,
            },
        ],
    },
    state: () => ({
        article: {},
        history: [],
    }),
    actions: {
        addArticle(entry) {
            this.article = entry
        },
        clearArticle() {
            this.article = {}
        },
        addHistory(entry) {
            this.history = entry
        },
        clearHistory() {
            this.history = []
        },
    },
})
