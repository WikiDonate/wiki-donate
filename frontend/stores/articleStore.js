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
        articles: [],
        articlesMeta: {},
        history: [],
    }),
    actions: {
        addArticle(entry) {
            this.article = entry
        },
        clearArticle() {
            this.article = {}
        },
        addArticles({ articles, articlesMeta }) {
            this.articles = articles
            this.articlesMeta = articlesMeta
        },
        clearArticles() {
            this.articles = []
            this.articlesMeta = {}
        },
        addHistory(entry) {
            this.history = entry
        },
        clearHistory() {
            this.history = []
        },
    },
})
