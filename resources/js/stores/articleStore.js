import { defineStore } from 'pinia'
import { ref } from 'vue'

export const useArticleStore = defineStore(
    'article',
    () => {
        const article = ref({})
        const articles = ref([])
        const articlesMeta = ref({})
        const history = ref([])

        function addArticle(entry) {
            article.value = entry
        }

        function clearArticle() {
            article.value = {}
        }

        function addArticles({ articles: newArticles, articlesMeta: newArticlesMeta }) {
            articles.value = newArticles
            articlesMeta.value = newArticlesMeta
        }

        function clearArticles() {
            articles.value = []
            articlesMeta.value = {}
        }

        function addHistory(entry) {
            history.value = entry
        }

        function clearHistory() {
            history.value = []
        }

        return {
            article,
            articles,
            articlesMeta,
            history,
            addArticle,
            clearArticle,
            addArticles,
            clearArticles,
            addHistory,
            clearHistory,
        }
    },
    {
        persist: { key: 'articleHistory' },
    }
)
